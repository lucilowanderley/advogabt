<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Processo judicial ou administrativo. */
class LegalCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'title', 'contact_id', 'client_role', 'opposing_party', 'area', 'action_type', 'court',
        'court_division', 'district', 'phase', 'status', 'claim_value', 'fee_percent', 'distributed_at',
        'responsible_id', 'partner_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'distributed_at' => 'date',
            'claim_value' => 'decimal:2',
            'fee_percent' => 'decimal:2',
            'datajud_sync' => 'boolean',
            'datajud_checked_at' => 'datetime',
            'datajud_updated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CaseMovement::class)->orderByDesc('date')->orderByDesc('id');
    }

    /** Intimações e publicações do DJEN vinculadas ao processo. */
    public function publications(): HasMany
    {
        return $this->hasMany(CourtPublication::class)->latest('available_at')->latest('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('number', 'like', "%{$term}%")
                ->orWhere('opposing_party', 'like', "%{$term}%")
                ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"));
        });
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->number ? "{$this->number} — {$this->title}" : $this->title;
    }
}
