<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Banco do Brasil, API Cobranças v2. OAuth2 (client credentials, Basic) e a
 * Developer Application Key em todas as chamadas (gw-dev-app-key). O número do
 * título é "000" + convênio (7) + sequencial (10), gerado pelo sistema.
 */
class BancoBrasilDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => ['oauth' => 'https://oauth.sandbox.bb.com.br/oauth/token', 'api' => 'https://api.sandbox.bb.com.br/cobrancas/v2'],
        'producao' => ['oauth' => 'https://oauth.bb.com.br/oauth/token', 'api' => 'https://api.bb.com.br/cobrancas/v2'],
    ];

    public const SCOPES = 'cobrancas.boletos-info cobrancas.boletos-requisicao';

    public function test(BillingProvider $p): string
    {
        $this->forgetToken($p);
        $this->withCertificate($p, fn (array $tls) => $this->token($p, $tls));

        return 'Banco do Brasil conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').'), convênio '.$p->credential('convenio').'.';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $payer = self::payer($payer, 'Banco do Brasil');
        $number = self::titleNumber($p, $c);
        $penaltyDate = $c->due_date->copy()->addDay()->format('d.m.Y');
        $payload = [
            'numeroConvenio' => (int) self::digits($p->credential('convenio')),
            'numeroCarteira' => (int) self::digits($p->credential('carteira')),
            'numeroVariacaoCarteira' => (int) self::digits($p->credential('variacao')),
            'codigoModalidade' => 1,
            'dataEmissao' => today()->format('d.m.Y'),
            'dataVencimento' => $c->due_date->format('d.m.Y'),
            'valorOriginal' => round((float) $c->amount, 2),
            'indicadorAceiteTituloVencido' => 'S',
            'numeroDiasLimiteRecebimento' => 30,
            'codigoAceite' => 'N',
            'codigoTipoTitulo' => 2,
            'descricaoTipoTitulo' => 'DM',
            'indicadorPermissaoRecebimentoParcial' => 'N',
            'numeroTituloBeneficiario' => self::seuNumero($c),
            'numeroTituloCliente' => $number,
            'mensagemBloquetoOcorrencia' => Str::limit(Str::ascii($c->description), 160, ''),
            'pagador' => array_filter([
                'tipoInscricao' => $payer['pj'] ? 2 : 1,
                'numeroInscricao' => $payer['doc'],
                'nome' => Str::ascii($payer['name']),
                'endereco' => Str::ascii($payer['address']),
                'cep' => (int) $payer['cep'],
                'cidade' => Str::ascii($payer['city']),
                'bairro' => Str::ascii($payer['district']),
                'uf' => $payer['uf'],
                'telefone' => $payer['phone'] ? $payer['ddd'].$payer['phone'] : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'indicadorPix' => $c->method === 'boleto' ? 'N' : 'S',
        ];
        if ((float) $p->fine_percent > 0) {
            $payload['multa'] = ['tipo' => 2, 'data' => $penaltyDate, 'porcentagem' => (float) $p->fine_percent];
        }
        if ((float) $p->interest_percent > 0) {
            $payload['jurosMora'] = ['tipo' => 2, 'porcentagem' => (float) $p->interest_percent];
        }

        $r = $this->send($p, fn (PendingRequest $h, array $q) => $h->asJson()->post($this->url($p, 'boletos').'?'.http_build_query($q), $payload));
        $c->forceFill([
            'external_id' => (string) ($r->json('numero') ?: $number),
            'bank_number' => (string) ($r->json('numero') ?: $number),
            'digitable_line' => $r->json('linhaDigitavel'),
            'barcode' => $r->json('codigoBarraNumerico'),
            'pix_payload' => $r->json('qrCode.emv') ?: null,
            'payment_url' => $r->json('qrCode.url') ?: null,
            'remote_status' => 'REGISTRADO',
        ])->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->send($p, fn (PendingRequest $h, array $q) => $h->get($this->url($p, 'boletos/'.$c->external_id), $q + [
            'numeroConvenio' => self::digits($p->credential('convenio')),
        ]));
        $code = (int) $r->json('codigoEstadoTituloCobranca');
        // 6 = liquidado, 9 = liquidado em cartório, 7 = baixado, 10 = baixado por devolução.
        $status = match (true) {
            in_array($code, [6, 9, 18], true) => 'pago',
            in_array($code, [7, 10, 13], true) => 'cancelado',
            default => self::openStatus($c),
        };
        $paid = $r->json('valorPagoSacado');

        return new RemoteCharge(
            $status,
            (string) $code,
            $status === 'pago' ? self::parseDate($r->json('dataRecebimentoTitulo') ?? $r->json('dataCreditoLiquidacao')) : null,
            $status === 'pago' && $paid ? (float) $paid : null,
            null,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->send($p, fn (PendingRequest $h, array $q) => $h->asJson()->post($this->url($p, 'boletos/'.$c->external_id.'/baixar').'?'.http_build_query($q), [
            'numeroConvenio' => (int) self::digits($p->credential('convenio')),
        ]));
    }

    public static function titleNumber(BillingProvider $p, BillingCharge $c): string
    {
        return '000'.str_pad(substr(self::digits($p->credential('convenio')), -7), 7, '0', STR_PAD_LEFT).str_pad((string) $c->id, 10, '0', STR_PAD_LEFT);
    }

    private function send(BillingProvider $p, callable $request): Response
    {
        return $this->withCertificate($p, function (array $tls) use ($p, $request) {
            $h = $this->http()->withOptions($tls)->withToken($this->token($p, $tls));

            return $this->guard($p, $this->call($p, fn () => $request($h, ['gw-dev-app-key' => (string) $p->api_key])));
        });
    }

    private function token(BillingProvider $p, array $tls): string
    {
        return $this->oauthToken($p, fn () => $this->http()->withOptions($tls)->asForm()
            ->withBasicAuth((string) $p->client_id, (string) $p->client_secret)
            ->post(self::HOSTS[$p->environment]['oauth'] ?? self::HOSTS['sandbox']['oauth'], [
                'grant_type' => 'client_credentials',
                'scope' => self::SCOPES,
            ]));
    }

    private function url(BillingProvider $p, string $path): string
    {
        return (self::HOSTS[$p->environment]['api'] ?? self::HOSTS['sandbox']['api']).'/'.ltrim($path, '/');
    }
}
