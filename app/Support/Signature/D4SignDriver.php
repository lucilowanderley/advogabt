<?php

namespace App\Support\Signature;

use App\Models\SignatureProvider;
use App\Models\SignatureRequest;
use Illuminate\Support\Str;

/**
 * D4Sign, API v1. Fluxo: envia o PDF ao cofre (uploadbinary), cadastra os signatários
 * (createlist), registra o webhook (se o sistema estiver publicado) e envia (sendtosigner).
 */
class D4SignDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://sandbox.d4sign.com.br/api/v1',
        'producao' => 'https://secure.d4sign.com.br/api/v1',
    ];

    /** "act" da D4Sign: 1 assinar, 4 assinar como parte, 5 assinar como testemunha. */
    public const ACTS = ['parte' => '4', 'advogado' => '1', 'testemunha' => '5'];

    public const AUTHS = ['email' => 'email', 'sms' => 'sms', 'whatsapp' => 'whats'];

    /** statusId da D4Sign. */
    public const STATUS = ['1' => 'enviado', '2' => 'enviado', '3' => 'enviado', '4' => 'assinado', '5' => 'assinado', '6' => 'cancelado', '7' => 'enviado'];

    public function test(SignatureProvider $p): string
    {
        $safes = $this->safes($p);
        if ($p->safe_uuid && ! isset($safes[$p->safe_uuid])) {
            throw new SignatureException('A conta respondeu, mas o cofre escolhido não está entre os cofres dela. Clique em "Carregar cofres" e escolha de novo.');
        }

        return 'D4Sign conectada ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').'): '.count($safes).' cofre(s) na conta.';
    }

    /** @return array<string, string> uuid => nome */
    public function safes(SignatureProvider $p): array
    {
        $r = $this->guard($p, $this->call($p, fn () => $this->http(30)->get($this->url($p, 'safes'))));
        $out = [];
        foreach ((array) $r->json() as $safe) {
            if (is_array($safe) && ! empty($safe['uuid_safe'])) {
                $out[(string) $safe['uuid_safe']] = (string) ($safe['name-safe'] ?? $safe['name_safe'] ?? $safe['uuid_safe']);
            }
        }
        if (! $out && is_string($r->json('message'))) {
            throw new SignatureException('D4Sign: '.$r->json('message'));
        }

        return $out;
    }

    public function send(SignatureProvider $p, SignatureRequest $r, string $pdf, ?string $webhookUrl): void
    {
        if (! $p->safe_uuid) {
            throw new SignatureException('Escolha o cofre da D4Sign em Integrações › Assinatura digital.');
        }
        $upload = $this->guard($p, $this->call($p, fn () => $this->http(120)->asJson()->post($this->url($p, "documents/{$p->safe_uuid}/uploadbinary"), [
            'base64_binary_file' => base64_encode($pdf),
            'mime_type' => 'application/pdf',
            'name' => $this->filename($r),
        ])));
        $uuid = (string) ($upload->json('uuid') ?? '');
        if ($uuid === '') {
            throw new SignatureException('A D4Sign não devolveu o documento enviado. '.Str::limit($upload->body(), 200));
        }
        $r->forceFill(['external_id' => $uuid])->save();

        $signers = [];
        foreach ($r->signers as $s) {
            $auth = self::AUTHS[$s->auth] ?? 'email';
            $signers[] = [
                'email' => $s->email,
                'act' => self::ACTS[$s->role] ?? '1',
                'foreign' => '0',
                'certificadoicpbr' => $r->icp_brasil ? '1' : '0',
                'assinatura_presencial' => '0',
                'embed_methodauth' => $auth,
                'embed_smsnumber' => $auth !== 'email' ? (string) $s->internationalPhone() : '',
                'upload_allow' => '0',
            ];
        }
        $list = $this->guard($p, $this->call($p, fn () => $this->http()->asJson()->post($this->url($p, "documents/{$uuid}/createlist"), ['signers' => $signers])));
        foreach ($this->rows($list->json()) as $row) {
            $key = $row['key_signer'] ?? null;
            $email = strtolower((string) ($row['email'] ?? ''));
            if ($key && $email !== '') {
                $r->signers->first(fn ($s) => strtolower($s->email) === $email)?->forceFill(['external_id' => (string) $key])->save();
            }
        }

        if ($webhookUrl) {
            try {
                $this->http(20)->asJson()->post($this->url($p, "documents/{$uuid}/webhooks"), ['url' => $webhookUrl]);
            } catch (\Throwable) {
                // sem webhook a rotina consulta a situação
            }
        }

        $this->guard($p, $this->call($p, fn () => $this->http()->asJson()->post($this->url($p, "documents/{$uuid}/sendtosigner"), [
            'message' => (string) ($r->message ?? ''),
            'skip_email' => '0',
            'workflow' => '0',
        ])));
    }

    public function status(SignatureProvider $p, SignatureRequest $r): RemoteStatus
    {
        $doc = $this->guard($p, $this->call($p, fn () => $this->http(30)->get($this->url($p, "documents/{$r->external_id}"))));
        $info = $doc->json('0') ?? $doc->json();
        $statusId = (string) ($info['statusId'] ?? '');
        $status = self::STATUS[$statusId] ?? 'enviado';

        $signers = [];
        try {
            $list = $this->http(30)->get($this->url($p, "documents/{$r->external_id}/list"));
            foreach ($list->successful() ? $this->rows($list->json()) : [] as $row) {
                $email = strtolower((string) ($row['email'] ?? ''));
                if ($email !== '' && (string) ($row['signed'] ?? '0') === '1') {
                    $signers[$email] = ['status' => 'assinado', 'signed_at' => $row['date_signed'] ?? null];
                }
            }
        } catch (\Throwable) {
            // detalhes por signatário são opcionais
        }

        return new RemoteStatus($status, $signers, (string) ($info['statusName'] ?? $statusId));
    }

    public function downloadSigned(SignatureProvider $p, SignatureRequest $r): string
    {
        $res = $this->guard($p, $this->call($p, fn () => $this->http()->asJson()->post($this->url($p, "documents/{$r->external_id}/download"), [
            'type' => 'PDF',
            'language' => 'pt',
        ])));
        $url = $res->json('url');
        if (! $url) {
            throw new SignatureException('A D4Sign não devolveu o link do documento assinado.');
        }

        return $this->fetchFile($p, (string) $url);
    }

    public function cancel(SignatureProvider $p, SignatureRequest $r): void
    {
        $this->guard($p, $this->call($p, fn () => $this->http()->asJson()->post($this->url($p, "documents/{$r->external_id}/cancel"), [
            'comment' => 'Cancelado pelo escritório no '.config('app.name').'.',
        ])));
    }

    private function url(SignatureProvider $p, string $path): string
    {
        return (self::HOSTS[$p->environment] ?? self::HOSTS['sandbox']).'/'.ltrim($path, '/').'?'.http_build_query([
            'tokenAPI' => (string) $p->api_token,
            'cryptKey' => (string) $p->crypt_key,
        ]);
    }

    /** Linhas com "email" em qualquer nível da resposta (a D4Sign varia o formato). */
    private function rows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['email']) && is_string($data['email'])) {
            return [$data];
        }
        $out = [];
        foreach ($data as $item) {
            array_push($out, ...$this->rows($item));
        }

        return $out;
    }

    private function filename(SignatureRequest $r): string
    {
        $base = Str::limit(trim(preg_replace('/[^\pL\pN .,_()-]+/u', ' ', $r->file_name ? pathinfo($r->file_name, PATHINFO_FILENAME) : $r->title)), 120, '');

        return ($base !== '' ? $base : 'documento').'.pdf';
    }
}
