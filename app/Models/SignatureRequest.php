<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Documento (contrato, procuração...) enviado para assinatura eletrônica. */
class SignatureRequest extends Model
{
    public const STATUSES = [
        'enviado' => ['label' => 'Aguardando assinaturas', 'badge' => 'gold'],
        'assinado' => ['label' => 'Assinado', 'badge' => 'green'],
        'recusado' => ['label' => 'Recusado', 'badge' => 'red'],
        'cancelado' => ['label' => 'Cancelado', 'badge' => ''],
        'expirado' => ['label' => 'Prazo expirado', 'badge' => 'red'],
        'erro' => ['label' => 'Falha no envio', 'badge' => 'red'],
    ];

    /** Situações em que ainda se espera alguma coisa da plataforma. */
    public const OPEN = ['enviado'];

    protected $fillable = [
        'provider', 'title', 'message', 'document_template_id', 'legal_case_id', 'contact_id', 'user_id',
        'status', 'icp_brasil', 'deadline_at', 'file_name',
    ];

    protected function casts(): array
    {
        return [
            'icp_brasil' => 'boolean',
            'deadline_at' => 'date',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSigner::class)->orderBy('position')->orderBy('id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isManual(): bool
    {
        return $this->provider === 'manual';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUSES[$this->status]['badge'] ?? '';
    }

    public function getProviderLabelAttribute(): string
    {
        return SignatureProvider::label($this->provider);
    }

    public function directory(): string
    {
        return 'assinaturas/'.$this->id;
    }
}
