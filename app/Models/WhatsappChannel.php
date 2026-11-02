<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Número de WhatsApp do escritório (Evolution API ou API oficial da Meta). */
class WhatsappChannel extends Model
{
    public const DRIVERS = [
        'evolution' => [
            'label' => 'Evolution API',
            'pricing' => 'gratis',
            'note' => 'Código aberto, instalado no servidor do escritório ou contratado de um provedor. Conecta o WhatsApp lendo um QR Code. Não é a API oficial da Meta.',
        ],
        'meta' => [
            'label' => 'WhatsApp Cloud API (oficial da Meta)',
            'pricing' => 'comercial',
            'note' => 'API oficial. Exige conta Business verificada na Meta e cobra por conversa iniciada pela empresa. Fora da janela de 24h só envia modelos aprovados.',
        ],
    ];

    public const DEFAULT_GREETING = "Olá! Você está falando com o atendimento virtual do escritório. Como posso ajudar?\n\n1 - Andamento do meu processo\n2 - Agendar um atendimento\n3 - Enviar documentos\n4 - Falar com a equipe\n\nResponda com o número da opção ou escreva sua dúvida.";

    protected $fillable = [
        'name', 'driver', 'base_url', 'instance', 'api_key', 'phone_number_id', 'app_secret', 'verify_token',
        'display_phone', 'ai_provider_id', 'active', 'bot_enabled', 'notify_movements', 'greeting', 'instructions',
        'team_user_ids',
    ];

    protected $hidden = ['api_key', 'app_secret'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'app_secret' => 'encrypted',
            'team_user_ids' => 'array',
            'active' => 'boolean',
            'bot_enabled' => 'boolean',
            'notify_movements' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_inbound_at' => 'datetime',
            'last_test_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $channel) {
            $channel->webhook_token ??= Str::random(40);
            $channel->verify_token ??= Str::random(24);
        });
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsappConversation::class);
    }

    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public static function default(): ?self
    {
        return static::active()->orderBy('id')->first();
    }

    public function getDriverLabelAttribute(): string
    {
        return self::DRIVERS[$this->driver]['label'] ?? $this->driver;
    }

    /** URL que a Evolution API / a Meta chamam a cada mensagem recebida. */
    public function getWebhookUrlAttribute(): string
    {
        return url('/api/whatsapp/'.$this->webhook_token);
    }

    public function greetingText(): string
    {
        return trim((string) $this->greeting) !== '' ? $this->greeting : self::DEFAULT_GREETING;
    }

    /** @return list<int> */
    public function teamIds(): array
    {
        $ids = array_map('intval', $this->team_user_ids ?? []);

        return $ids ?: User::where('role', 'admin')->where('active', true)->pluck('id')->all();
    }
}
