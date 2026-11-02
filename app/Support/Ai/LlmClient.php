<?php

namespace App\Support\Ai;

use App\Models\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente único para conversar com qualquer provedor de IA cadastrado.
 * Recebe o histórico no formato [['role' => 'user'|'assistant', 'content' => '...'], ...].
 */
class LlmClient
{
    /** Versão da API Messages da Anthropic. */
    public const ANTHROPIC_VERSION = '2023-06-01';

    public const AZURE_DEFAULT_VERSION = '2024-10-21';

    public function chat(AiProvider $provider, string $system, array $messages, ?int $maxTokens = null): AiReply
    {
        $maxTokens = $maxTokens ?: ($provider->max_tokens ?: 4096);

        try {
            return match ($provider->protocol) {
                'anthropic' => $this->anthropic($provider, $system, $messages, $maxTokens),
                'gemini' => $this->gemini($provider, $system, $messages, $maxTokens),
                'ollama' => $this->ollama($provider, $system, $messages, $maxTokens),
                default => $this->openai($provider, $system, $messages, $maxTokens),
            };
        } catch (ConnectionException $e) {
            throw new AiException('Não foi possível conectar ao provedor ('.$provider->endpoint().'). Verifique a URL, a internet ou se o serviço local está rodando.', 0, $e);
        }
    }

    private function http(AiProvider $provider): PendingRequest
    {
        return Http::timeout((int) config('services.ai.timeout', 180))
            ->connectTimeout(15)
            ->acceptJson()
            ->asJson();
    }

    private function anthropic(AiProvider $p, string $system, array $messages, int $maxTokens): AiReply
    {
        $base = $p->endpoint();
        $url = str_ends_with($base, '/v1') ? $base.'/messages' : $base.'/v1/messages';

        // Nos modelos atuais do Claude o raciocínio é adaptativo e parâmetros de amostragem
        // (temperature) não são aceitos, por isso não são enviados.
        $body = array_filter([
            'model' => $p->model,
            'max_tokens' => $maxTokens,
            'system' => $system !== '' ? $system : null,
            'messages' => $messages,
        ], fn ($v) => $v !== null);

        $response = $this->http($p)
            ->withHeaders(['x-api-key' => (string) $p->api_key, 'anthropic-version' => self::ANTHROPIC_VERSION])
            ->post($url, $body);
        $this->guard($p, $response);

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n\n");

        $stop = $response->json('stop_reason');
        if ($stop === 'refusal' && trim($text) === '') {
            $text = '_O modelo recusou esta solicitação por política de segurança do provedor. Reformule o pedido ou tente outro modelo._';
        }

        return new AiReply(
            $this->truncatedNote($text, $stop === 'max_tokens'),
            (string) ($response->json('model') ?: $p->model),
            $response->json('usage.input_tokens'),
            $response->json('usage.output_tokens'),
        );
    }

