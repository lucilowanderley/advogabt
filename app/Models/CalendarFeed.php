<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Link secreto de assinatura da agenda (.ics), usado no Google Agenda, Outlook, iPhone... */
class CalendarFeed extends Model
{
    public const SCOPES = [
        'meus' => 'Só os meus compromissos e prazos',
        'equipe' => 'Agenda de toda a equipe',
    ];

    public const TASKS = [
        'prazos' => 'Prazos processuais',
        'todas' => 'Todas as tarefas com data',
        'nenhuma' => 'Nenhuma tarefa (só compromissos)',
    ];

    protected $fillable = ['user_id', 'name', 'scope', 'tasks'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_accessed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (CalendarFeed $feed) {
            $feed->token ??= self::newToken();
        });
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return route('calendar.feed', ['token' => $this->token]);
    }

    /** Mesmo link com webcal:// (abre direto no app de agenda do computador ou do celular). */
    public function webcalUrl(): string
    {
        return preg_replace('#^https?://#', 'webcal://', $this->url());
    }
}
