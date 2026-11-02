<?php

namespace App\Support\Whatsapp;

use App\Models\WhatsappChannel;
use App\Support\Ai\LlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fala com o WhatsApp do escritório: envia textos, lê os avisos de mensagens
 * recebidas e baixa arquivos. Suporta a Evolution API e a Cloud API da Meta.
 */
class WhatsappClient
{
    public const META_GRAPH = 'https://graph.facebook.com';

    public const META_VERSION = 'v21.0';

    /** Envia um texto. Devolve o id da mensagem no WhatsApp, quando informado. */
    public function sendText(WhatsappChannel $channel, string $phone, string $text): ?string
    {
        $phone = preg_replace('/\D/', '', $phone);
        $text = Str::limit($text, 4000, '…');

        if ($channel->driver === 'meta') {
            $response = $this->call(fn () => $this->meta($channel)->post($this->metaUrl($channel, $channel->phone_number_id.'/messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $text],
            ]));
            $this->guard($response);

            return $response->json('messages.0.id');
        }

        $response = $this->call(fn () => $this->evolution($channel)->post($this->evolutionUrl($channel, 'message/sendText'), [
            'number' => $phone,
            'text' => $text,
        ]));
        $this->guard($response);

        return $response->json('key.id');
    }

    /** Confere a conexão. Devolve uma descrição curta do número conectado. */
    public function check(WhatsappChannel $channel): string
    {
        if ($channel->driver === 'meta') {
            $response = $this->call(fn () => $this->meta($channel)->get($this->metaUrl($channel, $channel->phone_number_id), ['fields' => 'display_phone_number,verified_name']));
            $this->guard($response);

            return trim($response->json('verified_name').' '.$response->json('display_phone_number'));
        }

        $response = $this->call(fn () => $this->evolution($channel)->get($this->evolutionUrl($channel, 'instance/connectionState')));
        $this->guard($response);
        $state = $response->json('instance.state') ?? $response->json('state');
        if ($state && $state !== 'open') {
            throw new WhatsappException("A instância \"{$channel->instance}\" está \"{$state}\". Abra o painel da Evolution API e leia o QR Code com o WhatsApp do escritório.");
        }

        return "instância {$channel->instance} conectada";
    }

    /**
     * Lê o aviso recebido e devolve as mensagens de clientes (ignora grupos,
     * mensagens enviadas pelo próprio número e avisos de status).
     *
     * @return list<Inbound>
     */
    public function parse(WhatsappChannel $channel, array $payload): array
    {
        return $channel->driver === 'meta' ? $this->parseMeta($payload) : $this->parseEvolution($payload);
    }

    /** A Meta assina cada aviso com o segredo do app (X-Hub-Signature-256). */
    public function validSignature(WhatsappChannel $channel, Request $request): bool
    {
        if ($channel->driver !== 'meta' || blank($channel->app_secret)) {
            return true;
        }
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $channel->app_secret);

