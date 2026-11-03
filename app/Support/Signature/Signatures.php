<?php

namespace App\Support\Signature;

use App\Models\CloudStorage;
use App\Models\SignatureProvider;
use App\Models\SignatureRequest;
use App\Support\Cloud\CloudFolders;
use App\Support\Integrations\Webhooks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Assinatura digital: envia o PDF para a plataforma, acompanha a situação (pelo aviso da
 * plataforma quando o sistema está publicado, ou consultando de tempos em tempos pela rotina
 * das integrações no Laragon), guarda o PDF assinado e o copia para a pasta na nuvem.
 */
class Signatures
{
    /** Pasta criada dentro da pasta do processo/cliente na nuvem. */
    public const CLOUD_FOLDER = 'Documentos assinados';

    public static function driver(string $name): Driver
    {
        return match ($name) {
            'clicksign' => app(ClickSignDriver::class),
            'd4sign' => app(D4SignDriver::class),
            default => throw new SignatureException('Plataforma de assinatura desconhecida: '.$name),
        };
    }

    public static function provider(SignatureRequest $r): SignatureProvider
    {
        $p = SignatureProvider::forDriver($r->provider);
        if (! $p?->isConfigured()) {
            throw new SignatureException('A '.SignatureProvider::label($r->provider).' não está configurada em Integrações › Assinatura digital.');
        }

        return $p;
    }

