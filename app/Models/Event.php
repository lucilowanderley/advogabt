<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Compromisso da agenda (audiência, reunião, prazo...). */
class Event extends Model
{
    protected $fillable = [
        'title', 'type', 'starts_at', 'ends_at', 'all_day', 'location', 'legal_case_id', 'contact_id', 'user_id',
        'notes',
    ];

    protected $hidden = ['invited_emails'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'reminded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Nova data: o lembrete por e-mail pode ser enviado de novo.
        static::saving(function (Event $event) {
            if ($event->exists && $event->isDirty('starts_at') && ! $event->isDirty('reminded_at')) {
                $event->reminded_at = null;
            }
        });
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
