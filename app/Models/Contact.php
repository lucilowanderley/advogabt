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
     * Contatos com o mesmo telefone ou WhatsApp, como chega do WhatsApp: ignora formatação,
     * o DDI 55 e o 9º dígito. Compara os 8 últimos dígitos e também o DDD quando os dois
     * números têm DDD (assim o mesmo número em outra cidade não é confundido com o cliente).
     */
    public static function idsByPhone(?string $phone): Collection
    {
        $target = self::phoneKey($phone);
        if (! $target) {
            return collect();
        }
        [$ddd, $tail] = $target;
        $same = function (?string $value) use ($ddd, $tail): bool {
            $key = self::phoneKey($value);

            return $key !== null && $key[1] === $tail && ($ddd === null || $key[0] === null || $key[0] === $ddd);
        };

        return static::query()
            ->where(fn (Builder $q) => $q->where('whatsapp', 'like', '%'.substr($tail, -4))->orWhere('phone', 'like', '%'.substr($tail, -4)))
            ->get(['id', 'phone', 'whatsapp'])
            ->filter(fn (self $c) => $same($c->whatsapp) || $same($c->phone))
            ->pluck('id');
    }

    /**
     * [DDD ou null, 8 últimos dígitos] de um telefone brasileiro.
     *
     * @return array{0: ?string, 1: string}|null
     */
    public static function phoneKey(?string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2); // DDI
        }
        if (strlen($digits) < 8) {
            return null;
        }
        $rest = ltrim(substr($digits, 0, -8), '0'); // 0 da operadora / do DDD
        if (strlen($rest) === 3 && str_ends_with($rest, '9')) {
            $rest = substr($rest, 0, 2); // 9º dígito do celular
        }

        return [strlen($rest) === 2 ? $rest : null, substr($digits, -8)];
    }

    /** Contato pelo número de WhatsApp (prefere clientes). */
    public static function findByPhone(?string $phone): ?self
    {
        $ids = static::idsByPhone($phone);

        return $ids->isEmpty() ? null : static::whereKey($ids)->orderByRaw("case when category = 'cliente' then 0 else 1 end")->orderBy('id')->first();
    }
}