    /** Endereço local (Laragon, rede interna): as plataformas não conseguem chamar o sistema. */
    public static function isLocalUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host === '' || ! str_contains($host, '.') || (bool) preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)|\.(test|local|localhost|lan|home|internal)$/', $host);
    }

    // --- Envio ---------------------------------------------------------------

    public static function send(SignatureRequest $r): void
    {
        $r->loadMissing('signers');
        if ($r->isManual()) {
            $r->forceFill(['status' => 'enviado', 'sent_at' => now(), 'last_error' => null, 'next_check_at' => null])->save();

            return;
        }
        try {
            $p = self::provider($r);
            $pdf = Storage::disk('local')->get((string) $r->file_path);
            if (! $pdf) {
                throw new SignatureException('O PDF do documento não foi encontrado no servidor.');
            }
            $webhook = self::isLocalUrl($p->webhook_url) ? null : $p->webhook_url;
            self::driver($r->provider)->send($p, $r, $pdf, $webhook);
            $r->forceFill(['status' => 'enviado', 'sent_at' => now(), 'last_error' => null, 'next_check_at' => now()->addMinutes(30)])->save();
        } catch (\Throwable $e) {
            $message = $e instanceof SignatureException ? $e->getMessage() : 'Erro inesperado: '.$e->getMessage();
            $r->forceFill(['status' => 'erro', 'last_error' => $message, 'next_check_at' => null])->save();
            if (! $e instanceof SignatureException) {
                Log::warning('Assinatura digital: falha no envio #'.$r->id.': '.$e->getMessage());
            }

            throw $e instanceof SignatureException ? $e : new SignatureException($message, 0, $e);
        }
        self::forget();
    }

    public static function cancel(SignatureRequest $r): void
    {
        if (! $r->isManual() && $r->external_id && $r->status === 'enviado') {
            self::driver($r->provider)->cancel(self::provider($r), $r);
        }
        $r->forceFill(['status' => 'cancelado', 'next_check_at' => null])->save();
        self::forget();
    }

    // --- Acompanhamento ------------------------------------------------------

    /** Consulta a plataforma e atualiza o pedido. Devolve true se a situação mudou. */
    public static function refresh(SignatureRequest $r): bool
    {
        if ($r->isManual() || ! $r->external_id) {
            return false;
        }
        $r->loadMissing('signers');
        $remote = self::driver($r->provider)->status(self::provider($r), $r);

        foreach ($r->signers as $s) {
            $info = $remote->signers[strtolower($s->email)] ?? null;
            if ($info && $s->status !== $info['status']) {
                $s->forceFill([
                    'status' => $info['status'],
                    'signed_at' => $info['status'] === 'assinado' ? self::date($info['signed_at']) ?? now() : null,
                ])->save();
            }
        }

        $changed = $remote->status !== $r->status;
        $r->forceFill(['last_checked_at' => now(), 'last_error' => null, 'next_check_at' => self::nextCheck($r)]);
        if ($remote->status === 'assinado' && $r->status !== 'assinado') {
            self::complete($r);
        } elseif ($changed) {
            $r->forceFill(['status' => $remote->status, 'next_check_at' => in_array($remote->status, SignatureRequest::OPEN, true) ? $r->next_check_at : null]);
            if ($remote->status === 'recusado') {
                self::dispatch('assinatura.recusada', $r);
            }
        }
        $r->save();
        self::forget();

        return $changed;
    }

    /** Documento assinado por todos: guarda o PDF final, copia para a nuvem e avisa os webhooks. */
    public static function complete(SignatureRequest $r, ?string $signedPdf = null): void
    {
        $first = $r->completed_at === null;
        $r->forceFill(['status' => 'assinado', 'completed_at' => $r->completed_at ?? now(), 'next_check_at' => null]);
        try {
            $signedPdf ??= self::driver($r->provider)->downloadSigned(self::provider($r), $r);
        } catch (\Throwable $e) {
            $r->last_error = 'Assinado, mas o PDF final não foi baixado: '.$e->getMessage().' Use "Atualizar situação" para tentar de novo.';
            $r->next_check_at = now()->addHour();
        }
        if ($signedPdf) {
            $path = $r->directory().'/assinado.pdf';
            Storage::disk('local')->put($path, $signedPdf);
            $r->signed_path = $path;
            $r->last_error = null;
            $r->next_check_at = null;
            $r->save();
            self::copyToCloud($r);
        }
        $r->signers()->where('status', 'pendente')->update(['status' => 'assinado', 'signed_at' => now()]);
        $r->save();
        if ($first) {
            self::dispatch('assinatura.concluida', $r);
        }
    }

    /** Rotina: consulta os documentos que aguardam assinatura. */
    public static function checkPending(int $limit = 20): int
    {
        $count = 0;
        $pending = SignatureRequest::open()->where('provider', '!=', 'manual')->whereNotNull('external_id')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderBy('next_check_at')->limit($limit)->get();
        foreach ($pending as $r) {
            try {
                if (self::refresh($r)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                $r->forceFill(['last_checked_at' => now(), 'last_error' => $e->getMessage(), 'next_check_at' => now()->addHours(2)])->save();
            }
        }
        // Pedidos assinados cujo PDF final não veio na primeira tentativa.
        SignatureRequest::where('status', 'assinado')->whereNull('signed_path')->where('provider', '!=', 'manual')
            ->whereNotNull('next_check_at')->where('next_check_at', '<=', now())->limit(5)->get()
            ->each(function (SignatureRequest $r) {
                try {
                    self::complete($r);
                } catch (\Throwable) {
                    // tenta na próxima rotina
                }
            });

        return $count;
    }

    public static function hasWork(): bool
    {
        return Cache::remember('signatures-pending', now()->addMinutes(5), fn () => SignatureRequest::open()->where('provider', '!=', 'manual')->exists());
    }

    public static function forget(): void
    {
        Cache::forget('signatures-pending');
    }

    /** Aviso da plataforma: localiza o documento pelos ids citados e consulta a situação na própria plataforma. */
    public static function handleWebhook(SignatureProvider $p, array $payload): int
    {
        $ids = array_values(array_unique(array_filter(self::strings($payload), fn ($v) => strlen($v) >= 3 && strlen($v) <= 100)));
        if (! $ids) {
            return 0;
        }
        $requests = SignatureRequest::where('provider', $p->driver)
            ->where(fn ($q) => $q->whereIn('external_id', $ids)->orWhereIn('external_document_id', $ids))
            ->whereIn('status', ['enviado', 'assinado'])->limit(5)->get();
        foreach ($requests as $r) {
            if ($r->status === 'assinado' && $r->signed_path) {
                continue;
            }
            try {
                $r->status === 'assinado' ? self::complete($r) : self::refresh($r);
            } catch (\Throwable $e) {
                $r->forceFill(['last_error' => $e->getMessage(), 'next_check_at' => now()->addMinutes(15)])->save();
            }
        }

        return $requests->count();
    }

    // --- ICP-Brasil com assinador próprio -----------------------------------

    /**
     * Confere o PDF assinado: quantas assinaturas digitais tem e se o certificado é ICP-Brasil.
     *
     * @return array{signatures: int, icp: bool}
     */
    public static function inspect(string $pdf): array
    {
        $count = preg_match_all('#/ByteRange\s*\[#', $pdf);
        $icp = false;
        if ($count && preg_match_all('#/Contents\s*<([0-9A-Fa-f\s]{200,})>#', $pdf, $m)) {
            foreach ($m[1] as $hex) {
                $der = @hex2bin(preg_replace('/\s+/', '', $hex).(strlen(preg_replace('/\s+/', '', $hex)) % 2 ? '0' : ''));
                if ($der !== false && stripos($der, 'ICP-Brasil') !== false) {
                    $icp = true;
                    break;
                }
            }
        }

        return ['signatures' => (int) $count, 'icp' => $icp];
    }

    // --- Apoio ---------------------------------------------------------------

    public static function copyToCloud(SignatureRequest $r): void
    {
        $storage = CloudStorage::connected();
        $owner = $r->legalCase ?? $r->contact;
        if (! $storage || ! $owner || ! $r->signed_path) {
            return;
        }
        try {
            $folders = app(CloudFolders::class);
            $folder = $folders->ensure($storage, $owner);
            $target = $folders->client()->ensureFolder($storage, self::CLOUD_FOLDER, $folder->remote_id);
            $name = CloudFolders::safeName(now()->format('Y-m-d').' - '.$r->title.' (assinado).pdf');
            $item = $folders->client()->upload($storage, $target['id'], $name, Storage::disk('local')->get($r->signed_path), 'application/pdf');
            $r->forceFill(['cloud_file_url' => $item['url'] ?? null])->save();
        } catch (\Throwable $e) {
            Log::warning('Assinatura digital: PDF assinado não copiado para a nuvem: '.$e->getMessage());
        }
    }

    public static function dispatch(string $event, SignatureRequest $r): void
    {
        Webhooks::dispatch($event, fn () => self::apiData($r->fresh(['signers']) ?? $r));
    }

    public static function apiData(SignatureRequest $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'status' => $r->status,
            'provider' => $r->provider,
            'icp_brasil' => $r->icp_brasil,
            'legal_case_id' => $r->legal_case_id,
            'contact_id' => $r->contact_id,
            'sent_at' => $r->sent_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'signers' => $r->signers->map(fn ($s) => [
                'name' => $s->name, 'email' => $s->email, 'role' => $s->role, 'status' => $s->status,
                'signed_at' => $s->signed_at?->toIso8601String(),
            ])->all(),
            'url' => route('signatures.show', $r),
        ];
    }

    private static function nextCheck(SignatureRequest $r): Carbon
    {
        $age = ($r->sent_at ?? $r->created_at ?? now())->diffInHours(now());

        return now()->addMinutes(match (true) {
            $age < 24 => 30,
            $age < 24 * 7 => 120,
            default => 720,
        });
    }

    private static function date(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function strings(mixed $data, int $depth = 0): array
    {
        if (is_string($data) || is_int($data)) {
            return [(string) $data];
        }
        if (! is_array($data) || $depth > 6) {
            return [];
        }
        $out = [];
        foreach ($data as $v) {
            array_push($out, ...self::strings($v, $depth + 1));
        }

        return array_slice($out, 0, 500);
    }
}
