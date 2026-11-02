<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /**
     * Contatos cujo telefone ou WhatsApp termina nos mesmos 8 dígitos
     * (ignora formatação, DDI e o 9º dígito), como chega do WhatsApp.
     */
    public static function idsByPhone(?string $phone): Collection
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) < 8) {
            return collect();
        }
        $tail = substr($digits, -8);
        $ends = fn (?string $v) => str_ends_with(preg_replace('/\D/', '', (string) $v), $tail);

        return static::query()
            ->where(fn (Builder $q) => $q->where('whatsapp', 'like', '%'.substr($tail, -4))->orWhere('phone', 'like', '%'.substr($tail, -4)))
            ->get(['id', 'phone', 'whatsapp'])
            ->filter(fn (self $c) => $ends($c->whatsapp) || $ends($c->phone))
            ->pluck('id');
    }

    /** Contato pelo número de WhatsApp (prefere clientes). */
    public static function findByPhone(?string $phone): ?self
    {
        $ids = static::idsByPhone($phone);

        return $ids->isEmpty() ? null : static::whereKey($ids)->orderByRaw("case when category = 'cliente' then 0 else 1 end")->orderBy('id')->first();
    }
}
