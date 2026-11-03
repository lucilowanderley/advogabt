<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pessoa que precisa assinar um documento. */
class SignatureSigner extends Model
{
    public const ROLES = [
        'parte' => 'Parte / contratante',
        'advogado' => 'Advogado(a)',
        'testemunha' => 'Testemunha',
    ];

    public const AUTHS = [
        'email' => 'Link por e-mail',
        'sms' => 'Código por SMS',
        'whatsapp' => 'Código por WhatsApp',
    ];

    public const STATUSES = [
        'pendente' => ['label' => 'Pendente', 'badge' => 'gold'],
        'assinado' => ['label' => 'Assinou', 'badge' => 'green'],
        'recusado' => ['label' => 'Recusou', 'badge' => 'red'],
    ];

    protected $fillable = ['name', 'email', 'phone', 'document', 'role', 'auth', 'status', 'position'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    /** Telefone no formato internacional (+5511999998888). */
    public function internationalPhone(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);
        if ($digits === '') {
            return null;
        }

        return '+'.(strlen($digits) <= 11 ? '55'.$digits : $digits);
    }

    /** CPF com pontuação (000.000.000-00), quando tem 11 dígitos. */
    public function formattedDocument(): ?string
    {
        $d = preg_replace('/\D/', '', (string) $this->document);

        return strlen($d) === 11 ? substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9) : null;
    }
}
