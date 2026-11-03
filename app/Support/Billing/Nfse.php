<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\FinancialEntry;
use App\Models\FiscalInvoice;
use App\Support\Integrations\Webhooks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * NFS-e pelo Asaas: a prefeitura e o certificado do escritório ficam configurados no Asaas;
 * o sistema manda os dados do serviço, acompanha a autorização e guarda número, PDF e XML.
 */
class Nfse
{
    public static function provider(): BillingProvider
    {
        $p = BillingProvider::nfse();
        if (! $p) {
            throw new BillingException('Ligue a emissão de NFS-e em Integrações › Cobrança e notas fiscais (conta do Asaas).');
        }
        if (! $p->setting('service_id') && ! $p->setting('service_code')) {
            throw new BillingException('Escolha o serviço municipal da NFS-e em Integrações › Cobrança e notas fiscais.');
        }

        return $p;
    }

    /** Texto padrão do serviço, com {descricao}, {cliente} e {processo} trocados. */
    public static function description(FinancialEntry $entry, ?BillingProvider $p = null): string
    {
        $p ??= BillingProvider::forDriver('asaas');
        $template = (string) ($p?->setting('description') ?: BillingProvider::NFSE_DEFAULTS['description']);
        $entry->loadMissing(['contact', 'legalCase']);
        $case = $entry->legalCase ? trim(($entry->legalCase->number ? $entry->legalCase->number.' - ' : '').$entry->legalCase->title) : '';

        return trim(strtr($template, [
            '{descricao}' => $entry->description,
            '{cliente}' => (string) $entry->contact?->name,
            '{processo}' => $case,
        ]));
    }

    public static function issue(FinancialEntry $entry, ?BillingCharge $charge = null, ?int $userId = null, ?string $description = null): FiscalInvoice
    {
        if ($entry->type !== 'receita') {
            throw new BillingException('Só dá para emitir nota de lançamentos de receita.');
        }
        $entry->loadMissing('contact');
        if (! $entry->contact) {
            throw new BillingException('Escolha o cliente (tomador do serviço) no lançamento antes de emitir a nota.');
        }
        if ($existing = $entry->invoices()->active()->first()) {
            throw new BillingException('Este lançamento já tem nota fiscal ('.$existing->status_label.'). Cancele-a antes de emitir outra.');
        }
        $p = self::provider();
        $charge ??= $entry->charges()->where('status', 'pago')->first() ?? $entry->charges()->open()->first();

        $inv = FiscalInvoice::create([
            'provider' => 'asaas',
            'financial_entry_id' => $entry->id,
            'billing_charge_id' => $charge?->id,
            'contact_id' => $entry->contact_id,
            'user_id' => $userId ?? $charge?->user_id,
            'amount' => $charge?->paid_amount ?? $entry->amount,
            'service_description' => Str::limit($description ?: self::description($entry, $p), 2000, ''),
            'observations' => $p->setting('observations'),
            'status' => 'processando',
            'effective_date' => today(),
        ]);

        try {
            app(AsaasDriver::class)->issueInvoice($p, $inv, $entry->contact, $charge);
            $inv->forceFill(['last_error' => null, 'next_check_at' => now()->addMinutes(5)])->save();
        } catch (\Throwable $e) {
            $message = $e instanceof BillingException ? $e->getMessage() : 'Erro inesperado: '.$e->getMessage();
            $inv->forceFill(['status' => 'erro', 'last_error' => $message, 'next_check_at' => null])->save();
            if (! $e instanceof BillingException) {
                Log::warning('NFS-e: falha ao emitir #'.$inv->id.': '.$e->getMessage());
            }
            Billing::forget();

            throw $e instanceof BillingException ? $e : new BillingException($message, 0, $e);
        }
        Billing::forget();

        return $inv;
    }

