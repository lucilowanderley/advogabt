<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nota fiscal de serviço (NFS-e) emitida pelo Asaas. */
class FiscalInvoice extends Model
{
    public const STATUSES = [
        'processando' => ['label' => 'Em processamento', 'badge' => 'gold'],
        'emitida' => ['label' => 'Emitida', 'badge' => 'green'],
        'cancelada' => ['label' => 'Cancelada', 'badge' => ''],
        'erro' => ['label' => 'Erro na emissão', 'badge' => 'red'],
    ];

    protected $fillable = [
        'provider', 'financial_entry_id', 'billing_charge_id', 'contact_id', 'user_id', 'amount', 'service_description',
        'observations', 'status', 'effective_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_date' => 'date',
            'issued_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'financial_entry_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(BillingCharge::class, 'billing_charge_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', ['processando', 'emitida']);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUSES[$this->status]['badge'] ?? '';
    }
}
