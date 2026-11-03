<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Integrations\Catalog;
use App\Support\Integrations\Deadlines;
use App\Support\Integrations\Routine;
use App\Support\Integrations\WebhookEvents;
use App\Support\Integrations\Webhooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Módulo Integrações: visão geral, tokens da API, webhooks e documentação. */
class IntegrationController extends Controller
{
    public function index(): View
    {
        return view('integrations.index', [
            'items' => Catalog::ITEMS,
            'tokens' => ApiToken::valid()->count(),
            'webhooks' => Webhook::active()->count(),
            'deliveries' => WebhookDelivery::where('created_at', '>=', now()->subDays(7))->selectRaw('count(*) as total, sum(case when success then 1 else 0 end) as ok')->first(),
        ]);
    }

    public function docs(): View
    {
        return view('integrations.docs', ['events' => WebhookEvents::ALL, 'deadlineDays' => Deadlines::days()]);
    }

    // --- Tokens da API -------------------------------------------------------

    public function tokens(): View
    {
        $tokens = ApiToken::with('user')->orderByRaw('revoked_at is not null')->latest('id')->get();

        return view('integrations.tokens', compact('tokens'));
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'access' => ['required', Rule::in(array_keys(ApiToken::ACCESS))],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [], ['name' => 'nome', 'access' => 'acesso', 'expires_at' => 'validade']);

        [$token, $plain] = ApiToken::issue($data['name'], $data['access'], $request->user()->id, $data['expires_at'] ?? null);

        return redirect()->route('integrations.tokens.index')
            ->with('new_token', ['id' => $token->id, 'name' => $token->name, 'plain' => $plain])
            ->with('success', 'Token criado. Copie agora: ele não será exibido de novo.');
    }

    public function revokeToken(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->forceFill(['revoked_at' => now()])->save();
        Webhook::where('api_token_id', $apiToken->id)->update(['active' => false]);
        Webhooks::flush();

        return back()->with('success', "Token \"{$apiToken->name}\" revogado. Sistemas que o usavam perderam o acesso.");
    }

    // --- Webhooks ------------------------------------------------------------

    public function webhooks(): View
    {
        $webhooks = Webhook::withCount(['deliveries as failed_count' => fn ($q) => $q->where('success', false)->where('created_at', '>=', now()->subDays(7))])
            ->orderByDesc('active')->orderBy('name')->get();
        $recent = WebhookDelivery::with('webhook')->latest('id')->limit(15)->get();

        return view('integrations.webhooks.index', compact('webhooks', 'recent'));
    }

    public function createWebhook(): View
    {
        return view('integrations.webhooks.form', ['webhook' => new Webhook([
            'active' => true,
            'events' => ['processo.criado', 'prazo.vencendo', 'pagamento.recebido'],
        ])]);
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        $webhook = Webhook::create($this->validatedWebhook($request) + [
            'secret' => Webhook::newSecret(),
            'source' => 'painel',
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('integrations.webhooks.show', $webhook)
            ->with('success', 'Webhook criado. Use "Enviar teste" para conferir se o destino recebe os avisos.');
    }

    public function showWebhook(Webhook $webhook): View
    {
        $deliveries = $webhook->deliveries()->paginate(25);

        return view('integrations.webhooks.show', compact('webhook', 'deliveries'));
    }

    public function editWebhook(Webhook $webhook): View
    {
        return view('integrations.webhooks.form', compact('webhook'));
    }

    public function updateWebhook(Request $request, Webhook $webhook): RedirectResponse
    {
        $webhook->update($this->validatedWebhook($request));

        return redirect()->route('integrations.webhooks.show', $webhook)->with('success', 'Webhook atualizado.');
    }

    public function destroyWebhook(Webhook $webhook): RedirectResponse
    {
        $webhook->delete();

        return redirect()->route('integrations.webhooks.index')->with('success', 'Webhook excluído.');
    }

    public function testWebhook(Webhook $webhook): RedirectResponse
    {
        @set_time_limit(60);
        $delivery = Webhooks::test($webhook);

        return $delivery->success
            ? back()->with('success', "Teste entregue em {$delivery->duration_ms} ms (HTTP {$delivery->response_status}).")
            : back()->with('error', 'O teste falhou: '.$delivery->error);
    }

    public function rotateSecret(Webhook $webhook): RedirectResponse
    {
        $webhook->update(['secret' => Webhook::newSecret()]);

        return back()->with('success', 'Novo segredo gerado. Atualize-o no sistema que recebe os avisos.');
    }

    public function redeliver(WebhookDelivery $delivery): RedirectResponse
    {
        @set_time_limit(60);
        $ok = Webhooks::send($delivery->load('webhook'));

        return $ok ? back()->with('success', 'Evento reenviado com sucesso.') : back()->with('error', 'O reenvio falhou: '.$delivery->error);
    }

    public function runRoutine(): RedirectResponse
    {
        @set_time_limit(120);
        $result = Routine::run();

        return back()->with('success', "Rotina executada: {$result['deadlines']} prazo(s) avisado(s), {$result['retried']} envio(s) repetido(s), {$result['reminders']} lembrete(s) e {$result['digests']} resumo(s) por e-mail.");
    }

    private function validatedWebhook(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:500'],
            'all_events' => ['nullable', 'boolean'],
            'events' => ['required_unless:all_events,1', 'array'],
            'events.*' => ['string', Rule::in(WebhookEvents::keys())],
            'active' => ['nullable', 'boolean'],
        ], ['events.required_unless' => 'Escolha pelo menos um evento.'], ['name' => 'nome', 'url' => 'URL', 'events' => 'eventos']);

        return [
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => $request->boolean('all_events') ? ['*'] : array_values(array_unique($data['events'])),
            'active' => $request->boolean('active'),
        ];
    }
}
