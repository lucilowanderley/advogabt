<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\FinancialEntry;
use App\Support\Integrations\Webhooks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cobrança: gera boleto/Pix na plataforma, acompanha o pagamento (pelo aviso da plataforma quando
 * o sistema está publicado, ou consultando pela rotina das integrações no Laragon) e dá baixa
 * automática no lançamento do Financeiro. Se a NFS-e automática estiver ligada, emite a nota.
 */
class Billing
{
    public static function driver(string $name): Driver
    {
        return match ($name) {
            'asaas' => app(AsaasDriver::class),
            'inter' => app(InterDriver::class),
            default => throw new BillingException('Plataforma de cobrança desconhecida: '.$name),
        };
    }

    public static function provider(string $driver): BillingProvider
    {
        $p = BillingProvider::forDriver($driver);
        if (! $p?->isConfigured()) {
            throw new BillingException('O '.BillingProvider::label($driver).' não está configurado em Integrações › Cobrança e notas fiscais.');
        }

        return $p;
    }

    /** Endereço local (Laragon, rede interna): as plataformas não conseguem chamar o sistema. */
    public static function isLocalUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host === '' || ! str_contains($host, '.') || (bool) preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)|\.(test|local|localhost|lan|home|internal)$/', $host);
    }

    // --- Emissão -------------------------------------------------------------

    /** Cria a cobrança de um lançamento a receber e registra na plataforma. */
    public static function issue(FinancialEntry $entry, string $driver, string $method, Carbon $due, ?int $userId = null, ?string $description = null): BillingCharge
    {
        if ($entry->type !== 'receita') {
            throw new BillingException('Só dá para cobrar lançamentos de receita.');
        }
        if ($entry->paid_at) {
            throw new BillingException('Este lançamento já está baixado como recebido.');
        }
        $entry->loadMissing('contact');
        if (! $entry->contact) {
            throw new BillingException('Escolha o cliente (pagador) no lançamento antes de gerar a cobrança.');
        }
        if ($open = $entry->charges()->open()->first()) {
            throw new BillingException('Este lançamento já tem uma cobrança em aberto (#'.$open->id.'). Cancele-a antes de gerar outra.');
        }
        $p = self::provider($driver);
        if (! isset($p->methods()[$method])) {
            throw new BillingException('O '.$p->label.' não oferece a forma "'.(BillingProvider::METHODS[$method] ?? $method).'".');
        }

        $charge = BillingCharge::create([
            'provider' => $driver,
            'method' => $method,
            'financial_entry_id' => $entry->id,
            'contact_id' => $entry->contact_id,
            'user_id' => $userId,
            'description' => $description ?: $entry->description,
            'amount' => $entry->amount,
            'due_date' => $due,
            'status' => 'pendente',
        ]);

        try {
            self::driver($driver)->create($p, $charge, $entry->contact);
            $charge->forceFill(['last_error' => null, 'next_check_at' => self::nextCheck($charge)])->save();
            if (! $entry->payment_method) {
                $entry->forceFill(['payment_method' => $method === 'pix' ? 'pix' : 'boleto'])->saveQuietly();
            }
        } catch (\Throwable $e) {
            $message = $e instanceof BillingException ? $e->getMessage() : 'Erro inesperado: '.$e->getMessage();
            $charge->forceFill(['status' => 'erro', 'last_error' => $message, 'next_check_at' => null])->save();
            if (! $e instanceof BillingException) {
                Log::warning('Cobrança: falha ao emitir #'.$charge->id.': '.$e->getMessage());
            }
            self::forget();

            throw $e instanceof BillingException ? $e : new BillingException($message, 0, $e);
        }
        self::forget();

        return $charge;
    }

    public static function cancel(BillingCharge $c): void
    {
        if ($c->external_id && $c->isOpen()) {
            self::driver($c->provider)->cancel(self::provider($c->provider), $c);
        }
        $c->forceFill(['status' => 'cancelado', 'next_check_at' => null])->save();
        self::forget();
    }

    // --- Acompanhamento ------------------------------------------------------

    /** Consulta a plataforma e atualiza a cobrança. Devolve true se a situação mudou. */
    public static function refresh(BillingCharge $c): bool
    {
        if (! $c->external_id) {
            return false;
        }
        $remote = self::driver($c->provider)->status(self::provider($c->provider), $c);
        $previous = $c->status;
        $c->forceFill($remote->fields + [
            'remote_status' => $remote->remoteStatus ?: $c->remote_status,
            'last_checked_at' => now(),
            'last_error' => null,
        ]);

        if ($remote->status === 'pago' && $previous !== 'pago') {
            self::markPaid($c, $remote);
        } else {
            $c->status = $remote->status;
            $c->next_check_at = in_array($remote->status, BillingCharge::OPEN, true) ? self::nextCheck($c) : null;
            $c->save();
        }
        if ($c->status === 'vencido' && $previous === 'pendente') {
            Webhooks::dispatch('cobranca.vencida', fn () => self::apiData($c));
        }
        self::forget();

        return $c->status !== $previous;
    }

    /** Pagamento confirmado: baixa automática no Financeiro e, se ligada, emissão da NFS-e. */
    public static function markPaid(BillingCharge $c, RemoteCharge $remote): void
    {
        $paidAt = self::date($remote->paidAt) ?? today();
        $c->forceFill([
            'status' => 'pago',
            'paid_at' => $paidAt,
            'paid_amount' => $remote->paidAmount ?? $c->amount,
            'paid_with' => $remote->paidWith,
            'next_check_at' => null,
        ])->save();

        $entry = $c->entry;
        if ($entry && ! $entry->paid_at) {
            $note = 'Baixa automática: pago pelo '.$c->provider_label.($remote->paidWith ? ' ('.match ($remote->paidWith) {
                'pix' => 'Pix', 'boleto' => 'boleto', 'cartao' => 'cartão', default => 'outro meio'
            }.')' : '')
                .' em '.$paidAt->format('d/m/Y');
            if ($remote->paidAmount !== null && abs($remote->paidAmount - (float) $c->amount) >= 0.01) {
                $note .= ', valor recebido R$ '.number_format($remote->paidAmount, 2, ',', '.').' (com multa/juros ou desconto)';
            }
            $entry->paid_at = $paidAt;
            if (in_array($remote->paidWith, ['pix', 'boleto', 'cartao'], true)) {
                $entry->payment_method = $remote->paidWith;
            }
            $entry->notes = trim(($entry->notes ? $entry->notes."\n" : '').$note.'.');
            $entry->save(); // dispara o webhook pagamento.recebido
        }

        $nfse = BillingProvider::nfse();
        if ($entry && $nfse && $nfse->setting('nfse_auto') && ! $entry->invoices()->active()->exists()) {
            try {
                Nfse::issue($entry, $c);
            } catch (\Throwable $e) {
                Log::warning('NFS-e automática não emitida para a cobrança #'.$c->id.': '.$e->getMessage());
            }
        }
    }

    /** Rotina: consulta as cobranças em aberto (e as que ainda não têm linha digitável). */
    public static function checkPending(int $limit = 30): int
    {
        $count = 0;
        $pending = BillingCharge::open()->whereNotNull('external_id')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderBy('next_check_at')->limit($limit)->get();
        foreach ($pending as $c) {
            try {
                if (self::refresh($c)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                $c->forceFill(['last_checked_at' => now(), 'last_error' => $e->getMessage(), 'next_check_at' => now()->addHours(2)])->save();
            }
        }

        return $count;
    }

    /** Rotina das integrações: cobranças em aberto e notas em processamento. @return array{charges: int, invoices: int} */
    public static function run(): array
    {
        return ['charges' => self::checkPending(), 'invoices' => Nfse::checkPending()];
    }

    public static function hasWork(): bool
    {
        return Cache::remember('billing-pending', now()->addMinutes(5), fn () => BillingCharge::open()->exists() || Nfse::hasWork());
    }

    public static function forget(): void
    {
        Cache::forget('billing-pending');
    }

    /** Aviso da plataforma: localiza as cobranças pelos ids citados e confirma a situação na própria plataforma. */
    public static function handleWebhook(BillingProvider $p, array $payload): int
    {
        $ids = array_values(array_unique(array_filter(self::strings($payload), fn ($v) => strlen($v) >= 5 && strlen($v) <= 100)));
        if (! $ids) {
            return 0;
        }
        $charges = BillingCharge::where('provider', $p->driver)->whereIn('external_id', $ids)->whereIn('status', ['pendente', 'vencido', 'pago'])->limit(20)->get();
        foreach ($charges as $c) {
            if ($c->status === 'pago') {
                continue;
            }
            try {
                self::refresh($c);
            } catch (\Throwable $e) {
                $c->forceFill(['last_error' => $e->getMessage(), 'next_check_at' => now()->addMinutes(15)])->save();
            }
        }
        $invoices = $p->driver === 'asaas' ? Nfse::handleWebhook($ids) : 0;

        return $charges->count() + $invoices;
    }

    public static function apiData(BillingCharge $c): array
    {
        return [
            'id' => $c->id,
            'provider' => $c->provider,
            'method' => $c->method,
            'status' => $c->status,
            'description' => $c->description,
            'amount' => (float) $c->amount,
            'due_date' => $c->due_date?->format('Y-m-d'),
            'paid_at' => $c->paid_at?->format('Y-m-d'),
            'paid_amount' => $c->paid_amount !== null ? (float) $c->paid_amount : null,
            'financial_entry_id' => $c->financial_entry_id,
            'contact_id' => $c->contact_id,
            'payment_url' => $c->payment_url,
            'digitable_line' => $c->digitable_line,
            'pix_payload' => $c->pix_payload,
            'url' => route('billing.show', $c),
        ];
    }

    // --- Apoio ---------------------------------------------------------------

    /** Antes do vencimento a cada poucas horas; perto e depois dele, com mais frequência. */
    private static function nextCheck(BillingCharge $c): Carbon
    {
        $missingCodes = ! $c->digitable_line && ! $c->pix_payload && ! $c->payment_url;
        $daysToDue = today()->diffInDays($c->due_date, false);

        return now()->addMinutes(match (true) {
            $missingCodes => 5,
            $daysToDue > 3 => 360,
            $daysToDue >= -5 => 60,
            $daysToDue >= -60 => 360,
            default => 1440,
        });
    }

    private static function date(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function strings(mixed $data, int $depth = 0): array
    {
        if (is_string($data) || is_int($data)) {
            return [(string) $data];
        }
        if (! is_array($data) || $depth > 6) {
            return [];
        }
        $out = [];
        foreach ($data as $v) {
            array_push($out, ...self::strings($v, $depth + 1));
        }

        return array_slice($out, 0, 500);
    }
}
