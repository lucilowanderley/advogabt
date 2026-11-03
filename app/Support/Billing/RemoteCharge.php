<?php

namespace App\Support\Billing;

/** Situação de uma cobrança na plataforma, já traduzida para as situações do sistema. */
class RemoteCharge
{
    /**
     * @param  string  $status  pendente | pago | vencido | cancelado | estornado | erro
     * @param  array<string, mixed>  $fields  dados para gravar na cobrança (linha digitável, Pix, links...)
     */
    public function __construct(
        public string $status,
        public string $remoteStatus = '',
        public ?string $paidAt = null,
        public ?float $paidAmount = null,
        public ?string $paidWith = null,
        public array $fields = [],
    ) {}
}