        return hash_equals($expected, (string) $request->header('X-Hub-Signature-256'));
    }

    /** Baixa o arquivo de uma mensagem. @return array{0: string, 1: ?string} conteúdo e tipo */
    public function downloadMedia(WhatsappChannel $channel, Inbound $message): array
    {
        if ($message->mediaBase64) {
            return [base64_decode($message->mediaBase64), $message->mediaMime];
        }
        if (! $message->mediaRef) {
            throw new WhatsappException('O aviso não trouxe o arquivo.');
        }

        if ($channel->driver === 'meta') {
            $info = $this->call(fn () => $this->meta($channel)->get($this->metaUrl($channel, $message->mediaRef)));
            $this->guard($info);
            $file = $this->call(fn () => $this->meta($channel)->accept('*/*')->get((string) $info->json('url')));
            $this->guard($file);

            return [$file->body(), $info->json('mime_type') ?: $message->mediaMime];
        }

        $response = $this->call(fn () => $this->evolution($channel)->post($this->evolutionUrl($channel, 'chat/getBase64FromMediaMessage'), [
            'message' => ['key' => ['id' => $message->mediaRef]],
            'convertToMp4' => false,
        ]));
        $this->guard($response);
        $base64 = (string) $response->json('base64');
        if ($base64 === '') {
            throw new WhatsappException('A Evolution API não devolveu o arquivo.');
        }

        return [base64_decode(preg_replace('#^data:[^;]+;base64,#', '', $base64)), $response->json('mimetype') ?: $message->mediaMime];
    }

    // --- Evolution API -------------------------------------------------------

    private function parseEvolution(array $payload): array
    {
        $event = strtolower(str_replace('_', '.', (string) ($payload['event'] ?? '')));
        if ($event !== 'messages.upsert') {
            return [];
        }
        $items = isset($payload['data']['key']) ? [$payload['data']] : ($payload['data']['messages'] ?? (is_array($payload['data'] ?? null) && array_is_list($payload['data']) ? $payload['data'] : []));

        $out = [];
        foreach ($items as $data) {
            $key = $data['key'] ?? [];
            $jid = (string) ($key['remoteJid'] ?? '');
            if (($key['fromMe'] ?? false) || ! str_ends_with($jid, '@s.whatsapp.net')) {
                continue; // enviada por nós, grupo, status ou lista de transmissão
            }
            $msg = $data['message'] ?? [];
            $phone = preg_replace('/\D/', '', strstr($jid, '@', true) ?: $jid);
            $base = ['phone' => $phone, 'externalId' => (string) ($key['id'] ?? Str::uuid()), 'profileName' => $data['pushName'] ?? null];
            $b64 = $msg['base64'] ?? ($data['base64'] ?? null);

            if (isset($msg['conversation']) || isset($msg['extendedTextMessage'])) {
                $out[] = new Inbound(...$base, type: 'text', text: $msg['conversation'] ?? ($msg['extendedTextMessage']['text'] ?? ''));
            } elseif ($m = $msg['documentMessage'] ?? ($msg['documentWithCaptionMessage']['message']['documentMessage'] ?? null)) {
                $out[] = new Inbound(...$base, type: 'document', text: $m['caption'] ?? null, mediaName: $m['fileName'] ?? ($m['title'] ?? null), mediaMime: $m['mimetype'] ?? null, mediaBase64: $b64, mediaRef: $base['externalId']);
            } elseif ($m = $msg['imageMessage'] ?? null) {
                $out[] = new Inbound(...$base, type: 'image', text: $m['caption'] ?? null, mediaMime: $m['mimetype'] ?? 'image/jpeg', mediaBase64: $b64, mediaRef: $base['externalId']);
            } elseif ($m = $msg['audioMessage'] ?? null) {
                $out[] = new Inbound(...$base, type: 'audio', mediaMime: $m['mimetype'] ?? 'audio/ogg', mediaBase64: $b64, mediaRef: $base['externalId']);
            } elseif ($m = $msg['videoMessage'] ?? null) {
                $out[] = new Inbound(...$base, type: 'video', text: $m['caption'] ?? null, mediaMime: $m['mimetype'] ?? 'video/mp4', mediaBase64: $b64, mediaRef: $base['externalId']);
            } elseif ($msg) {
                $out[] = new Inbound(...$base, type: 'other');
            }
        }

        return $out;
    }

    private function evolution(WhatsappChannel $channel): PendingRequest
    {
        return $this->http()->withHeaders(['apikey' => (string) $channel->api_key]);
    }

    private function evolutionUrl(WhatsappChannel $channel, string $path): string
    {
        return rtrim((string) $channel->base_url, '/').'/'.$path.'/'.rawurlencode((string) $channel->instance);
    }

    // --- Meta (WhatsApp Cloud API) ------------------------------------------

    private function parseMeta(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);
                foreach ($value['messages'] ?? [] as $m) {
                    $phone = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                    $base = ['phone' => $phone, 'externalId' => (string) ($m['id'] ?? Str::uuid()), 'profileName' => $names[$m['from'] ?? ''] ?? null];
                    $type = $m['type'] ?? 'other';
                    $media = $m[$type] ?? [];
                    $out[] = match ($type) {
                        'text' => new Inbound(...$base, type: 'text', text: $m['text']['body'] ?? ''),
                        'button' => new Inbound(...$base, type: 'text', text: $m['button']['text'] ?? ''),
                        'interactive' => new Inbound(...$base, type: 'text', text: $m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? '')),
                        'document', 'image', 'audio', 'video' => new Inbound(...$base, type: $type, text: $media['caption'] ?? null, mediaName: $media['filename'] ?? null, mediaMime: $media['mime_type'] ?? null, mediaRef: $media['id'] ?? null),
                        default => new Inbound(...$base, type: 'other'),
                    };
                }
            }
        }

        return $out;
    }

    private function meta(WhatsappChannel $channel): PendingRequest
    {
        return $this->http()->withToken((string) $channel->api_key);
    }

    private function metaUrl(WhatsappChannel $channel, string $path): string
    {
        return rtrim($channel->base_url ?: self::META_GRAPH, '/').'/'.self::META_VERSION.'/'.$path;
    }

    // --- comum ---------------------------------------------------------------

    private function http(): PendingRequest
    {
        return Http::timeout(30)->connectTimeout(10)->withOptions(['verify' => LlmClient::caBundle()])->acceptJson()->asJson();
    }

    private function call(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw new WhatsappException('Não foi possível conectar ao serviço de WhatsApp: '.Str::limit($e->getMessage(), 300), 0, $e);
        }
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $detail = $response->json('error.message') ?? $response->json('response.message') ?? $response->json('message') ?? Str::limit($response->body(), 300);
        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $hint = match ($response->status()) {
            401, 403 => ' Confira a chave/token de acesso.',
            404 => ' Confira a URL do servidor e o nome da instância (ou o Phone Number ID).',
            default => '',
        };

        throw new WhatsappException("O serviço de WhatsApp respondeu HTTP {$response->status()}: {$detail}.{$hint}");
    }
}
