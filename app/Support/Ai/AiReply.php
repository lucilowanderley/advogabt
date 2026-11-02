<?php

namespace App\Support\Ai;

/** Resposta normalizada de qualquer provedor de IA. */
class AiReply
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}
}
