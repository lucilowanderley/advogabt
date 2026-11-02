<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Conversa de WhatsApp com um número (cliente, lead ou desconhecido). */
class WhatsappConversation extends Model
{
    protected $fillable = [
        'whatsapp_channel_id', 'phone', 'profile_name', 'contact_id', 'mode', 'state', 'assigned_user_id', 'unread',
        'last_message_at', 'last_inbound_at',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_inbound_at' => 'datetime', 'unread' => 'integer'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WhatsappChannel::class, 'whatsapp_channel_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class)->orderBy('id');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(WhatsappMessage::class)->latestOfMany();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->contact?->name ?: ($this->profile_name ?: $this->formatted_phone);
    }

    public function getFormattedPhoneAttribute(): string
    {
        $d = $this->phone;
        if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $d, $m)) {
            return "+55 ({$m[1]}) {$m[2]}-{$m[3]}";
        }

        return '+'.$d;
    }

    /** A Meta só aceita texto livre até 24h depois da última mensagem do cliente. */
    public function insideServiceWindow(): bool
    {
        return $this->last_inbound_at && $this->last_inbound_at->gt(now()->subDay());
    }
}
