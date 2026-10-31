<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lançamento financeiro (conta a receber ou a pagar). */
class FinancialEntry extends Model
{
    protected $fillable = [
        'type', 'description', 'category', 'amount', 'due_date', 'paid_at', 'payment_method', 'contact_id',
        'legal_case_id', 'partner_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'date',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->whereNotNull('paid_at');
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereNull('paid_at');
    }

    /** pago | atrasado | aberto */
    public function getSituationAttribute(): string
    {
        if ($this->paid_at) {
            return 'pago';
        }

        return $this->due_date->lt(today()) ? 'atrasado' : 'aberto';
    }
}