    public static function refresh(FiscalInvoice $inv): bool
    {
        if (! $inv->external_id) {
            return false;
        }
        $p = BillingProvider::forDriver('asaas');
        if (! $p?->isConfigured()) {
            throw new BillingException('A conta do Asaas não está configurada.');
        }
        $s = app(AsaasDriver::class)->invoiceStatus($p, $inv);
        $previous = $inv->status;
        $inv->forceFill(array_filter([
            'number' => $s['number'],
            'verification_code' => $s['code'],
            'pdf_url' => $s['pdf'],
            'xml_url' => $s['xml'],
        ]) + [
            'status' => $s['status'],
            'remote_status' => $s['remote'],
            'last_checked_at' => now(),
            'last_error' => $s['status'] === 'erro' ? ($s['message'] ?: 'A prefeitura recusou a nota. Veja o motivo no Asaas.') : null,
            'next_check_at' => $s['status'] === 'processando' ? now()->addMinutes(($inv->created_at && $inv->created_at->diffInHours(now()) > 6) ? 120 : 15) : null,
        ]);
        if ($s['status'] === 'emitida' && ! $inv->issued_at) {
            $inv->issued_at = now();
        }
        $inv->save();
        if ($s['status'] === 'emitida' && $previous !== 'emitida') {
            Webhooks::dispatch('nota_fiscal.emitida', fn () => self::apiData($inv->fresh() ?? $inv));
        }
        Billing::forget();

        return $inv->status !== $previous;
    }

    public static function cancel(FiscalInvoice $inv): void
    {
        if ($inv->external_id && in_array($inv->status, ['processando', 'emitida'], true)) {
            $p = BillingProvider::forDriver('asaas');
            if (! $p?->isConfigured()) {
                throw new BillingException('A conta do Asaas não está configurada.');
            }
            app(AsaasDriver::class)->cancelInvoice($p, $inv);
            // O cancelamento na prefeitura pode levar um tempo: a rotina confirma.
            $inv->forceFill(['status' => 'processando', 'remote_status' => 'PROCESSING_CANCELLATION', 'next_check_at' => now()->addMinutes(10)])->save();
        } else {
            $inv->forceFill(['status' => 'cancelada', 'next_check_at' => null])->save();
        }
        Billing::forget();
    }

    public static function checkPending(int $limit = 20): int
    {
        $count = 0;
        $pending = FiscalInvoice::where('status', 'processando')->whereNotNull('external_id')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderBy('next_check_at')->limit($limit)->get();
        foreach ($pending as $inv) {
            try {
                if (self::refresh($inv)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                $inv->forceFill(['last_checked_at' => now(), 'last_error' => $e->getMessage(), 'next_check_at' => now()->addHours(2)])->save();
            }
        }

        return $count;
    }

    public static function hasWork(): bool
    {
        return FiscalInvoice::where('status', 'processando')->whereNotNull('external_id')->exists();
    }

    /** @param  list<string>  $ids */
    public static function handleWebhook(array $ids): int
    {
        $invoices = FiscalInvoice::whereIn('external_id', $ids)->where('status', 'processando')->limit(10)->get();
        foreach ($invoices as $inv) {
            try {
                self::refresh($inv);
            } catch (\Throwable $e) {
                $inv->forceFill(['last_error' => $e->getMessage(), 'next_check_at' => now()->addMinutes(15)])->save();
            }
        }

        return $invoices->count();
    }

    public static function apiData(FiscalInvoice $inv): array
    {
        return [
            'id' => $inv->id,
            'status' => $inv->status,
            'number' => $inv->number,
            'verification_code' => $inv->verification_code,
            'amount' => (float) $inv->amount,
            'service_description' => $inv->service_description,
            'financial_entry_id' => $inv->financial_entry_id,
            'billing_charge_id' => $inv->billing_charge_id,
            'contact_id' => $inv->contact_id,
            'pdf_url' => $inv->pdf_url,
            'xml_url' => $inv->xml_url,
            'issued_at' => $inv->issued_at?->toIso8601String(),
        ];
    }
}
