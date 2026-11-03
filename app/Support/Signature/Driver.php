<?php

namespace App\Support\Signature;

use App\Models\SignatureProvider;
use App\Models\SignatureRequest;
use App\Support\Ai\LlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Base das plataformas de assinatura: HTTP com os certificados do Windows e erros em português. */
abstract class Driver
{
    /** Confere a conta. Devolve uma descrição curta. */
    abstract public function test(SignatureProvider $p): string;

    /** Envia o PDF e os signatários; grava os ids da plataforma no pedido e nos signatários. */
    abstract public function send(SignatureProvider $p, SignatureRequest $r, string $pdf, ?string $webhookUrl): void;

    abstract public function status(SignatureProvider $p, SignatureRequest $r): RemoteStatus;

    /** PDF assinado (com a folha de assinaturas da plataforma). */
    abstract public function downloadSigned(SignatureProvider $p, SignatureRequest $r): string;

    abstract public function cancel(SignatureProvider $p, SignatureRequest $r): void;

    protected function http(int $timeout = 60): PendingRequest
    {
        return Http::timeout($timeout)->connectTimeout(10)->withOptions(['verify' => LlmClient::caBundle()])->acceptJson();
    }

    protected function call(SignatureProvider $p, callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            // A D4Sign leva as chaves na URL: nunca mostre a query string.
            $message = preg_replace('/\?\S*/', '', $e->getMessage());
            throw new SignatureException('Não foi possível conectar à '.$p->label.': '.Str::limit($message, 300).' Verifique a internet do servidor (e o antivírus que inspeciona HTTPS).', 0, $e);
        }
    }

    protected function guard(SignatureProvider $p, Response $r): Response
    {
        if ($r->successful()) {
            return $r;
        }
        $detail = $r->json('errors.0.detail') ?? $r->json('errors.0.title') ?? $r->json('message') ?? $r->json('mensagem_pt') ?? $r->json('error')
            ?? Str::limit(strip_tags($r->body()), 300);
        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $hint = match ($r->status()) {
            401, 403 => ' Confira a chave de acesso e se ela é do mesmo ambiente escolhido (testes ou produção).',
            404 => ' O documento ou o cofre não foi encontrado na conta.',
            422 => ' A plataforma recusou os dados enviados (confira e-mails, telefones e CPF dos signatários).',
            429 => ' Limite de chamadas atingido; tente de novo em alguns minutos.',
            default => '',
        };

        throw new SignatureException("A {$p->label} respondeu HTTP {$r->status()}: {$detail}.{$hint}");
    }

    /** Baixa um arquivo de uma URL temporária devolvida pela plataforma. */
    protected function fetchFile(SignatureProvider $p, string $url): string
    {
        $r = $this->guard($p, $this->call($p, fn () => Http::timeout(120)->connectTimeout(10)->withOptions(['verify' => LlmClient::caBundle()])->get($url)));
        $body = $r->body();
        if (! str_starts_with($body, '%PDF')) {
            throw new SignatureException('A '.$p->label.' não devolveu um PDF.');
        }

        return $body;
    }
}
