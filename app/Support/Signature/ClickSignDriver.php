<?php

namespace App\Support\Signature;

use App\Models\SignatureProvider;
use App\Models\SignatureRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * ClickSign, API v3 (envelopes). Fluxo: cria o envelope, anexa o PDF, cadastra os signatários,
 * cria os requisitos (assinar + forma de autenticação), ativa o envelope e notifica.
 */
class ClickSignDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://sandbox.clicksign.com/api/v3',
        'producao' => 'https://app.clicksign.com/api/v3',
    ];

    /** Papel do signatário no documento ("assinar como"). */
    public const ROLES = ['parte' => 'party', 'advogado' => 'lawyer', 'testemunha' => 'witness'];

    public function test(SignatureProvider $p): string
    {
        $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, 'envelopes'), ['page[size]' => 1])));

        return 'ClickSign conectado ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').').';
    }

    public function send(SignatureProvider $p, SignatureRequest $r, string $pdf, ?string $webhookUrl): void
    {
        $attributes = [
            'name' => Str::limit($r->title, 250, ''),
            'locale' => 'pt-BR',
            'auto_close' => true,
            'remind_interval' => 3,
            'block_after_refusal' => true,
        ];
        if ($r->deadline_at) {
            $attributes['deadline_at'] = $r->deadline_at->copy()->setTime(23, 59)->toIso8601String();
        }
        $envelope = $this->post($p, 'envelopes', ['type' => 'envelopes', 'attributes' => $attributes]);
        $envelopeId = (string) $envelope->json('data.id');
        if ($envelopeId === '') {
            throw new SignatureException('A ClickSign não devolveu o envelope criado.');
        }
        $r->forceFill(['external_id' => $envelopeId])->save();

        $doc = $this->post($p, "envelopes/{$envelopeId}/documents", ['type' => 'documents', 'attributes' => [
            'filename' => $this->filename($r),
            'content_base64' => 'data:application/pdf;base64,'.base64_encode($pdf),
        ]]);
        $documentId = (string) $doc->json('data.id');
        $r->forceFill(['external_document_id' => $documentId])->save();

        foreach ($r->signers as $i => $s) {
            $channel = $s->auth === 'email' ? 'email' : $s->auth;
            $signer = $this->post($p, "envelopes/{$envelopeId}/signers", ['type' => 'signers', 'attributes' => array_filter([
                'name' => $s->name,
                'email' => $s->email,
                'phone_number' => $s->internationalPhone() ? substr($s->internationalPhone(), 3) : null,
                'has_documentation' => (bool) $s->formattedDocument(),
                'documentation' => $s->formattedDocument(),
                'refusable' => true,
                'group' => 1,
                'communicate_events' => [
                    'signature_request' => $channel,
                    'signature_reminder' => $channel === 'whatsapp' ? 'email' : $channel,
                    'document_signed' => 'email',
                ],
            ], fn ($v) => $v !== null)]);
            $signerId = (string) $signer->json('data.id');
            $s->forceFill(['external_id' => $signerId])->save();

            $relationships = [
                'document' => ['data' => ['type' => 'documents', 'id' => $documentId]],
                'signer' => ['data' => ['type' => 'signers', 'id' => $signerId]],
            ];
            $this->post($p, "envelopes/{$envelopeId}/requirements", ['type' => 'requirements',
                'attributes' => ['action' => 'agree', 'role' => self::ROLES[$s->role] ?? 'sign'],
                'relationships' => $relationships]);
            $this->post($p, "envelopes/{$envelopeId}/requirements", ['type' => 'requirements',
                'attributes' => ['action' => 'provide_evidence', 'auth' => $r->icp_brasil ? 'icp_brasil' : $s->auth],
                'relationships' => $relationships]);
        }

        $this->guard($p, $this->call($p, fn () => $this->api($p)->patch($this->url($p, "envelopes/{$envelopeId}"), ['data' => [
            'id' => $envelopeId, 'type' => 'envelopes', 'attributes' => ['status' => 'running'],
        ]])));

        $this->post($p, "envelopes/{$envelopeId}/notifications", ['type' => 'notifications', 'attributes' => array_filter([
            'message' => $r->message ? Str::limit($r->message, 500, '') : null,
        ])]);
    }

    public function status(SignatureProvider $p, SignatureRequest $r): RemoteStatus
    {
        $envelope = $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, "envelopes/{$r->external_id}"))));
        $remote = (string) $envelope->json('data.attributes.status');

        // Quem já assinou / recusou: eventos do documento (se a conta não liberar, segue só com a situação do envelope).
        $signers = [];
        $refused = false;
        try {
            $byId = $r->signers->filter(fn ($s) => $s->external_id)->keyBy('external_id');
            $events = $this->api($p)->get($this->url($p, "envelopes/{$r->external_id}/documents/{$r->external_document_id}/events"));
            foreach ($events->successful() ? (array) $events->json('data') : [] as $event) {
                $name = (string) Arr::get($event, 'attributes.name');
                $data = (array) Arr::get($event, 'attributes.data', []);
                $email = strtolower((string) (Arr::get($data, 'signer.email') ?? Arr::get($data, 'email') ?? ''));
                $key = (string) (Arr::get($data, 'signer.key') ?? Arr::get($data, 'signer.id') ?? Arr::get($data, 'signer_id') ?? '');
                if ($email === '' && $key !== '' && $byId->has($key)) {
                    $email = strtolower($byId[$key]->email);
                }
                $at = Arr::get($event, 'attributes.created') ?? Arr::get($event, 'attributes.created_at') ?? Arr::get($data, 'signed_at');
                if ($email !== '' && $name === 'sign') {
                    $signers[$email] = ['status' => 'assinado', 'signed_at' => $at];
                } elseif ($email !== '' && $name === 'refusal') {
                    $signers[$email] = ['status' => 'recusado', 'signed_at' => null];
                    $refused = true;
                }
            }
        } catch (\Throwable) {
            // detalhes por signatário são opcionais
        }

        $status = match ($remote) {
            'closed' => 'assinado',
            'canceled', 'cancelled' => $refused ? 'recusado' : ($r->deadline_at && $r->deadline_at->endOfDay()->isPast() ? 'expirado' : 'cancelado'),
            default => $refused ? 'recusado' : 'enviado',
        };
        if ($status === 'assinado') {
            foreach ($r->signers as $s) {
                $signers[strtolower($s->email)] ??= ['status' => 'assinado', 'signed_at' => null];
            }
        }

        return new RemoteStatus($status, $signers, $remote);
    }

    public function downloadSigned(SignatureProvider $p, SignatureRequest $r): string
    {
        $doc = $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, "envelopes/{$r->external_id}/documents/{$r->external_document_id}"))));
        $url = $doc->json('data.links.files.signed') ?? $doc->json('data.links.files.ziped');
        if (! $url) {
            throw new SignatureException('O documento ainda não tem a versão assinada na ClickSign.');
        }

        return $this->fetchFile($p, (string) $url);
    }

    public function cancel(SignatureProvider $p, SignatureRequest $r): void
    {
        $this->guard($p, $this->call($p, fn () => $this->api($p)->patch($this->url($p, "envelopes/{$r->external_id}"), ['data' => [
            'id' => $r->external_id, 'type' => 'envelopes', 'attributes' => ['status' => 'canceled'],
        ]])));
    }

    private function post(SignatureProvider $p, string $path, array $data)
    {
        return $this->guard($p, $this->call($p, fn () => $this->api($p)->post($this->url($p, $path), ['data' => $data])));
    }

    private function api(SignatureProvider $p): PendingRequest
    {
        return $this->http()->asJson()
            ->withHeaders(['Authorization' => (string) $p->api_token, 'Accept' => 'application/vnd.api+json'])
            ->contentType('application/vnd.api+json');
    }

    private function url(SignatureProvider $p, string $path): string
    {
        return (self::HOSTS[$p->environment] ?? self::HOSTS['sandbox']).'/'.ltrim($path, '/');
    }

    private function filename(SignatureRequest $r): string
    {
        $base = Str::limit(trim(preg_replace('/[^\pL\pN .,_()-]+/u', ' ', $r->file_name ? pathinfo($r->file_name, PATHINFO_FILENAME) : $r->title)), 120, '');

        return ($base !== '' ? $base : 'documento').'.pdf';
    }
}
