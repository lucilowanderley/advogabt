<?php

namespace App\Support\Courts;

use RuntimeException;

/** Falha ao consultar o Datajud ou o DJEN, com mensagem em português para a tela. */
class CourtException extends RuntimeException
{
    /** Código usado quando nem foi possível conectar (internet, DNS, certificado): não adianta insistir agora. */
    public const CONNECTION = 1;
}
