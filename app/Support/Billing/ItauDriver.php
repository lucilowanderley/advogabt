<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Itaú, API de Boletos (Cash Management v2). OAuth2 com certificado (mTLS). O beneficiário é
 * agência (4) + conta (7) + DAC (1); o nosso número (8) é gerado pelo sistema. Valores vão em
 * texto com casas fixas (valor: 15 inteiros + 2 decimais; percentual: 7 + 5).
 */
class ItauDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => ['oauth' => 'https://devportal.itau.com.br/api/jwt', 'api' => 'https://devportal.itau.com.br/sandboxapi/cash_management_ext_v2/v2'],
        'producao' => ['oauth' => 'https://sts.itau.com.br/api/oauth/token', 'api' => 'https://api.itau.com.br/cash_management/v2'],
    ];

    public function test(BillingProvider $p): string
    {
        $this->forgetToken($p);
        $this->withCertificate($p, fn (array $tls) => $this->token($p, $tls));

        return 'Itaú conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').'), beneficiário '.self::beneficiary($p).'.';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $payer = self::payer($payer, 'Itaú');
        $ourNumber = str_pad(substr((string) $c->id, -8), 8, '0', STR_PAD_LEFT);
        $pix = $c->method !== 'boleto' && filled($p->credential('pix_key'));
        $person = $payer['pj']
            ? ['codigo_tipo_pessoa' => 'J', 'numero_cadastro_nacional_pessoa_juridica' => $payer['doc']]
            : ['codigo_tipo_pessoa' => 'F', 'numero_cadastro_pessoa_fisica' => $payer['doc']];

        $slip = [
            'descricao_instrumento_cobranca' => $pix ? 'boleto_pix' : 'boleto',
            'tipo_boleto' => 'a vista',
            'codigo_carteira' => self::wallet($p),
            'valor_total_titulo' => self::amount($c->amount),
            'codigo_especie' => '01',
            'data_emissao' => today()->format('Y-m-d'),
            'pagador' => [
                'pessoa' => ['nome_pessoa' => Str::ascii($payer['name']), 'tipo_pessoa' => $person],
                'endereco' => [
                    'nome_logradouro' => Str::ascii($payer['address']),
                    'nome_bairro' => Str::ascii($payer['district']),
                    'nome_cidade' => Str::ascii($payer['city']),
                    'sigla_UF' => $payer['uf'],
                    'numero_CEP' => $payer['cep'],
                ],
            ],
            'dados_individuais_boleto' => [[
                'numero_nosso_numero' => $ourNumber,
                'data_vencimento' => $c->due_date->format('Y-m-d'),
                'valor_titulo' => self::amount($c->amount),
                'texto_seu_numero' => self::seuNumero($c),
            ]],
            'lista_mensagem_cobranca' => [['mensagem' => Str::limit(Str::ascii($c->description), 80, '')]],
        ];
        if ((float) $p->fine_percent > 0) {
            $slip['multa'] = ['codigo_tipo_multa' => '02', 'quantidade_dias_multa' => 1, 'percentual_multa' => self::percent($p->fine_percent)];
        }
        if ((float) $p->interest_percent > 0) {
            $slip['juros'] = ['codigo_tipo_juros' => '90', 'quantidade_dias_juros' => 1, 'percentual_juros' => self::percent($p->interest_percent)];
        }
        $payload = [
            'etapa_processo_boleto' => 'efetivacao',
            'codigo_canal_operacao' => 'API',
            'beneficiario' => ['id_beneficiario' => self::beneficiary($p)],
            'dado_boleto' => $slip,
        ];
        if ($pix) {
            $payload['dados_qrcode'] = ['chave' => trim((string) $p->credential('pix_key'))];
        }

        $r = $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, 'boletos'), $payload));
        $item = $r->json('data.dado_boleto.dados_individuais_boleto.0') ?? [];
        $c->forceFill([
            'external_id' => self::beneficiary($p).self::wallet($p).$ourNumber,
            'bank_number' => $ourNumber,
            'digitable_line' => $item['numero_linha_digitavel'] ?? null,
            'barcode' => $item['codigo_barras'] ?? null,
            'pix_payload' => $r->json('data.dados_qrcode.emv') ?: null,
            'remote_status' => 'Em Aberto',
        ])->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, 'boletos'), [
            'id_beneficiario' => self::beneficiary($p),
            'codigo_carteira' => self::wallet($p),
            'nosso_numero' => $c->bank_number,
        ]));
        $slip = $r->json('data.0.dado_boleto') ?? [];
        $situation = Str::lower(Str::ascii((string) data_get($slip, 'dados_individuais_boleto.0.situacao_geral_boleto')));
        $status = match (true) {
            str_contains($situation, 'paga') || str_contains($situation, 'liquid') => 'pago',
            str_contains($situation, 'baixa') => 'cancelado',
            default => self::openStatus($c),
        };
        $payment = data_get($slip, 'pagamentos_cobranca.0', []);

        return new RemoteCharge(
            $status,
            $situation,
            $status === 'pago' ? self::parseDate(data_get($payment, 'data_inclusao_pagamento') ?? data_get($payment, 'data_pagamento')) : null,
            $status === 'pago' && data_get($payment, 'valor_pago_total_cobranca') ? (float) data_get($payment, 'valor_pago_total_cobranca') : null,
            null,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->send($p, fn (PendingRequest $h) => $h->asJson()->patch($this->url($p, 'boletos/'.$c->external_id.'/baixa'), (object) []));
    }

    public static function beneficiary(BillingProvider $p): string
    {
        $agency = str_pad(substr(self::digits($p->credential('agencia')), -4), 4, '0', STR_PAD_LEFT);
        $account = self::digits($p->credential('conta'));
        $dac = substr($account, -1);

        return $agency.str_pad(substr($account, 0, -1), 7, '0', STR_PAD_LEFT).$dac;
    }

    private static function wallet(BillingProvider $p): string
    {
        return str_pad(self::digits($p->credential('carteira')) ?: '109', 3, '0', STR_PAD_LEFT);
    }

    private static function amount(mixed $value): string
    {
        return str_pad((string) (int) round((float) $value * 100), 17, '0', STR_PAD_LEFT);
    }

    private static function percent(mixed $value): string
    {
        return str_pad((string) (int) round((float) $value * 100000), 12, '0', STR_PAD_LEFT);
    }

    private function send(BillingProvider $p, callable $request): Response
    {
        return $this->withCertificate($p, function (array $tls) use ($p, $request) {
            $h = $this->http()->withOptions($tls)->withToken($this->token($p, $tls))->withHeaders([
                'x-itau-apikey' => (string) $p->client_id,
                'x-itau-correlationID' => (string) Str::uuid(),
                'x-itau-flowID' => (string) Str::uuid(),
            ]);

            return $this->guard($p, $this->call($p, fn () => $request($h)));
        });
    }

    private function token(BillingProvider $p, array $tls): string
    {
        return $this->oauthToken($p, fn () => $this->http()->withOptions($tls)->asForm()
            ->post(self::HOSTS[$p->environment]['oauth'] ?? self::HOSTS['sandbox']['oauth'], [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $p->client_id,
                'client_secret' => (string) $p->client_secret,
            ]));
    }

    private function url(BillingProvider $p, string $path): string
    {
        return (self::HOSTS[$p->environment]['api'] ?? self::HOSTS['sandbox']['api']).'/'.ltrim($path, '/');
    }
}
