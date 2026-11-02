<?php

namespace App\Support\Ai;

use RuntimeException;

/** Falha ao falar com o provedor de IA; a mensagem já vem pronta para o usuário. */
class AiException extends RuntimeException {}
