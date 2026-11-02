<?php

namespace App\Http\Controllers\Api;

use App\Models\Webhook;
use App\Support\Integrations\WebhookEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Assinatura de webhooks pela própria API (REST Hooks), usada por gatilhos
 * "instantâneos" do Zapier/Make/n8n: o app cria o webhook ao ligar o fluxo e apaga ao desligar.
 */
class WebhookSubscriptionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $hooks = Webhook::where('api_token_id', $this->token($request)->id)->orderBy('id')->get();

        return response()->json(['data' => $hooks->map(fn (Webhook $w) => $this->data($w))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_merge(['*'], WebhookEvents::keys()))],
            'name' => ['nullable', 'string', 'max:100'],
            'secret' => ['nullable', 'string', 'min:16', 'max:200'],
        ]);
        $token = $this->token($request);
        $hook = Webhook::create([
            'name' => $data['name'] ?? 'Via API: '.$token->name,
            'url' => $data['url'],
            'secret' => $data['secret'] ?? Webhook::newSecret(),
            'events' => array_values(array_unique($data['events'])),
            'active' => true,
            'source' => 'api',
            'api_token_id' => $token->id,
            'user_id' => $token->user_id,
        ]);

        return $this->item($this->data($hook) + ['secret' => $hook->secret], 201);
    }

    public function destroy(Request $request, Webhook $webhook): JsonResponse
    {
        abort_unless($webhook->api_token_id === $this->token($request)->id, 404);
        $webhook->delete();

        return response()->json(null, 204);
    }

    private function data(Webhook $w): array
    {
        return ['id' => $w->id, 'name' => $w->name, 'url' => $w->url, 'events' => $w->events, 'active' => $w->active, 'created_at' => $w->created_at?->toIso8601String()];
    }
}
