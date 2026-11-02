<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class AiMessage extends Model
{
    protected $fillable = [
        'ai_conversation_id', 'role', 'content', 'provider_name', 'model', 'input_tokens', 'output_tokens', 'is_error',
    ];

    protected function casts(): array
    {
        return ['is_error' => 'boolean'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /** Resposta da IA em Markdown convertida para HTML seguro (sem HTML bruto nem links perigosos). */
    public function getHtmlAttribute(): HtmlString
    {
        return new HtmlString(Str::markdown($this->content, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]));
    }
}
