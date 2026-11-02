<?php

namespace App\Support\Ai;

use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Arquivo de certificados usado nas chamadas HTTPS aos provedores de IA.
 *
 * No Windows, antivírus com "verificação HTTPS" (Avast, AVG, Kaspersky, ESET...) e proxies
 * corporativos trocam o certificado dos sites pelo deles, assinado por uma autoridade que
 * só existe no repositório de certificados do Windows. O PHP não lê esse repositório, então
 * a conexão falha ("self-signed certificate in certificate chain"). Aqui exportamos as
 * autoridades raiz do Windows e juntamos às da Mozilla (composer/ca-bundle), sem desligar
 * a verificação SSL.
 */
class CertificateStore
{
    public static function path(): string
    {
        return storage_path('app/ai-cacert.pem');
    }

    /** Permite simular o Windows nos testes. */
    public static ?bool $windows = null;

    public static function isWindows(): bool
    {
        return self::$windows ?? PHP_OS_FAMILY === 'Windows';
    }

    /** Gera o arquivo combinado. Retorna quantos certificados do Windows foram incluídos. */
    public static function buildFromWindows(): int
    {
        $script = '$ErrorActionPreference="SilentlyContinue";'
            .'$c=@(Get-ChildItem Cert:\\LocalMachine\\Root)+@(Get-ChildItem Cert:\\CurrentUser\\Root)+@(Get-ChildItem Cert:\\LocalMachine\\CA);'
            .'foreach($x in $c){"-----BEGIN CERTIFICATE-----";[Convert]::ToBase64String($x.RawData,"InsertLineBreaks");"-----END CERTIFICATE-----"}';

        $result = Process::timeout(60)->run(['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script]);
        $output = str_replace("\r\n", "\n", $result->output());
        $count = substr_count($output, '-----BEGIN CERTIFICATE-----');

        if (! $result->successful() || $count === 0) {
            throw new AiException('Não foi possível ler os certificados do Windows (PowerShell). '.trim($result->errorOutput()));
        }

        $mozilla = (string) @file_get_contents(CaBundle::getBundledCaBundlePath());
        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), rtrim($mozilla)."\n\n# Autoridades raiz do Windows (antivírus/proxy)\n".$output);

        return $count;
    }

    /** Arquivo gerado, se existir. */
    public static function generated(): ?string
    {
        return is_readable(self::path()) ? self::path() : null;
    }

    /** Erro de certificado que os certificados do Windows podem resolver. */
    public static function isCertificateError(string $error): bool
    {
        return (bool) preg_match('/cURL error (60|77)\b|SSL certificate|self[- ]signed certificate|unable to get local issuer/i', $error);
    }
}
