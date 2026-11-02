<?php

namespace App\Support\Cloud;

use RuntimeException;

class CloudException extends RuntimeException
{
    /** O arquivo ou a pasta não existe mais (ou foi para a lixeira). */
    public bool $notFound = false;

    public static function notFound(string $message): self
    {
        $e = new self($message);
        $e->notFound = true;

        return $e;
    }
}
