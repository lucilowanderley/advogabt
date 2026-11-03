<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro dos e-mails automáticos enviados (ou que falharam). */
class MailLog extends Model
{
    public const UPDATED_AT = null;

    public const KINDS = [
        'convite' => 'Convite',
        'cancelamento' => 'Cancelamento',
        'lembrete' => 'Lembrete',
        'resumo' => 'Resumo diário',
        'teste' => 'Teste',
    ];

    protected $fillable = ['kind', 'to', 'subject', 'success', 'error'];

    protected function casts(): array
    {
        return ['success' => 'boolean'];
    }
}
