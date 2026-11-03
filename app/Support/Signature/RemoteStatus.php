<?php

namespace App\Support\Signature;

/** Situação de um documento na plataforma de assinatura. */
class RemoteStatus
{
    /**
     * @param  string  $status  enviado | assinado | recusado | cancelado | expirado
     * @param  array<string, array{status: string, signed_at: ?string}>  $signers  por e-mail (minúsculo)
     */
    public function __construct(
        public string $status,
        public array $signers = [],
        public ?string $detail = null,
    ) {}
}
