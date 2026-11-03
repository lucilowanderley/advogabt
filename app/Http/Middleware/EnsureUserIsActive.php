<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Usuário desativado pelo administrador perde o acesso na hora, mesmo com a sessão aberta ou "lembrar-me". */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Seu usuário foi desativado.'], 401)
                : redirect()->route('login')->withErrors(['email' => 'Seu usuário foi desativado. Fale com o administrador do escritório.']);
        }

        return $next($request);
    }
}
