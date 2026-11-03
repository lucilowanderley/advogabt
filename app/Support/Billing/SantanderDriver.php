<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Santander, API de Cobrança (collection_bill_management v2). OAuth2 com certificado (mTLS).
 * Os boletos ficam em um "workspace" de cobrança ligado ao convênio; se não houver, o sistema
 * cria na primeira chamada e guarda o id. O nosso número (bankNumber) é gerado pelo sistema.
 */
class SantanderDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://trust-sandbox.api.santander.com.br',
        'producao' => 'https://trust-open.api.santander.com.br',
    ];

    public function test(BillingProvider $p): string
    {
        $this->forgetToken($p);
        $workspace = $this->workspace($p);

        return 'Santander conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').'), workspace '.$workspace.'.';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $payer = self::payer($payer, 'Santander');
        $workspace = $this->workspace($p);
        $bankNumber = (string) $c->id;
        $pix = $c->method !== 'boleto' && filled($p->credential('pix_key'));

        $payload = array_filter([
            'environment' => $p->isSandbox() ? 'TESTE' : 'PRODUCAO',
            'nsuCode' => substr(self::seuNumero($c).'-'.now()->format('His'), 0, 20),
            'nsuDate' => today()->format('Y-m-d'),
            'covenantCode' => self::digits($p->credential('convenio')),
            'bankNumber' => $bankNumber,
            'clientNumber' => substr(self::seuNumero($c), 0, 15),
            'dueDate' => $c->due_date->format('Y-m-d'),
            'issueDate' => today()->format('Y-m-d'),
            'participantCode' => self::seuNumero($c),
            'nominalValue' => number_format((float) $c->amount, 2, '.', ''),
            'payer' => [
                'name' => Str::ascii($payer['name']),
                'documentType' => $payer['pj'] ? 'CNPJ' : 'CPF',
                'documentNumber' => $payer['doc'],
                'address' => Str::ascii($payer['address']),
                'neighborhood' => Str::ascii($payer['district']),
                'city' => Str::ascii($payer['city']),
                'state' => $payer['uf'],
                'zipCode' => substr($payer['cep'], 0, 5).'-'.substr($payer['cep'], 5),
            ],
            'documentKind' => 'DUPLICATA_MERCANTIL',
            'finePercentage' => (float) $p->fine_percent > 0 ? number_format((float) $p->fine_percent, 2, '.', '') : null,
            'fineQuantityDays' => (float) $p->fine_percent > 0 ? '1' : null,
            'interestPercentage' => (float) $p->interest_percent > 0 ? number_format((float) $p->interest_percent, 2, '.', '') : null,
            'protestType' => 'SEM_PROTESTO',
            'writeOffQuantityDays' => '30',
            'paymentType' => 'REGISTRO',
            'messages' => [Str::limit(Str::ascii($c->description), 100, '')],
            'key' => $pix ? ['type' => self::pixKeyType((string) $p->credential('pix_key')), 'dictKey' => trim((string) $p->credential('pix_key'))] : null,
        ], fn ($v) => $v !== null);

        $r = $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, "workspaces/{$workspace}/bank_slips"), $payload));
        $c->forceFill([
            'external_id' => self::digits($p->credential('convenio')).'.'.($r->json('bankNumber') ?: $bankNumber),
            'bank_number' => (string) ($r->json('bankNumber') ?: $bankNumber),
            'digitable_line' => $r->json('digitableLine'),
            'barcode' => $r->json('barCode') ?: $r->json('barcode'),
            'pix_payload' => $r->json('qrCodePix') ?: null,
            'remote_status' => (string) ($r->json('status') ?: 'ATIVO'),
        ])->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, 'bills/'.$c->external_id), ['tipoConsulta' => 'settlement']));
        $data = $r->json('content.0') ?? $r->json();
        $remote = Str::upper((string) (data_get($data, 'status') ?? data_get($data, 'bankSlipStatus')));
        $status = match (true) {
            str_contains($remote, 'LIQUIDADO') || str_contains($remote, 'PAGO') => 'pago',
            str_contains($remote, 'BAIXADO') => 'cancelado',
            default => self::openStatus($c),
        };
        $settlement = data_get($data, 'settlements.0') ?? data_get($data, 'settlementData.0') ?? [];
        $value = data_get($settlement, 'settlementValue') ?? data_get($settlement, 'paidValue') ?? data_get($data, 'paidValue');

        return new RemoteCharge(
            $status,
            $remote,
            $status === 'pago' ? self::parseDate(data_get($settlement, 'settlementDate') ?? data_get($settlement, 'paymentDate') ?? data_get($data, 'settlementDate')) : null,
            $status === 'pago' && $value ? (float) $value : null,
            $status === 'pago' && str_contains(Str::upper((string) data_get($settlement, 'settlementType', '')), 'PIX') ? 'pix' : null,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $workspace = $this->workspace($p);
        $this->send($p, fn (PendingRequest $h) => $h->asJson()->patch($this->url($p, "workspaces/{$workspace}/bank_slips"), [
            'covenantCode' => self::digits($p->credential('convenio')),
            'bankNumber' => $c->bank_number,
            'operation' => 'BAIXAR',
        ]));
    }

    /** Id do workspace de cobrança (cria e guarda se ainda não houver). */
    public function workspace(BillingProvider $p): string
    {
        if (filled($p->credential('workspace'))) {
            return (string) $p->credential('workspace');
        }
        $r = $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, 'workspaces'), [
            'type' => 'BILLING',
            'covenants' => [['code' => self::digits($p->credential('convenio'))]],
            'description' => 'ADVOGABT',
            'bankSlipBillingWebhookActive' => false,
            'pixBillingWebhookActive' => false,
        ]));
        $id = (string) $r->json('id');
        if ($id === '') {
            throw new BillingException('O Santander não devolveu o workspace de cobrança criado.');
        }
        $p->settings = array_merge($p->settings ?? [], ['workspace' => $id]);
        $p->saveQuietly();

        return $id;
    }

    public static function pixKeyType(string $key): string
    {
        $key = trim($key);

        return match (true) {
            str_contains($key, '@') => 'EMAIL',
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) => 'EVP',
            str_starts_with($key, '+') => 'CELULAR',
            strlen(self::digits($key)) === 14 => 'CNPJ',
            strlen(self::digits($key)) === 11 => 'CPF',
            default => 'EVP',
        };
    }

    private function send(BillingProvider $p, callable $request): Response
    {
        return $this->withCertificate($p, function (array $tls) use ($p, $request) {
            $h = $this->http()->withOptions($tls)->withToken($this->token($p, $tls))->withHeaders(['X-Application-Key' => (string) $p->client_id]);

            return $this->guard($p, $this->call($p, fn () => $request($h)));
        });
    }

    private function token(BillingProvider $p, array $tls): string
    {
        return $this->oauthToken($p, fn () => $this->http()->withOptions($tls)->asForm()
            ->post($this->host($p).'/auth/oauth/v2/token', [
                'client_id' => (string) $p->client_id,
                'client_secret' => (string) $p->client_secret,
                'grant_type' => 'client_credentials',
            ]));
    }

    private function host(BillingProvider $p): string
    {
        return self::HOSTS[$p->environment] ?? self::HOSTS['sandbox'];
    }

    private function url(BillingProvider $p, string $path): string
    {
        return $this->host($p).'/collection_bill_management/v2/'.ltrim($path, '/');
    }
}
