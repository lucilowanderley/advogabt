<?php

namespace App\Support\Whatsapp;

/** Mensagem recebida, no mesmo formato para a Evolution API e para a Meta. */
class Inbound
{
    public function __construct(
        public readonly string $phone,
        public readonly string $externalId,
        public readonly string $type = 'text', // text | document | image | audio | video | other
        public readonly ?string $text = null,
        public readonly ?string $profileName = null,
        public readonly ?string $mediaName = null,
        public readonly ?string $mediaMime = null,
        public readonly ?string $mediaBase64 = null, // conteúdo já enviado no aviso (Evolution com base64 ligado)
        public readonly ?string $mediaRef = null, // id para baixar o arquivo depois
    ) {}

    public function hasMedia(): bool
    {
        return in_array($this->type, ['document', 'image', 'audio', 'video'], true);
    }
}
