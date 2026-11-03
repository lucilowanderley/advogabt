<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Sicredi, API de Cobrança. Autenticação OAuth2 "password": usuário = código do beneficiário +
 * cooperativa, senha = código de acesso gerado no Internet Banking, com o token do portal
 * (x-api-key) em todas as chamadas. Boleto "HIBRIDO" = boleto com QR Code Pix.
 */
class SicrediDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://api-parceiro.sicredi.com.br/sb',
        'producao' => 'https://api-parceiro.sicredi.com.br',
    ];

    public function test(BillingProvider $p): string
    {
        $this->forgetToken($p);
        $this->token($p);

        return 'Sicredi conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').'), beneficiário '.$p->credential('beneficiario').'.';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $payer = self::payer($payer, 'Sicredi');
        $payload = array_filter([
            'tipoCobranca' => $c->method === 'boleto' ? 'NORMAL' : 'HIBRIDO',
            'codigoBeneficiario' => self::digits($p->credential('beneficiario')),
            'pagador' => array_filter([
                'tipoPessoa' => $payer['pj'] ? 'PESSOA_JURIDICA' : 'PESSOA_FISICA',
                'documento' => $payer['doc'],
                'nome' => Str::ascii($payer['name']),
                'endereco' => Str::ascii($payer['address']),
                'cidade' => Str::ascii($payer['city']),
                'uf' => $payer['uf'],
                'cep' => $payer['cep'],
                'telefone' => $payer['phone'] ? $payer['ddd'].$payer['phone'] : null,
                'email' => $payer['email'],
            ]),
            'especieDocumento' => 'DUPLICATA_MERCANTIL_INDICACAO',
            'seuNumero' => self::seuNumero($c),
            'dataVencimento' => $c->due_date->format('Y-m-d'),
            'valor' => round((float) $c->amount, 2),
            'diasProtestoAuto' => null,
            'diasNegativacaoAuto' => null,
            'validadeAposVencimento' => 30,
            'tipoJuros' => (float) $p->interest_percent > 0 ? 'PERCENTUAL' : null,
            'juros' => (float) $p->interest_percent > 0 ? (float) $p->interest_percent : null,
            'multa' => (float) $p->fine_percent > 0 ? (float) $p->fine_percent : null,
            'informativos' => [Str::limit(Str::ascii($c->description), 80, '')],
        ], fn ($v) => $v !== null);

        $r = $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, 'boletos'), $payload));
        $ourNumber = (string) $r->json('nossoNumero');
        if ($ourNumber === '') {
            throw new BillingException('O Sicredi não devolveu o nosso número do boleto.');
        }
        $c->forceFill([
            'external_id' => $ourNumber,
            'bank_number' => $ourNumber,
            'digitable_line' => $r->json('linhaDigitavel'),
            'barcode' => $r->json('codigoBarras'),
            'pix_payload' => $r->json('qrCode') ?: null,
            'remote_status' => 'EM CARTEIRA',
        ])->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, 'boletos'), [
            'codigoBeneficiario' => self::digits($p->credential('beneficiario')),
            'nossoNumero' => $c->external_id,
        ]));
        $remote = Str::upper(Str::ascii((string) $r->json('situacao')));
        $status = match (true) {
            str_contains($remote, 'LIQUIDADO') => 'pago',
            str_contains($remote, 'BAIXADO') => 'cancelado',
            default => self::openStatus($c),
        };
        $paid = $r->json('dadosLiquidacao.valor');

        return new RemoteCharge(
            $status,
            $remote,
            $status === 'pago' ? self::parseDate($r->json('dadosLiquidacao.data')) : null,
            $status === 'pago' && $paid ? (float) $paid : null,
            $status === 'pago' && $r->json('dadosLiquidacao.pix') ? 'pix' : null,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->send($p, fn (PendingRequest $h) => $h->asJson()
            ->withHeaders(['codigoBeneficiario' => self::digits($p->credential('beneficiario'))])
            ->patch($this->url($p, 'boletos/'.$c->external_id.'/baixa'), (object) []));
    }

    public function pdf(BillingProvider $p, BillingCharge $c): ?string
    {
        if (! $c->digitable_line) {
            return null;
        }
        $r = $this->send($p, fn (PendingRequest $h) => $h->accept('application/pdf')->get($this->url($p, 'boletos/pdf'), ['linhaDigitavel' => $c->digitable_line]));
        $pdf = $r->body();
        if (! str_starts_with($pdf, '%PDF')) {
            throw new BillingException('O Sicredi não devolveu o PDF do boleto.');
        }

        return $pdf;
    }

    private function send(BillingProvider $p, callable $request): Response
    {
        $h = $this->http()->withToken($this->token($p))->withHeaders([
            'x-api-key' => (string) $p->api_key,
            'cooperativa' => self::digits($p->credential('cooperativa')),
            'posto' => self::digits($p->credential('posto')),
        ]);

        return $this->guard($p, $this->call($p, fn () => $request($h)));
    }

    private function token(BillingProvider $p): string
    {
        return $this->oauthToken($p, fn () => $this->http()->asForm()
            ->withHeaders(['x-api-key' => (string) $p->api_key, 'context' => 'COBRANCA'])
            ->post($this->host($p).'/auth/openapi/token', [
                'username' => self::digits($p->credential('beneficiario')).self::digits($p->credential('cooperativa')),
                'password' => (string) $p->client_secret,
                'scope' => 'cobranca',
                'grant_type' => 'password',
            ]));
    }

    private function host(BillingProvider $p): string
    {
        return self::HOSTS[$p->environment] ?? self::HOSTS['sandbox'];
    }

    private function url(BillingProvider $p, string $path): string
    {
        return $this->host($p).'/cobranca/boleto/v1/'.ltrim($path, '/');
    }
}