    private function openai(AiProvider $p, string $system, array $messages, int $maxTokens): AiReply
    {
        $chat = $system !== '' ? array_merge([['role' => 'system', 'content' => $system]], $messages) : $messages;
        $body = ['model' => $p->model, 'messages' => $chat];

        // A OpenAI (e o Azure) usam max_completion_tokens nos modelos novos; os demais compatíveis usam max_tokens.
        if (in_array($p->protocol, ['azure'], true) || $p->driver === 'openai') {
            $body['max_completion_tokens'] = $maxTokens;
        } else {
            $body['max_tokens'] = $maxTokens;
        }
        if ($p->temperature !== null) {
            $body['temperature'] = $p->temperature;
        }

        $request = $this->http($p);
        $url = $p->endpoint().'/chat/completions';

        if ($p->protocol === 'azure') {
            $request = $request->withHeaders(['api-key' => (string) $p->api_key]);
            $url .= '?api-version='.urlencode((string) $p->option('api_version', self::AZURE_DEFAULT_VERSION));
            unset($body['model']); // no Azure o modelo é definido pelo deployment da URL
        } elseif ($p->api_key) {
            $request = $request->withToken((string) $p->api_key);
        }
        if ($org = $p->option('organization')) {
            $request = $request->withHeaders(['OpenAI-Organization' => $org]);
        }
        if ($p->driver === 'openrouter') {
            $request = $request->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => config('app.name')]);
        }

        $response = $request->post($url, $body);
        $this->guard($p, $response);

        $content = $response->json('choices.0.message.content');
        if (is_array($content)) {
            $content = collect($content)->pluck('text')->filter()->implode("\n\n");
        }

        return new AiReply(
            $this->truncatedNote((string) $content, $response->json('choices.0.finish_reason') === 'length'),
            (string) ($response->json('model') ?: $p->model),
            $response->json('usage.prompt_tokens'),
            $response->json('usage.completion_tokens'),
        );
    }

    private function gemini(AiProvider $p, string $system, array $messages, int $maxTokens): AiReply
    {
        $contents = array_map(fn ($m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $body = ['contents' => $contents, 'generationConfig' => ['maxOutputTokens' => $maxTokens]];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($p->temperature !== null) {
            $body['generationConfig']['temperature'] = $p->temperature;
        }

        $model = str_starts_with($p->model, 'models/') ? $p->model : 'models/'.$p->model;
        $response = $this->http($p)
            ->withHeaders(['x-goog-api-key' => (string) $p->api_key])
            ->post($p->endpoint().'/'.$model.':generateContent', $body);
        $this->guard($p, $response);

        if ($block = $response->json('promptFeedback.blockReason')) {
            throw new AiException("O Gemini bloqueou a solicitação ({$block}). Reformule o pedido.");
        }

        $text = collect($response->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode('');

        return new AiReply(
            $this->truncatedNote($text, $response->json('candidates.0.finishReason') === 'MAX_TOKENS'),
            (string) ($response->json('modelVersion') ?: $p->model),
            $response->json('usageMetadata.promptTokenCount'),
            $response->json('usageMetadata.candidatesTokenCount'),
        );
    }

    private function ollama(AiProvider $p, string $system, array $messages, int $maxTokens): AiReply
    {
        $chat = $system !== '' ? array_merge([['role' => 'system', 'content' => $system]], $messages) : $messages;
        $options = ['num_predict' => $maxTokens];
        if ($p->temperature !== null) {
            $options['temperature'] = $p->temperature;
        }

        $request = $this->http($p);
        if ($p->api_key) {
            $request = $request->withToken((string) $p->api_key);
        }
        $response = $request->post($p->endpoint().'/api/chat', [
            'model' => $p->model,
            'messages' => $chat,
            'stream' => false,
            'options' => $options,
        ]);
        $this->guard($p, $response);

        return new AiReply(
            $this->truncatedNote((string) $response->json('message.content'), $response->json('done_reason') === 'length'),
            (string) ($response->json('model') ?: $p->model),
            $response->json('prompt_eval_count'),
            $response->json('eval_count'),
        );
    }

    /** Converte erros HTTP em mensagens claras em português. */
    private function guard(AiProvider $p, Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = $response->json('error.message')
            ?? $response->json('error')
            ?? $response->json('message')
            ?? Str::limit(strip_tags($response->body()), 200);
        $detail = is_string($detail) ? Str::limit($detail, 300) : '';

        $message = match (true) {
            in_array($response->status(), [401, 403], true) => 'A chave de API foi recusada pelo provedor. Confira a chave e as permissões da conta.',
            $response->status() === 404 => 'Modelo ou endereço não encontrado. Confira o nome do modelo ("'.$p->model.'") e a URL.',
            $response->status() === 429 => 'Limite de uso ou créditos do provedor esgotados. Aguarde alguns instantes ou verifique o plano contratado.',
            in_array($response->status(), [400, 422], true) => 'O provedor recusou a solicitação.',
            $response->status() >= 500 => 'O provedor está instável ou sobrecarregado no momento. Tente novamente em instantes.',
            default => 'Erro inesperado do provedor.',
        };

        throw new AiException(trim($message.' (HTTP '.$response->status().')'.($detail !== '' ? ' — '.$detail : '')));
    }

    private function truncatedNote(string $text, bool $truncated): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new AiException('O provedor respondeu sem texto. Tente novamente ou escolha outro modelo.');
        }

        return $truncated
            ? $text."\n\n_(Resposta interrompida pelo limite de tamanho configurado. Peça para continuar ou aumente o limite de tokens.)_"
            : $text;
    }
}
