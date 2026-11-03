<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configuração da integração com os tribunais (Datajud e DJEN). Uma linha por escritório. */
class CourtSetting extends Model
{
    /** Chave pública da API do Datajud divulgada pelo CNJ (pode mudar: informe a nova na tela). */
    public const PUBLIC_KEY = 'cDZHYzlZa0JadVREZDJCendQbXY6SkJlTzNjLV9TRENyQk1RdnFKZGRQdw==';

    protected $fillable = ['datajud_enabled', 'datajud_hours', 'datajud_notify', 'djen_enabled', 'auto_deadline', 'deadline_days', 'holidays'];

    protected $hidden = ['datajud_api_key'];

    protected function casts(): array
    {
        return [
            'datajud_api_key' => 'encrypted',
            'datajud_enabled' => 'boolean',
            'datajud_notify' => 'boolean',
            'djen_enabled' => 'boolean',
            'auto_deadline' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /** Configuração salva ou os valores padrão (sem gravar: a integração só liga quando o administrador salva). */
    public static function current(): self
    {
        return static::query()->first() ?? new static([
            'datajud_enabled' => true, 'datajud_hours' => 24, 'datajud_notify' => true,
            'djen_enabled' => true, 'auto_deadline' => true, 'deadline_days' => 15,
        ]);
    }

    public function apiKey(): string
    {
        return filled($this->datajud_api_key) ? trim($this->datajud_api_key) : self::PUBLIC_KEY;
    }

    public function datajudOn(): bool
    {
        return $this->exists && $this->datajud_enabled;
    }

    public function djenOn(): bool
    {
        return $this->exists && $this->djen_enabled;
    }
}
