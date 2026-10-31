<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'person_type', 'category', 'crm_stage', 'document', 'rg', 'email', 'phone', 'whatsapp',
        'birth_date', 'profession', 'marital_status', 'nationality', 'zip_code', 'address', 'city', 'state',
        'origin', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function legalCases(): HasMany
    {
        return $this->hasMany(LegalCase::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('document', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    public function getWhatsappLinkAttribute(): ?string
    {
        $number = preg_replace('/\D/', '', (string) ($this->whatsapp ?: $this->phone));
        if (! $number) {
            return null;
        }
        if (strlen($number) <= 11) {
            $number = '55'.$number;
        }

        return 'https://wa.me/'.$number;
    }
}
