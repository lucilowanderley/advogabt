<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    protected $fillable = [
        'name', 'type', 'oab', 'document', 'email', 'phone', 'city', 'state', 'commission_percent', 'pix_key',
        'active', 'notes',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'commission_percent' => 'decimal:2'];
    }

    public function legalCases(): HasMany
    {
        return $this->hasMany(LegalCase::class);
    }

    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }
}
