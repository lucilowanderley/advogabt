<?php

namespace App\Support\Integrations;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Ai\CertificateStore;
use App\Support\Ai\LlmClient;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Envio dos webhooks: monta o evento, grava o registro de entrega e faz o POST
 * assinado (HMAC SHA-256) depois que a resposta da tela já foi enviada ao usuário.
 */
class Webhooks
{
    public const USER_AGENT = 'ADVOGABT-Webhooks/1.0';

    /** Webhooks ativos carregados uma vez por requisição. */
    private static ?Collection $active = null;

    /** Desliga os disparos (ex.: carga de dados de demonstração). */
    public static bool $muted = false;

    public static function flush(): void
    {
        self::$active = null;
    }

    public static function hasActive(): bool
    {
        return self::active()->isNotEmpty();
    }

    /** @return Collection<int, Webhook> */
    public static function listeners(string $event): Collection
    {
        return self::active()->filter(fn (Webhook $w) => $w->listensTo($event))->values();
    }

    /** @return Collection<int, Webhook> */
    private static function active(): Collection
    {
        if (self::$active === null) {
            try {
                self::$active = Schema::hasTable('webhooks') ? Webhook::active()->get() : collect();
            } catch (\Throwable) {
                self::$active = collect();
            }
        }

        return self::$active;
    }

    /**
     * Dispara um evento. $data pode ser um array ou uma função que o monta, chamada só
     * no fim da requisição (assim relações gravadas depois do registro, como os
     * responsáveis de uma tarefa, já aparecem no envio).
     */
    public static function dispatch(string $event, array|Closure $data): int
    {
        if (self::$muted) {
            return 0;
        }
        $hooks = self::listeners($event);
        if ($hooks->isEmpty()) {
            return 0;
        }

        $run = function () use ($event, $data, $hooks) {
            try {
                $payload = self::envelope($event, $data instanceof Closure ? $data() : $data);
                foreach ($hooks as $hook) {
                    self::send(self::record($hook, $event, $payload));
                }
            } catch (\Throwable $e) {
                Log::warning("Webhook {$event} não enviado: ".$e->getMessage());
            }
        };

        function_exists('Illuminate\Support\defer') ? \Illuminate\Support\defer($run) : app()->terminating($run);

        return $hooks->count();
    }

    public static function envelope(string $event, array $data): array
    {
        return [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'event_label' => WebhookEvents::label($event),
            'created_at' => now()->toIso8601String(),
            'source' => ['system' => 'ADVOGABT', 'office' => config('app.name'), 'url' => config('app.url')],
            'data' => $data,
        ];
    }

    public static function record(Webhook $hook, string $event, array $payload): WebhookDelivery
    {
        // Cada webhook recebe o seu próprio id de entrega.
        $payload['id'] = (string) Str::uuid();

        return $hook->deliveries()->create(['uuid' => $payload['id'], 'event' => $event, 'payload' => $payload]);
    }

    /** Evento de teste com dados de exemplo. */
    public static function test(Webhook $hook): WebhookDelivery
    {
        $payload = self::envelope('teste', [
            'message' => 'Teste de webhook do ADVOGABT. Se você recebeu isto, a integração está funcionando.',
            'webhook' => ['id' => $hook->id, 'name' => $hook->name],
            'events' => $hook->events,
        ]);
        $payload['event_label'] = 'Teste';
        $delivery = self::record($hook, 'teste', $payload);
        self::send($delivery);

        return $delivery->refresh();
    }

    public static function body(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function signature(string $secret, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public static function send(WebhookDelivery $delivery): bool
    {
        $hook = $delivery->webhook;
        $body = self::body($delivery->payload);
        $started = microtime(true);
        $status = null;
        $responseBody = null;
        $error = null;

        $post = fn () => Http::timeout((int) config('services.integrations.webhook_timeout', 15))
            ->connectTimeout(8)
            ->withOptions(['verify' => LlmClient::caBundle()])
            ->withUserAgent(self::USER_AGENT)
            ->withHeaders([
                'X-Advogabt-Event' => $delivery->event,
                'X-Advogabt-Delivery' => $delivery->uuid,
                'X-Advogabt-Signature' => self::signature((string) $hook->secret, $body),
                'X-Advogabt-Attempt' => (string) ($delivery->attempts + 1),
            ])
            ->withBody($body, 'application/json')
            ->post($hook->url);

        try {
            try {
                $response = $post();
            } catch (ConnectionException $e) {
                // Windows com antivírus/proxy que inspeciona HTTPS: usa as autoridades do Windows.
                if (CertificateStore::isWindows() && CertificateStore::isCertificateError($e->getMessage())
                    && Cache::add('webhook-windows-certs', true, now()->addMinutes(10))) {
                    CertificateStore::buildFromWindows();
                    $response = $post();
                } else {
                    throw $e;
                }
            }
            $status = $response->status();
            $responseBody = Str::limit((string) $response->body(), 2000);
            if (! $response->successful()) {
                $error = "O destino respondeu HTTP {$status}.";
            }
        } catch (ConnectionException $e) {
            $error = 'Não foi possível conectar: '.Str::limit($e->getMessage(), 500);
        } catch (\Throwable $e) {
            $error = Str::limit($e->getMessage(), 500);
        }

        $ok = $error === null;
        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'response_status' => $status,
            'response_body' => $responseBody,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'success' => $ok,
            'delivered_at' => $ok ? now() : $delivery->delivered_at,
        ])->save();

        $hook->forceFill([
            'last_delivery_at' => now(),
            'last_delivery_ok' => $ok,
            'consecutive_failures' => $ok ? 0 : $hook->consecutive_failures + 1,
        ])->saveQuietly();

        return $ok;
    }

    /** Reenvia as entregas que falharam, com espera crescente entre as tentativas. */
    public static function retryFailed(): int
    {
        $count = 0;
        WebhookDelivery::with('webhook')
            ->where('success', false)
            ->where('attempts', '<', WebhookDelivery::MAX_ATTEMPTS)
            ->where('created_at', '>=', now()->subDays(2))
            ->whereHas('webhook', fn ($q) => $q->where('active', true))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (WebhookDelivery $d) use (&$count) {
                // 1, 4, 9, 16 minutos depois da última tentativa
                if ($d->updated_at->gt(now()->subMinutes($d->attempts ** 2))) {
                    return;
                }
                self::send($d);
                $count++;
            });

        return $count;
    }
}
