<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Agenda\AgendaMail;
use App\Support\Cloud\CloudEvents;
use App\Support\Integrations\ModelEvents;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));
        Paginator::defaultView('partials.pagination');

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // API REST: limite de chamadas por token (Integrações).
        RateLimiter::for('api-token', fn (Request $request) => Limit::perMinute((int) config('services.integrations.api_rate_limit', 120))
            ->by('api:'.($request->attributes->get('api_token')?->id ?? $request->ip()))
            ->response(fn () => response()->json(['message' => 'Limite de chamadas por minuto atingido. Aguarde alguns segundos.'], 429)));

        // Webhooks: liga os acontecimentos do sistema aos eventos enviados.
        ModelEvents::register();

        // Arquivos na nuvem: pastas automáticas de clientes e processos.
        CloudEvents::register();

        // Agenda e e-mail: convites (.ics) quando um compromisso é marcado, alterado ou excluído.
        AgendaMail::register();
    }
}
