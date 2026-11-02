<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de cada envio de webhook (para conferência e reenvio). */
class WebhookDelivery extends Model
{
    /** Tentativas automáticas antes de desistir (o reenvio manual continua possível). */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'webhook_id', 'uuid', 'event', 'payload', 'attempts', 'response_status', 'response_body', 'error',
        'duration_ms', 'success', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
            'delivered_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
