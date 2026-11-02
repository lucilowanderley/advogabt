<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticação da API por token: "Authorization: Bearer advbt_..." ou "X-Api-Key: advbt_...".
 * Uso: api.token (leitura) ou api.token:escrita.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $access = 'leitura'): Response
    {
        $plain = $request->bearerToken() ?: $request->header('X-Api-Key');
        $token = ApiToken::findValid($plain);

        if (! $token) {
            return response()->json([
                'message' => 'Token de API ausente, inválido, expirado ou revogado. Envie o cabeçalho "Authorization: Bearer <token>". Os tokens são criados em Integrações > API REST.',
            ], 401);
        }
        if ($token->user && ! $token->user->active) {
            return response()->json(['message' => 'O usuário que criou este token está inativo.'], 401);
        }
        if ($access === 'escrita' && ! $token->canWrite()) {
            return response()->json(['message' => 'Este token é somente leitura. Crie um token com acesso de escrita para cadastrar ou alterar dados.'], 403);
        }

        $token->touchUsage($request->ip());
        $request->attributes->set('api_token', $token);
        if ($token->user) {
            $request->setUserResolver(fn () => $token->user);
        }

        return $next($request);
    }
}
