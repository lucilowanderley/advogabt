<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Inscrição na OAB acompanhada no DJEN (Diário de Justiça Eletrônico Nacional). */
class CourtLawyer extends Model
{
    public const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];

    protected $fillable = ['user_id', 'name', 'oab_number', 'oab_uf', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'synced_until' => 'date',
            'last_checked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(CourtPublication::class);
    }

    public function getLabelAttribute(): string
    {
        return 'OAB/'.$this->oab_uf.' '.$this->oab_number;
    }

    /**
     * Lê uma OAB escrita de qualquer jeito ("SP 123.456", "123456/SP", "OAB/SP 123.456-A").
     *
     * @return array{0: string, 1: string}|null [número, UF]
     */
    public static function parse(?string $oab): ?array
    {
        $oab = strtoupper((string) $oab);
        if (! preg_match('/\b('.implode('|', self::UFS).')\b/', $oab, $uf)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $oab);
        $digits = ltrim($digits, '0');

        return $digits !== '' && strlen($digits) <= 8 ? [$digits, $uf[1]] : null;
    }
}
