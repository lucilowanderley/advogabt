<?php

namespace App\Support\Courts;

use App\Support\Ai\CertificateStore;
use App\Support\Ai\LlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Chamadas HTTPS às APIs públicas do CNJ, com o mesmo tratamento de certificados do Assistente IA. */
class CourtHttp
{
    public static function request(int $timeout = 30): PendingRequest
    {
        return Http::timeout($timeout)->connectTimeout(10)
            ->withOptions(['verify' => LlmClient::caBundle()])
            ->withUserAgent('ADVOGABT/1.0 (gestao juridica)')
            ->acceptJson();
    }

    /** Executa a chamada; no Windows com antivírus que inspeciona HTTPS, importa os certificados do Windows e tenta de novo. */
    public static function send(string $service, callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            if (CertificateStore::isWindows() && CertificateStore::isCertificateError($e->getMessage())
                && Cache::add('court-windows-certs-imported', true, now()->addMinutes(10))) {
                try {
                    CertificateStore::buildFromWindows();

                    return $send();
                } catch (\Throwable $retry) {
                    $e = $retry instanceof ConnectionException ? $retry : $e;
                }
            }

            $detail = preg_replace(['/\s*\(see https?:\/\/[^)]+\)/', '/\s+for https?:\/\/\S+/'], '', $e->getMessage());
            throw new CourtException("Não foi possível conectar ao {$service}: ".Str::limit(trim($detail), 200).'.'
                .' Verifique a internet do servidor (e o antivírus que inspeciona HTTPS).', CourtException::CONNECTION, $e);
        }
    }

    public static function detail(Response $r): string
    {
        $detail = $r->json('message') ?? $r->json('error.reason') ?? $r->json('error.type') ?? $r->json('error') ?? Str::limit(trim(strip_tags($r->body())), 250);

        return is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : (string) $detail;
    }
}
