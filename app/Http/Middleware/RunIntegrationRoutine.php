<?php

namespace App\Http\Middleware;

use App\Support\Integrations\Routine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Executa a rotina das integrações (prazos e reenvios) no máximo a cada 15 minutos de uso. */
class RunIntegrationRoutine
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && $request->user()) {
            Routine::runIfDue();
        }

        return $response;
    }
}
