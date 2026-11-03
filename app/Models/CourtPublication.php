<?php

namespace App\Models;

use App\Support\Courts\Cnj;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Intimação ou publicação capturada no DJEN. */
class CourtPublication extends Model
{
    public const STATUS = [
        'nova' => ['label' => 'Nova', 'badge' => 'gold'],
        'tratada' => ['label' => 'Tratada', 'badge' => 'green'],
        'descartada' => ['label' => 'Descartada', 'badge' => ''],
    ];

    protected $fillable = [
        'external_id', 'court_lawyer_id', 'legal_case_id', 'task_id', 'process_number', 'court', 'kind', 'document_type',
        'organ', 'class_name', 'text', 'link', 'parties', 'lawyers', 'available_at', 'published_at', 'deadline_days', 'status',
    ];

    protected function casts(): array
    {
        return [
            'parties' => 'array',
            'lawyers' => 'array',
            'available_at' => 'date',
            'published_at' => 'date',
            'handled_at' => 'datetime',
        ];
    }

    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(CourtLawyer::class, 'court_lawyer_id');
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeUnread(Builder $q): Builder
    {
        return $q->where('status', 'nova');
    }

    public function getMaskedNumberAttribute(): ?string
    {
        return $this->process_number ? Cnj::mask($this->process_number) : null;
    }

    public function getTitleAttribute(): string
    {
        return trim(($this->kind ?: 'Publicação').($this->document_type ? ' · '.$this->document_type : ''));
    }

    public function getExcerptAttribute(): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', strip_tags((string) $this->text)), 260);
    }
}
