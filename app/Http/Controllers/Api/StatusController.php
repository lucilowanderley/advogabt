<?php

namespace App\Http\Controllers\Api;

use App\Support\Integrations\WebhookEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends ApiController
{
    /** Teste de conexão: confirma o token e mostra o que ele pode fazer. */
    public function status(Request $request): JsonResponse
    {
        $token = $this->token($request);

        return response()->json([
            'ok' => true,
            'system' => 'ADVOGABT',
            'office' => config('app.name'),
            'api_version' => 'v1',
            'server_time' => now()->toIso8601String(),
            'token' => [
                'name' => $token->name,
                'access' => $token->access,
                'can_write' => $token->canWrite(),
                'user' => $token->user?->only(['id', 'name', 'email']),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function events(): JsonResponse
    {
        return response()->json(['data' => collect(WebhookEvents::ALL)
            ->map(fn ($e, $key) => ['event' => $key, 'label' => $e['label'], 'group' => $e['group'], 'description' => $e['description']])
            ->values()]);
    }
}
