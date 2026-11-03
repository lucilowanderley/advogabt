<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Banco Inter, API Cobrança v3 (boleto com Pix). Autenticação OAuth2 (client credentials)
 * com certificado mTLS da integração. A emissão é assíncrona: a criação devolve o
 * codigoSolicitacao e a linha digitável/Pix aparecem na consulta seguinte.
 */
class InterDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://cdpj-sandbox.partners.uatinter.co',
        'producao' => 'https://cdpj.partners.bancointer.com.br',
    ];

    public const SCOPES = 'boleto-cobranca.read boleto-cobranca.write';

    public function test(BillingProvider $p): string
    {
        Cache::forget($this->tokenKey($p));
        $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, 'cobranca/v3/cobrancas'), [
            'dataInicial' => today()->subDays(7)->format('Y-m-d'),
            'dataFinal' => today()->format('Y-m-d'),
            'itensPorPagina' => 1,
        ]));

        return 'Banco Inter conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').').';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $doc = self::payerDocument($payer);
        $missing = array_keys(array_filter([
            'endereço' => blank($payer->address),
            'cidade' => blank($payer->city),
            'UF' => blank($payer->state),
            'CEP' => strlen(self::digits($payer->zip_code)) !== 8,
        ]));
        if ($missing) {
            throw new BillingException('O Banco Inter exige o endereço completo do pagador. Complete na ficha de '.$payer->name.': '.implode(', ', $missing).'.');
        }
        $phone = self::digits($payer->whatsapp ?: $payer->phone);
        if (strlen($phone) > 11) {
            $phone = substr($phone, -11);
        }

        $payload = [
            'seuNumero' => substr('ADV'.$c->id, 0, 15),
            'valorNominal' => round((float) $c->amount, 2),
            'dataVencimento' => $c->due_date->format('Y-m-d'),
            'numDiasAgenda' => 30,
            'pagador' => array_filter([
                'cpfCnpj' => $doc,
                'tipoPessoa' => strlen($doc) === 14 ? 'JURIDICA' : 'FISICA',
                'nome' => Str::limit($payer->name, 100, ''),
                'endereco' => Str::limit((string) $payer->address, 90, ''),
                'cidade' => Str::limit((string) $payer->city, 60, ''),
                'uf' => strtoupper((string) $payer->state),
                'cep' => self::digits($payer->zip_code),
                'email' => $payer->email ?: null,
                'ddd' => strlen($phone) >= 10 ? substr($phone, 0, 2) : null,
                'telefone' => strlen($phone) >= 10 ? substr($phone, 2) : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'mensagem' => ['linha1' => Str::limit($c->description, 78, '')],
        ];
        if ((float) $p->fine_percent > 0) {
            $payload['multa'] = ['codigo' => 'PERCENTUAL', 'taxa' => (float) $p->fine_percent];
        }
        if ((float) $p->interest_percent > 0) {
            $payload['mora'] = ['codigo' => 'TAXAMENSAL', 'taxa' => (float) $p->interest_percent];
        }

        $r = $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, 'cobranca/v3/cobrancas'), $payload));
        $id = (string) $r->json('codigoSolicitacao');
        if ($id === '') {
            throw new BillingException('O Banco Inter não devolveu o código da cobrança.');
        }
        $c->forceFill(['external_id' => $id, 'remote_status' => 'EM_PROCESSAMENTO'])->save();

        // A linha digitável costuma sair em segundos; se ainda não saiu, a rotina busca depois.
        try {
            $remote = $this->status($p, $c);
            $c->forceFill($remote->fields + ['remote_status' => $remote->remoteStatus])->save();
        } catch (\Throwable) {
        }
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, 'cobranca/v3/cobrancas/'.$c->external_id)));
        $remote = (string) $r->json('cobranca.situacao');
        $status = match ($remote) {
            'RECEBIDO', 'MARCADO_RECEBIDO' => 'pago',
            'ATRASADO', 'PROTESTO' => 'vencido',
            'CANCELADO', 'EXPIRADO' => 'cancelado',
            'FALHA_EMISSAO' => 'erro',
            default => 'pendente', // A_RECEBER, EM_PROCESSAMENTO
        };
        $fields = array_filter([
            'digitable_line' => $r->json('boleto.linhaDigitavel'),
            'barcode' => $r->json('boleto.codigoBarras'),
            'pix_payload' => $r->json('pix.pixCopiaECola'),
        ]);
        $origin = (string) $r->json('cobranca.origemRecebimento');

        return new RemoteCharge(
            $status,
            $remote,
            $status === 'pago' ? ($r->json('cobranca.dataSituacao') ?? null) : null,
            $status === 'pago' && $r->json('cobranca.valorTotalRecebido') !== null ? (float) $r->json('cobranca.valorTotalRecebido') : null,
            $status === 'pago' ? match ($origin) {
                'PIX' => 'pix', 'BOLETO' => 'boleto', default => $remote === 'MARCADO_RECEBIDO' ? 'outro' : null
            } : null,
            $fields,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->send($p, fn (PendingRequest $h) => $h->asJson()->post($this->url($p, "cobranca/v3/cobrancas/{$c->external_id}/cancelar"), [
            'motivoCancelamento' => 'Cancelada pelo escritório',
        ]));
    }

    public function pdf(BillingProvider $p, BillingCharge $c): ?string
    {
        $r = $this->send($p, fn (PendingRequest $h) => $h->get($this->url($p, "cobranca/v3/cobrancas/{$c->external_id}/pdf")));
        $pdf = base64_decode((string) $r->json('pdf'), true);
        if (! $pdf || ! str_starts_with($pdf, '%PDF')) {
            throw new BillingException('O Banco Inter não devolveu o PDF do boleto (ele pode ainda estar em processamento).');
        }

        return $pdf;
    }

    /** Cadastra no Inter o endereço de aviso (exige o sistema publicado com HTTPS). */
    public function registerWebhook(BillingProvider $p): void
    {
        $this->send($p, fn (PendingRequest $h) => $h->asJson()->put($this->url($p, 'cobranca/v3/cobrancas/webhook'), ['webhookUrl' => $p->webhook_url]));
    }

    // --- Apoio -------------------------------------------------------------------

    /** Chamada autenticada com o certificado da integração (arquivos temporários apagados ao final). */
    private function send(BillingProvider $p, callable $request): Response
    {
        return $this->withCertificate($p, function (array $tls) use ($p, $request) {
            $token = $this->token($p, $tls);
            $h = $this->http()->withOptions($tls)->withToken($token);
            if ($p->account_number) {
                $h = $h->withHeaders(['x-conta-corrente' => self::digits($p->account_number)]);
            }

            return $this->guard($p, $this->call($p, fn () => $request($h)));
        });
    }

    private function token(BillingProvider $p, array $tls): string
    {
        $cached = Cache::get($this->tokenKey($p));
        if ($cached) {
            return $cached;
        }
        $r = $this->call($p, fn () => $this->http()->withOptions($tls)->asForm()->post($this->url($p, 'oauth/v2/token'), [
            'client_id' => (string) $p->client_id,
            'client_secret' => (string) $p->client_secret,
            'grant_type' => 'client_credentials',
            'scope' => self::SCOPES,
        ]));
        if (! $r->successful()) {
            $detail = $r->json('error_description') ?? $r->json('title') ?? $r->json('error') ?? Str::limit(strip_tags($r->body()), 200);
            throw new BillingException('O Banco Inter recusou a autenticação (HTTP '.$r->status().': '.(is_string($detail) ? $detail : json_encode($detail)).'). '
                .'Confira o Client ID, o Client Secret, o certificado e a chave, e se a integração tem as permissões de boleto e é do ambiente escolhido.');
        }
        $token = (string) $r->json('access_token');
        if ($token === '') {
            throw new BillingException('O Banco Inter não devolveu o token de acesso.');
        }
        Cache::put($this->tokenKey($p), $token, now()->addSeconds(max(60, (int) $r->json('expires_in', 3600) - 120)));

        return $token;
    }

    private function withCertificate(BillingProvider $p, callable $fn): mixed
    {
        $dir = storage_path('app/private/billing-tmp');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $cert = $dir.'/'.Str::random(24).'.crt';
        $key = $dir.'/'.Str::random(24).'.key';
        file_put_contents($cert, (string) $p->certificate);
        file_put_contents($key, (string) $p->private_key);
        @chmod($cert, 0600);
        @chmod($key, 0600);
        try {
            return $fn(['cert' => $cert, 'ssl_key' => $key]);
        } finally {
            @unlink($cert);
            @unlink($key);
        }
    }

    private function tokenKey(BillingProvider $p): string
    {
        return 'inter-token-'.$p->id.'-'.md5($p->environment.'|'.$p->client_id.'|'.$p->updated_at);
    }

    private function url(BillingProvider $p, string $path): string
    {
        return (self::HOSTS[$p->environment] ?? self::HOSTS['sandbox']).'/'.ltrim($path, '/');
    }
}
