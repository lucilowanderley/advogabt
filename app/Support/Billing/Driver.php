<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use App\Support\Ai\LlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Base das plataformas de cobrança: HTTP com os certificados do Windows e erros em português. */
abstract class Driver
{
    /** Confere a conta. Devolve uma descrição curta. */
    abstract public function test(BillingProvider $p): string;

    /** Registra a cobrança na plataforma; grava o id externo e o que já vier pronto (links, linha digitável, Pix). */
    abstract public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void;

    abstract public function status(BillingProvider $p, BillingCharge $c): RemoteCharge;

    abstract public function cancel(BillingProvider $p, BillingCharge $c): void;

    /** PDF do boleto, quando a plataforma não oferece um link público. */
    public function pdf(BillingProvider $p, BillingCharge $c): ?string
    {
        return null;
    }

    protected function http(int $timeout = 40): PendingRequest
    {
        return Http::timeout($timeout)->connectTimeout(10)->withOptions(['verify' => LlmClient::caBundle()])->acceptJson();
    }

    protected function call(BillingProvider $p, callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw new BillingException('Não foi possível conectar ao '.$p->label.': '.Str::limit($e->getMessage(), 300)
                .' Verifique a internet do servidor (e o antivírus que inspeciona HTTPS).', 0, $e);
        }
    }

    protected function guard(BillingProvider $p, Response $r): Response
    {
        if ($r->successful()) {
            return $r;
        }
        $detail = $r->json('errors.0.description') ?? $r->json('violacoes.0.razao') ?? $r->json('detail') ?? $r->json('title')
            ?? $r->json('message') ?? $r->json('error_description') ?? $r->json('error') ?? Str::limit(strip_tags($r->body()), 300);
        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $extra = $r->json('violacoes.0.propriedade') ? ' ('.$r->json('violacoes.0.propriedade').')' : '';
        $hint = match ($r->status()) {
            401, 403 => ' Confira as credenciais e se são do mesmo ambiente escolhido (testes ou produção).',
            404 => ' A cobrança não foi encontrada na conta.',
            429 => ' Limite de chamadas atingido; tente de novo em alguns minutos.',
            default => '',
        };

        throw new BillingException("O {$p->label} respondeu HTTP {$r->status()}: {$detail}{$extra}.{$hint}");
    }

    /** Dados do pagador que as plataformas exigem. */
    protected static function payerDocument(Contact $payer): string
    {
        $doc = preg_replace('/\D+/', '', (string) $payer->document);
        if (! in_array(strlen($doc), [11, 14], true)) {
            throw new BillingException('Cadastre o CPF ou CNPJ de '.$payer->name.' na ficha do contato antes de gerar a cobrança.');
        }

        return $doc;
    }

    /**
     * Pagador normalizado. Os bancos exigem o endereço completo para registrar o boleto.
     *
     * @return array{doc: string, pj: bool, name: string, email: ?string, ddd: ?string, phone: ?string, address: string, district: string, city: string, uf: string, cep: string}
     */
    protected static function payer(Contact $payer, string $bank): array
    {
        $doc = self::payerDocument($payer);
        $missing = array_keys(array_filter([
            'endereço' => blank($payer->address),
            'cidade' => blank($payer->city),
            'UF' => blank($payer->state),
            'CEP' => strlen(self::digits($payer->zip_code)) !== 8,
        ]));
        if ($missing) {
            throw new BillingException('O '.$bank.' exige o endereço completo do pagador. Complete na ficha de '.$payer->name.': '.implode(', ', $missing).'.');
        }
        $phone = self::digits($payer->whatsapp ?: $payer->phone);
        if (strlen($phone) > 11) {
            $phone = substr($phone, -11);
        }
        // A ficha não tem campo de bairro: usa o trecho depois do último " - " do endereço, se houver.
        $address = trim((string) $payer->address);
        $district = str_contains($address, ' - ') ? trim(substr($address, strrpos($address, ' - ') + 3)) : '';

        return [
            'doc' => $doc,
            'pj' => strlen($doc) === 14,
            'name' => Str::limit($payer->name, 60, ''),
            'email' => $payer->email ?: null,
            'ddd' => strlen($phone) >= 10 ? substr($phone, 0, 2) : null,
            'phone' => strlen($phone) >= 10 ? substr($phone, 2) : null,
            'address' => Str::limit($address, 60, ''),
            'district' => Str::limit($district !== '' ? $district : 'Centro', 30, ''),
            'city' => Str::limit((string) $payer->city, 40, ''),
            'uf' => strtoupper((string) $payer->state),
            'cep' => self::digits($payer->zip_code),
        ];
    }

    /** "Seu número" da cobrança, o mesmo no banco e no arquivo de retorno CNAB. */
    public static function seuNumero(BillingCharge $c): string
    {
        return 'ADV'.$c->id;
    }

    protected static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    /** Arquivos temporários com o certificado/chave (mTLS); apagados ao final. Sem certificado, opções vazias. */
    protected function withCertificate(BillingProvider $p, callable $fn): mixed
    {
        if (blank($p->certificate) || blank($p->private_key)) {
            return $fn([]);
        }
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

    /** Token OAuth guardado em cache até perto de expirar. $request devolve a resposta do servidor de autenticação. */
    protected function oauthToken(BillingProvider $p, callable $request): string
    {
        $key = $this->tokenKey($p);
        if ($cached = Cache::get($key)) {
            return $cached;
        }
        $r = $this->call($p, $request);
        if (! $r->successful()) {
            $detail = $r->json('error_description') ?? $r->json('message') ?? $r->json('title') ?? $r->json('error') ?? Str::limit(strip_tags($r->body()), 200);
            throw new BillingException('O '.$p->label.' recusou a autenticação (HTTP '.$r->status().': '.(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)).'). '
                .'Confira as credenciais, o certificado e se a aplicação tem acesso à API de cobrança no ambiente escolhido.');
        }
        $token = (string) ($r->json('access_token') ?? $r->json('accessToken'));
        if ($token === '') {
            throw new BillingException('O '.$p->label.' não devolveu o token de acesso.');
        }
        Cache::put($key, $token, now()->addSeconds(max(60, (int) ($r->json('expires_in') ?? 300) - 60)));

        return $token;
    }

    protected function forgetToken(BillingProvider $p): void
    {
        Cache::forget($this->tokenKey($p));
    }

    private function tokenKey(BillingProvider $p): string
    {
        return 'billing-token-'.$p->driver.'-'.md5($p->id.'|'.$p->environment.'|'.$p->client_id.'|'.$p->updated_at);
    }

    /** Data de pagamento em vários formatos (2026-11-03, 03.11.2026, 03/11/2026, 03112026). */
    protected static function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        $format = match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $value) => ['Y-m-d', 10],
            (bool) preg_match('/^\d{2}\.\d{2}\.\d{4}/', $value) => ['d.m.Y', 10],
            (bool) preg_match('/^\d{2}\/\d{2}\/\d{4}/', $value) => ['d/m/Y', 10],
            (bool) preg_match('/^\d{8}$/', $value) && ! preg_match('/^0+$/', $value) => ['dmY', 8],
            default => null,
        };
        if (! $format) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!'.$format[0], substr($value, 0, $format[1]))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Pendente ou vencido conforme a data. */
    protected static function openStatus(BillingCharge $c): string
    {
        return $c->due_date->lt(today()) ? 'vencido' : 'pendente';
    }
}
