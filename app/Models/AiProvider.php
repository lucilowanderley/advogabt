<?php

namespace App\Models;

use App\Support\Ai\Drivers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conexão com um provedor de IA (chave e modelo contratados pelo escritório). */
class AiProvider extends Model
{
    protected $fillable = [
        'name', 'driver', 'base_url', 'api_key', 'model', 'max_tokens', 'temperature', 'system_prompt',
        'options', 'is_default', 'active', 'last_tested_at', 'last_test_ok',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'options' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
            'temperature' => 'float',
            'max_tokens' => 'integer',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Provedor padrão do escritório (ou o primeiro ativo). */
    public static function default(): ?self
    {
        return static::active()->orderByDesc('is_default')->orderBy('id')->first();
    }

    public function makeDefault(): void
    {
        static::whereKeyNot($this->id)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true, 'active' => true])->save();
    }

    public function getDriverLabelAttribute(): string
    {
        return Drivers::get($this->driver)['label'];
    }

    public function getProtocolAttribute(): string
    {
        return Drivers::protocol($this->driver);
    }

    /** Gratuito ou comercial: chave de Drivers::PRICING. */
    public function getPricingAttribute(): string
    {
        return Drivers::pricing($this->driver, $this->model);
    }

    public function getPricingLabelAttribute(): string
    {
        return Drivers::pricingLabel($this->pricing);
    }

    /** URL base sem barra final (usa a padrão do provedor quando vazia). */
    public function endpoint(): string
    {
        return rtrim($this->base_url ?: Drivers::get($this->driver)['base_url'], '/');
    }

    /** Chave mascarada para exibição: sk-ant-…a1b2 */
    public function getMaskedKeyAttribute(): string
    {
        $key = (string) $this->api_key;
        if ($key === '') {
            return '—';
        }

        return mb_substr($key, 0, min(6, (int) (strlen($key) / 3))).'…'.mb_substr($key, -4);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }
}
