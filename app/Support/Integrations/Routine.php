<?php

namespace App\Support\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Rotina das integrações: avisa prazos vencendo e reenvia webhooks que falharam.
 * Roda pelo agendador (php artisan schedule:work) e, para quem não configurou o
 * agendador no Laragon, também a cada 15 minutos de uso do sistema, depois da resposta.
 */
class Routine
{
    public static function run(): array
    {
        return ['deadlines' => Deadlines::notify(), 'retried' => Webhooks::retryFailed()];
    }

    public static function runIfDue(): void
    {
        if (! config('services.integrations.web_routine') || ! Webhooks::hasActive()) {
            return;
        }
        if (! Cache::add('integrations-routine', true, now()->addMinutes(15))) {
            return;
        }
        $run = function () {
            try {
                self::run();
            } catch (\Throwable $e) {
                Log::warning('Rotina de integrações falhou: '.$e->getMessage());
            }
        };
        function_exists('Illuminate\Support\defer') ? \Illuminate\Support\defer($run) : app()->terminating($run);
    }
}
