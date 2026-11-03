<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Bradesco, API de Cobrança (boleto híbrido com Pix). Autenticação por JWT assinado com a chave
 * privada da aplicação (RS256) e cada chamada assinada (X-Brad-Signature). O nosso número (11)
 * é gerado pelo sistema. A homologação é feita com o banco antes de liberar a produção.
 */
class BradescoDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://proxy.api.prebanco.com.br',
        'producao' => 'https://openapi.bradesco.com.br',
    ];

    public function test(BillingProvider $p): string
    {
        $this->forgetToken($p);
        $this->withCertificate($p, fn (array $tls) => $this->token($p, $tls));

        return 'Bradesco conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').').';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $payer = self::payer($payer, 'Bradesco');
        $ourNumber = str_pad(substr((string) $c->id, -11), 11, '0', STR_PAD_LEFT);
        [$base, $branch, $check] = self::cnpjParts($p);
        $payload = [
            'ctitloCobrCdent' => $ourNumber,
            'registrarTitulo' => 1,
            'codUsuario' => 'APISERVIC',
            'nroCpfCnpjBenef' => $base,
            'filCpfCnpjBenef' => $branch,
            'digCpfCnpjBenef' => $check,
            'tipoAcesso' => 2,
            'cpssoaJuridContr' => 0,
            'ctpoContrNegoc' => 0,
            'nseqContrNegoc' => 0,
            'cidtfdProdCobr' => (int) self::digits($p->credential('carteira')),
            'cnegocCobr' => self::negotiation($p),
            'codigoBanco' => 237,
            'eNseqContrNegoc' => 0,
            'tipoRegistro' => 1,
            'cprodtServcOper' => 0,
            'ctitloCliCdent' => self::seuNumero($c),
            'demisTitloCobr' => today()->format('d.m.Y'),
            'dvctoTitloCobr' => $c->due_date->format('d.m.Y'),
            'cidtfdTpoVcto' => 0,
            'cindcdEconmMoeda' => 6,
            'vnmnalTitloCobr' => (int) round((float) $c->amount * 100),
            'qmoedaNegocTitlo' => 0,
            'cespceTitloCobr' => 2,
            'cindcdAceitSacdo' => 'N',
            'ctpoProteTitlo' => 0,
            'ctpoPrzProte' => 0,
            'ctpoProteDecurs' => 0,
            'ctpoPrzDecurs' => 0,
            'cctrlPartcTitlo' => 0,
            'cformaEmisPplta' => 2,
            'cindcdPgtoParcial' => 'N',
            'qtdePgtoParcial' => 0,
            'ptxJuroVcto' => (float) $p->interest_percent,
            'vdiaJuroMora' => 0,
            'qdiaInicJuro' => (float) $p->interest_percent > 0 ? 1 : 0,
            'pmultaAplicVcto' => (float) $p->fine_percent,
            'vmultaAtrsoPgto' => 0,
            'qdiaInicMulta' => (float) $p->fine_percent > 0 ? 1 : 0,
            'isacdoTitloCobr' => Str::limit(Str::ascii($payer['name']), 70, ''),
            'elogdrSacdoTitlo' => Str::limit(Str::ascii($payer['address']), 40, ''),
            'enroLogdrSacdo' => '0',
            'ecomplLogdrSacdo' => '',
            'ccepSacdoTitlo' => (int) substr($payer['cep'], 0, 5),
            'ccomplCepSacdo' => (int) substr($payer['cep'], 5, 3),
            'ebairoLogdrSacdo' => Str::limit(Str::ascii($payer['district']), 40, ''),
            'imunSacdoTitlo' => Str::limit(Str::ascii($payer['city']), 30, ''),
            'csglUfSacdo' => $payer['uf'],
            'indCpfCnpjSacdo' => $payer['pj'] ? 2 : 1,
            'nroCpfCnpjSacdo' => (int) $payer['doc'],
            'renderEletrSacdo' => (string) $payer['email'],
            'cdddFoneSacdo' => (int) $payer['ddd'],
            'cfoneSacdoTitlo' => (int) $payer['phone'],
            'cindcdPix' => $c->method === 'boleto' ? 'N' : 'S',
        ];

        $r = $this->send($p, '/boleto-hibrido/cobranca-registro/v1/gerarBoleto', $payload);
        $c->forceFill([
            'external_id' => (string) ($r->json('ctitloCobrCdent') ?: $ourNumber),
            'bank_number' => (string) ($r->json('ctitloCobrCdent') ?: $ourNumber),
            'digitable_line' => $r->json('linhaDig10') ?? $r->json('linhaDigitavel') ?? $r->json('linhaDig'),
            'barcode' => $r->json('cdBarras') ?? $r->json('codBarras10'),
            'pix_payload' => $r->json('wqrcdPdraoMercd') ?: null,
            'remote_status' => 'REGISTRADO',
        ])->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->send($p, '/boleto/cobranca-consulta-titulo/v1/consultar', $this->key($p, $c) + ['status' => 0]);
        $title = $r->json('titulo') ?? $r->json();
        $text = Str::upper(Str::ascii((string) (data_get($title, 'descStatus') ?? data_get($title, 'status') ?? '')));
        $status = match (true) {
            str_contains($text, 'LIQUID') || str_contains($text, 'PAGO') => 'pago',
            str_contains($text, 'BAIXAD') => 'cancelado',
            default => self::openStatus($c),
        };
        $paid = data_get($title, 'valorPagto') ?? data_get($title, 'vlrPagto');

        return new RemoteCharge(
            $status,
            $text,
            $status === 'pago' ? self::parseDate(data_get($title, 'dataPagto') ?? data_get($title, 'dtPagto')) : null,
            $status === 'pago' && $paid ? ((float) $paid > (float) $c->amount * 50 ? (float) $paid / 100 : (float) $paid) : null,
            null,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->send($p, '/boleto/cobranca-baixa/v1/baixar', $this->key($p, $c) + ['codigoBaixa' => 57]);
    }

    private function key(BillingProvider $p, BillingCharge $c): array
    {
        [$base, $branch, $check] = self::cnpjParts($p);

        return [
            'cpfCnpj' => ['cpfCnpj' => $base, 'filial' => $branch, 'controle' => $check],
            'produto' => (int) self::digits($p->credential('carteira')),
            'negociacao' => self::negotiation($p),
            'nossoNumero' => (int) $c->bank_number,
            'sequencia' => 0,
        ];
    }

    /** @return array{0: int, 1: int, 2: int} raiz, filial e dígitos do CNPJ do escritório */
    private static function cnpjParts(BillingProvider $p): array
    {
        $cnpj = str_pad(self::digits($p->credential('cnpj')), 14, '0', STR_PAD_LEFT);

        return [(int) substr($cnpj, 0, 8), (int) substr($cnpj, 8, 4), (int) substr($cnpj, 12, 2)];
    }

    /** Agência (4) + conta (14 posições à direita), como o Bradesco identifica a negociação. */
    private static function negotiation(BillingProvider $p): int
    {
        $agency = str_pad(substr(self::digits($p->credential('agencia')), 0, 4), 4, '0', STR_PAD_LEFT);
        $account = str_pad(self::digits($p->credential('conta')), 14, '0', STR_PAD_LEFT);

        return (int) ($agency.$account);
    }

    private function send(BillingProvider $p, string $path, array $body): Response
    {
        return $this->withCertificate($p, function (array $tls) use ($p, $path, $body) {
            $token = $this->token($p, $tls);
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $nonce = (string) (int) (microtime(true) * 1000);
            $timestamp = now()->format('Y-m-d\TH:i:sP');
            $signature = self::sign($p, "POST\n{$path}\n\n{$json}\n{$token}\n{$nonce}\n{$timestamp}\nSHA256");

            return $this->guard($p, $this->call($p, fn () => $this->http()->withOptions($tls)->withToken($token)->withHeaders([
                'X-Brad-Signature' => $signature,
                'X-Brad-Nonce' => $nonce,
                'X-Brad-Timestamp' => $timestamp,
                'X-Brad-Algorithm' => 'SHA256',
                'access-token' => (string) $p->client_id,
            ])->withBody($json, 'application/json')->post($this->host($p).$path)));
        });
    }

    private function token(BillingProvider $p, array $tls): string
    {
        $aud = $this->host($p).'/auth/server/v1.1/token';
        $now = time();
        $jwt = self::jwt($p, ['aud' => $aud, 'sub' => (string) $p->client_id, 'iat' => $now, 'exp' => $now + 3600, 'jti' => (string) ($now * 1000), 'ver' => '1.1']);

        return $this->oauthToken($p, fn () => $this->http()->withOptions($tls)->asForm()->post($aud, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));
    }

    private static function jwt(BillingProvider $p, array $claims): string
    {
        $segments = [self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES))];
        $segments[] = self::sign($p, implode('.', $segments));

        return implode('.', $segments);
    }

    private static function sign(BillingProvider $p, string $data): string
    {
        $key = openssl_pkey_get_private((string) $p->private_key);
        if (! $key || ! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new BillingException('Não foi possível assinar a chamada ao Bradesco: confira a chave privada (.key) enviada.');
        }

        return self::b64($signature);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function host(BillingProvider $p): string
    {
        return self::HOSTS[$p->environment] ?? self::HOSTS['sandbox'];
    }
}
