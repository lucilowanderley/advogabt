<?php

namespace App\Support\Ai;

use App\Models\AiProvider;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
        $send = fn () => match ($provider->protocol) {
            'anthropic' => $this->anthropic($provider, $system, $messages, $maxTokens),
            'gemini' => $this->gemini($provider, $system, $messages, $maxTokens),
            'ollama' => $this->ollama($provider, $system, $messages, $maxTokens),
            default => $this->openai($provider, $system, $messages, $maxTokens),
        };

        return $this->run($provider, $send);
    }

    /**
     * Modelos disponíveis para a chave informada (consulta a API do provedor).
     *
     * @return list<string>
     */
    public function models(AiProvider $provider): array
    {
        return $this->run($provider, function () use ($provider) {
            $base = $provider->endpoint();
            $request = $this->http($provider);

            if ($provider->protocol === 'azure') {
                throw new AiException('No Azure OpenAI o "modelo" é o nome do deployment criado no portal do Azure; não há lista para carregar.');
            }

            if ($provider->protocol === 'anthropic') {
                $url = (str_ends_with($base, '/v1') ? $base : $base.'/v1').'/models?limit=100';
                $response = $request->withHeaders(['x-api-key' => (string) $provider->api_key, 'anthropic-version' => self::ANTHROPIC_VERSION])->get($url);
                $this->guard($provider, $response);
                $ids = collect($response->json('data', []))->pluck('id');
            } elseif ($provider->protocol === 'gemini') {
                $response = $request->withHeaders(['x-goog-api-key' => (string) $provider->api_key])->get($base.'/models', ['pageSize' => 200]);
                $this->guard($provider, $response);
                $ids = collect($response->json('models', []))
                    ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
                    ->map(fn ($m) => preg_replace('#^models/#', '', $m['name'] ?? ''));
            } elseif ($provider->protocol === 'ollama') {
                $response = $request->get($base.'/api/tags');
                $this->guard($provider, $response);
                $ids = collect($response->json('models', []))->pluck('name');
            } else {
                if ($provider->api_key) {
                    $request = $request->withToken((string) $provider->api_key);
                }
                $response = $request->get($base.'/models');
                $this->guard($provider, $response);
                $ids = collect($response->json('data', []))->filter(fn ($m) => ($m['active'] ?? true) !== false)->pluck('id');
            }

            // Remove modelos que não servem para conversa (áudio, imagem, embeddings, moderação).
            return $ids->filter(fn ($id) => is_string($id) && $id !== ''
                && ! preg_match('/whisper|tts|embed|moderation|dall-e|guard|transcri|audio|image|realtime|search-preview/i', $id))
                ->unique()->sort()->values()->all();
        });
    }

    /** Executa a chamada tratando falhas de conexão (inclusive HTTPS interceptado no Windows). */
    private function run(AiProvider $provider, callable $send): mixed
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            // Windows com antivírus/proxy que inspeciona HTTPS: importa as autoridades do
            // Windows uma vez e tenta de novo, sem desligar a verificação SSL.
            if ($this->canImportWindowsCertificates($e->getMessage())) {
                try {
                    CertificateStore::buildFromWindows();

                    return $send();
                } catch (ConnectionException $retry) {
                    $e = $retry;
                } catch (AiException) {
                    // segue com a mensagem do erro original
                }
            }

            throw new AiException(self::connectionMessage($provider, $e->getMessage()), 0, $e);
        }
    }

    private function canImportWindowsCertificates(string $error): bool
    {
        return CertificateStore::isWindows()
            && (string) config('services.ai.ca_bundle') === ''
            && CertificateStore::isCertificateError($error)
            && ! Cache::has('ai-windows-certs-imported')
            && Cache::put('ai-windows-certs-imported', true, now()->addMinutes(10));
    }

    private function http(AiProvider $provider): PendingRequest
    {
        return Http::timeout((int) config('services.ai.timeout', 180))
            ->connectTimeout(15)
            ->withOptions(['verify' => self::caBundle()])
            ->acceptJson()
            ->asJson();
    }

    /**
     * Certificados confiáveis para HTTPS. No Windows/Laragon o PHP costuma vir sem o
     * curl.cainfo configurado, o que derruba toda conexão HTTPS (cURL error 60).
     * Ordem: AI_CA_BUNDLE, o arquivo gerado com as autoridades do Windows
     * (php artisan ai:certificados), o do sistema ou o que acompanha o composer/ca-bundle.
     */
    public static function caBundle(): string|bool
    {
        $custom = (string) config('services.ai.ca_bundle');
        if ($custom !== '' && is_readable($custom)) {
            return $custom;
        }
        if ($generated = CertificateStore::generated()) {
            return $generated;
        }

        try {
            return CaBundle::getSystemCaRootBundlePath();
        } catch (\Throwable) {
            return true;
        }
    }

    /** Explica em português a causa real de uma falha de conexão (erros do cURL). */
    public static function connectionMessage(AiProvider $provider, string $error): string
    {
        $host = parse_url($provider->endpoint(), PHP_URL_HOST) ?: $provider->endpoint();
        preg_match('/cURL error (\d+)/', $error, $m);
        $code = (int) ($m[1] ?? 0);

        $reason = match (true) {
            (bool) preg_match('/self[- ]signed certificate/i', $error) => 'a conexão HTTPS está sendo interceptada por um programa deste computador ou da rede (antivírus com "verificação HTTPS/SSL", firewall ou proxy da empresa), que troca o certificado do site pelo dele.'
                .(CertificateStore::isWindows() ? ' O sistema tentou usar os certificados instalados no Windows e não bastou.' : '')
                .' Solução: no antivírus, desative a verificação de conexões HTTPS/SSL ou coloque o php.exe nas exceções; ou exporte o certificado raiz desse programa (.pem) e informe o caminho em AI_CA_BUNDLE no .env.',
            in_array($code, [60, 77], true) || str_contains($error, 'SSL certificate') => 'o PHP não conseguiu validar o certificado SSL do site. No Laragon/Windows isso acontece quando o php.ini não tem o "curl.cainfo" configurado. Atualize o sistema (composer install) ou informe o caminho de um cacert.pem em AI_CA_BUNDLE no .env.',
            $code === 6 => 'o endereço "'.$host.'" não foi encontrado (DNS). Verifique a internet do servidor e se a URL está correta.',
            $code === 7 => 'a conexão com "'.$host.'" foi recusada. Verifique se o serviço está rodando, a porta e o firewall/antivírus.',
            $code === 28 => 'o provedor demorou demais para responder. Tente novamente ou aumente AI_TIMEOUT no .env.',
            in_array($code, [5, 56], true) => 'falha no proxy da rede. Verifique as configurações de proxy do servidor.',
            in_array($code, [35, 51, 58], true) => 'falha na negociação SSL/TLS com "'.$host.'". Verifique antivírus/firewall que inspecionam HTTPS e a versão do OpenSSL do PHP.',
            default => 'verifique a URL, a internet do servidor ou se o serviço local está rodando.',
        };

        $detail = Str::limit(trim(preg_replace('/\s*\(see https?:\/\/[^)]+\)/', '', $error)), 220);

        return "Não foi possível conectar ao provedor ({$provider->endpoint()}): {$reason} Detalhe técnico: {$detail}";
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
