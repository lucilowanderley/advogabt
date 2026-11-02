<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Token de acesso à API REST do escritório (só o hash fica gravado). */
class ApiToken extends Model
{
    public const PREFIX = 'advbt_';

    public const ACCESS = [
        'leitura' => 'Somente leitura',
        'escrita' => 'Leitura e escrita',
    ];

    protected $fillable = ['name', 'access', 'user_id', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cria um token e devolve [modelo, token em texto]. O texto só existe neste momento.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, string $access, ?int $userId, mixed $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = new self(['name' => $name, 'access' => $access, 'user_id' => $userId, 'expires_at' => $expiresAt]);
        $token->forceFill(['token_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 12)])->save();

        return [$token, $plain];
    }

    public static function findValid(?string $plain): ?self
    {
        if (! $plain || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $token = static::where('token_hash', hash('sha256', $plain))->first();

        return $token && $token->isValid() ? $token : null;
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isValid(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function canWrite(): bool
    {
        return $this->access === 'escrita';
    }

    public function getAccessLabelAttribute(): string
    {
        return self::ACCESS[$this->access] ?? $this->access;
    }

    public function getStatusAttribute(): string
    {
        return match (true) {
            (bool) $this->revoked_at => 'revogado',
            $this->expires_at && $this->expires_at->isPast() => 'expirado',
            default => 'ativo',
        };
    }

    /** Marca o uso no máximo uma vez por minuto (evita escrita a cada chamada). */
    public function touchUsage(?string $ip): void
    {
        if (! $this->last_used_at || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now(), 'last_ip' => $ip])->saveQuietly();
        }
    }
}
