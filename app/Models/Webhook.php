<?php

namespace App\Models;

use App\Support\Integrations\WebhookEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** URL externa avisada quando acontecem eventos no escritório. */
class Webhook extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'active', 'source', 'api_token_id', 'user_id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'last_delivery_ok' => 'boolean',
            'last_delivery_at' => 'datetime',
        ];
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(32);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest('id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'api_token_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function listensTo(string $event): bool
    {
        $events = $this->events ?? [];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }

    /** @return list<string> */
    public function getEventLabelsAttribute(): array
    {
        if (in_array('*', $this->events ?? [], true)) {
            return ['Todos os eventos'];
        }

        return array_map(fn ($e) => WebhookEvents::label($e), $this->events ?? []);
    }

    public function getHostAttribute(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?: $this->url;
    }
}
