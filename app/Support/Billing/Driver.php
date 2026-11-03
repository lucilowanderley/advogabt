<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use App\Support\Ai\LlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    protected static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
