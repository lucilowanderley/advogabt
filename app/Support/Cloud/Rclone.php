<?php

namespace App\Support\Cloud;

use App\Models\CloudStorage;
use App\Support\Ai\LlmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Conexão rápida pelo rclone (mesmo método do armaria-digital): o administrador roda
 * `rclone authorize "drive"` (ou "onedrive") no PowerShell, entra na conta pelo navegador
 * e cola aqui o token. Não é preciso criar app no Google Cloud nem no Microsoft Entra.
 *
 * O token pertence ao app público do rclone, então só o próprio rclone sabe renová-lo:
 * quando vence, rodamos um comando leve com uma configuração temporária e lemos de volta
 * o token novo que ele gravou. As demais chamadas vão direto às APIs com o token de acesso.
 */
class Rclone
{
    /** Caminho do rclone: pasta do sistema, Laragon ou PATH. */
    public static function path(): ?string
    {
        $exe = self::isWindows() ? 'rclone.exe' : 'rclone';
        $candidates = array_filter([
            storage_path('app/bin/'.$exe),
            self::isWindows() ? 'C:\\laragon\\bin\\rclone\\rclone.exe' : null,
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $which = Process::run(self::isWindows() ? ['where', 'rclone'] : ['which', 'rclone']);
        $found = trim(strtok($which->output(), "\r\n") ?: '');

        return $which->successful() && $found !== '' ? $found : null;
    }

    public static function version(): ?string
    {
        $bin = self::path();
        if (! $bin) {
            return null;
        }
        $result = Process::timeout(20)->run([$bin, 'version']);

        return $result->successful() ? trim(strtok($result->output(), "\r\n")) : null;
    }

    /** Linha para rodar no PowerShell (ou terminal) e gerar o token. */
    public static function authorizeCommand(CloudStorage $s): ?string
    {
        $bin = self::path();
        if (! $bin) {
            return null;
        }

        return (self::isWindows() ? '& "'.$bin.'"' : escapeshellarg($bin)).' authorize "'.$s->rcloneType().'"';
    }

    /** Baixa o rclone oficial para storage/app/bin. */
    public static function install(): string
    {
        $platform = match (PHP_OS_FAMILY) {
            'Windows' => 'windows-amd64',
            'Darwin' => 'osx-amd64',
            default => 'linux-amd64',
        };
        $dir = storage_path('app/bin');
        File::ensureDirectoryExists($dir);
        $zipPath = $dir.DIRECTORY_SEPARATOR.'rclone-download.zip';

        $response = Http::timeout(300)->withOptions(['verify' => LlmClient::caBundle()])->sink($zipPath)
            ->get("https://downloads.rclone.org/rclone-current-{$platform}.zip");
        if (! $response->successful()) {
            @unlink($zipPath);
            throw new CloudException('Falha ao baixar o rclone (HTTP '.$response->status().'). Verifique a internet ou baixe em https://rclone.org/downloads/ e coloque o rclone.exe em C:\\laragon\\bin\\rclone\\.');
        }

        $exe = self::isWindows() ? 'rclone.exe' : 'rclone';
        $target = $dir.DIRECTORY_SEPARATOR.$exe;
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);
            throw new CloudException('O arquivo baixado do rclone está corrompido.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (basename((string) $zip->getNameIndex($i)) === $exe) {
                file_put_contents($target, $zip->getStream($zip->getNameIndex($i)));
                break;
            }
        }
        $zip->close();
        @unlink($zipPath);
        if (! is_file($target)) {
            throw new CloudException('O rclone não foi encontrado dentro do arquivo baixado.');
        }
        @chmod($target, 0755);

        return $target;
    }

    /**
     * Extrai o token do texto colado (aceita a saída inteira do "rclone authorize", com as setas).
     *
     * @return array{access_token: string, refresh_token: string, expiry: ?string}
     */
    public static function parseToken(string $pasted): array
    {
        if (! preg_match('/\{.*\}/s', $pasted, $m)) {
            throw new CloudException('Não encontrei o token no texto colado. Copie tudo o que aparece entre as setas ---> e <---.');
        }
        $token = json_decode($m[0], true);
        if (! is_array($token) || empty($token['access_token']) || empty($token['refresh_token'])) {
            throw new CloudException('Token inválido: faltam access_token ou refresh_token. Gere de novo com o comando mostrado na tela.');
        }

        return $token;
    }

    /** Guarda o token (do "rclone authorize" ou renovado pelo rclone) na conexão. */
    public static function storeToken(CloudStorage $s, array $token): void
    {
        $expiry = ! empty($token['expiry']) ? Carbon::parse($token['expiry']) : now()->addMinutes(50);
        $s->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $s->refresh_token,
            'token_expires_at' => $expiry,
        ])->save();
    }

    /** Renova o token pelo rclone (ele grava o token novo no arquivo de configuração temporário). */
    public static function refresh(CloudStorage $s): void
    {
        $bin = self::path();
        if (! $bin) {
            throw new CloudException('O rclone não está instalado neste servidor, e ele é necessário para renovar a conexão. Use "Instalar rclone" em Integrações › Arquivos na nuvem.');
        }

        $conf = storage_path('app/.rclone-'.Str::random(12).'.conf');
        File::ensureDirectoryExists(dirname($conf));
        file_put_contents($conf, self::config($s, expired: true));
        try {
            // "about" é leve e obriga o rclone a usar (e portanto renovar) o token.
            $result = Process::timeout(60)->run([$bin, '--config', $conf, '--contimeout', '20s', '--timeout', '40s', '--retries', '1', 'about', 'nuvem:', '--json']);
            $token = self::readToken($conf);
        } finally {
            @unlink($conf);
        }

        if ($token && $token['access_token'] !== $s->access_token) {
            self::storeToken($s, $token);

            return;
        }
        $error = trim($result->errorOutput()) ?: trim($result->output());
        $lines = implode(' | ', array_slice(array_filter(explode("\n", $error)), -2));
        if (str_contains($lines, 'invalid_grant') || str_contains($lines, 'AADSTS70000') || str_contains($lines, 'AADSTS700082')) {
            throw new CloudException('invalid_grant: '.$lines);
        }

        throw new CloudException('O rclone não conseguiu renovar a conexão: '.(Str::limit($lines, 300) ?: 'sem detalhes'));
    }

    private static function config(CloudStorage $s, bool $expired): string
    {
        $token = json_encode([
            'access_token' => (string) $s->access_token,
            'token_type' => 'Bearer',
            'refresh_token' => (string) $s->refresh_token,
            // vencido de propósito: o rclone renova logo na primeira chamada
            'expiry' => ($expired ? now()->subMinute() : ($s->token_expires_at ?? now()))->toRfc3339String(),
        ], JSON_UNESCAPED_SLASHES);

        $lines = $s->driver === 'google'
            ? ['type' => 'drive', 'scope' => 'drive', 'token' => $token]
            : ['type' => 'onedrive', 'token' => $token, 'drive_id' => (string) $s->drive_id, 'drive_type' => $s->drive_type ?: 'personal'];

        $ini = "[nuvem]\n";
        foreach ($lines as $key => $value) {
            $ini .= $key.' = '.str_replace(["\r", "\n"], '', (string) $value)."\n";
        }

        return $ini;
    }

    private static function readToken(string $conf): ?array
    {
        if (! is_file($conf)) {
            return null;
        }
        foreach (file($conf, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^token\s*=\s*(\{.*\})\s*$/', $line, $m)) {
                $token = json_decode($m[1], true);

                return ! empty($token['access_token']) ? $token : null;
            }
        }

        return null;
    }

    private static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
