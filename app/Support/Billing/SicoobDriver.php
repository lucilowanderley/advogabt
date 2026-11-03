<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Sicoob, API Cobrança Bancária v3. Produção: OAuth2 (client credentials) com certificado
 * (mTLS) e o client_id em todas as chamadas. Sandbox: token fixo do portal. O nosso número
 * é gerado pela cooperativa.
 */
class SicoobDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3',
        'producao' => 'https://api.sicoob.com.br/cobranca-bancaria/v3',
    ];

    public const OAUTH = 'https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token';

    public const SCOPES = 'boletos_inclusao boletos_consulta boletos_alteracao';

    public function test(BillingProvider $p): string
    {
        $this->forgetToken($p);
        $this->withCertificate($p, fn (array $tls) => $this->token($p, $tls));

        return 'Sicoob conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').'), cliente '.$p->credential('beneficiario').'.';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $payer = self::payer($payer, 'Sicoob');
        $penaltyDate = $c->due_date->copy()->addDay()->format('Y-m-d');
        $payload = array_filter([
            'numeroCliente' => (int) self::digits($p->credential('beneficiario')),
            'codigoModalidade' => 1,
            'numeroContaCorrente' => (int) self::digits($p->credential('conta')),
            'codigoEspecieDocumento' => 'DM',
            'dataEmissao' => today()->format('Y-m-d'),
            'seuNumero' => self::seuNumero($c),
            'identificacaoEmissaoBoleto' => 1,
            'identificacaoDistribuicaoBoleto' => 1,
            'valor' => round((float) $c->amount, 2),
            'dataVencimento' => $c->due_date->format('Y-m-d'),
            'dataLimitePagamento' => $c->due_date->copy()->addDays(30)->format('Y-m-d'),
            'tipoDesconto' => 0,
            'tipoMulta' => (float) $p->fine_percent > 0 ? 2 : 0,
            'dataMulta' => (float) $p->fine_percent > 0 ? $penaltyDate : null,
            'valorMulta' => (float) $p->fine_percent > 0 ? (float) $p->fine_percent : null,
            'tipoJurosMora' => (float) $p->interest_percent > 0 ? 2 : 3,
            'dataJurosMora' => (float) $p->interest_percent > 0 ? $penaltyDate : null,
            'valorJurosMora' => (float) $p->interest_percent > 0 ? (float) $p->interest_percent : null,
            'numeroParcela' => 1,
            'pagador' => array_filter([
                'numeroCpfCnpj' => $payer['doc'],
                'nome' => Str::ascii($payer['name']),
                'endereco' => Str::ascii($payer['address']),
                'bairro' => Str::ascii($payer['district']),
                'cidade' => Str::ascii($payer['city']),
                'cep' => $payer['cep'],
                'uf' => $payer['uf'],
                'email' => $payer['email'],
            ]),
            'mensagensInstrucao' => [Str::limit(Str::ascii($c->description), 40, '')],
            'gerarPdf' => false,
            'codigoCadastrarPIX' => $c->method === 'boleto' ? 2 : 1,
        ], fn ($v) => $v !== null);

        $r = $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, 'boletos'), $payload));
        $data = $r->json('resultado') ?? [];
        $ourNumber = (string) ($data['nossoNumero'] ?? '');
        if ($ourNumber === '') {
            throw new BillingException('O Sicoob não devolveu o nosso número do boleto.');
        }
        $c->forceFill([
            'external_id' => $ourNumber,
            'bank_number' => $ourNumber,
            'digitable_line' => $data['linhaDigitavel'] ?? null,
            'barcode' => $data['codigoBarras'] ?? null,
            'pix_payload' => ($data['qrCode'] ?? null) ?: null,
            'remote_status' => 'Em Aberto',
        ])->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, 'boletos'), $this->key($p, $c)));
        $data = $r->json('resultado') ?? [];
        $situation = Str::lower(Str::ascii((string) ($data['situacaoBoleto'] ?? '')));
        $status = match (true) {
            str_contains($situation, 'liquidado') => 'pago',
            str_contains($situation, 'baixado') => 'cancelado',
            default => self::openStatus($c),
        };
        $settlement = collect($data['listaHistorico'] ?? [])->first(fn ($h) => str_contains(Str::lower(Str::ascii((string) ($h['descricaoHistorico'] ?? ''))), 'liquida'));

        return new RemoteCharge(
            $status,
            $situation,
            $status === 'pago' ? self::parseDate($settlement['dataHistorico'] ?? $data['dataLiquidacao'] ?? null) : null,
            $status === 'pago' && isset($data['valorPago']) ? (float) $data['valorPago'] : null,
            null,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, 'boletos/'.$c->external_id.'/baixar'), [
            'numeroCliente' => (int) self::digits($p->credential('beneficiario')),
            'codigoModalidade' => 1,
        ]));
    }

    public function pdf(BillingProvider $p, BillingCharge $c): ?string
    {
        $r = $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, 'boletos/segunda-via'), $this->key($p, $c) + ['gerarPdf' => 'true']));
        $pdf = base64_decode((string) $r->json('resultado.pdfBoleto'), true);
        if (! $pdf || ! str_starts_with($pdf, '%PDF')) {
            throw new BillingException('O Sicoob não devolveu o PDF do boleto.');
        }

        return $pdf;
    }

    private function key(BillingProvider $p, BillingCharge $c): array
    {
        return ['numeroCliente' => self::digits($p->credential('beneficiario')), 'codigoModalidade' => 1, 'nossoNumero' => $c->external_id];
    }

    private function send(BillingProvider $p, callable $request): Response
    {
        return $this->withCertificate($p, function (array $tls) use ($p, $request) {
            $h = $this->http()->withOptions($tls)->withToken($this->token($p, $tls))->withHeaders(['client_id' => (string) $p->client_id]);

            return $this->guard($p, $this->call($p, fn () => $request($h)));
        });
    }

    private function token(BillingProvider $p, array $tls): string
    {
        if ($p->isSandbox() && filled($p->api_key)) {
            return (string) $p->api_key; // token fixo do sandbox do portal Sicoob
        }

        return $this->oauthToken($p, fn () => $this->http()->withOptions($tls)->asForm()->post(self::OAUTH, [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $p->client_id,
            'scope' => self::SCOPES,
        ]));
    }

    private function url(BillingProvider $p, string $path): string
    {
        return (self::HOSTS[$p->environment] ?? self::HOSTS['sandbox']).'/'.ltrim($path, '/');
    }
}
