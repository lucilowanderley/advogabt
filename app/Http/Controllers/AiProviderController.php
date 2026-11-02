<?php

namespace App\Http\Controllers;

use App\Models\AiMessage;
use App\Models\AiProvider;
use App\Support\Ai\AiException;
use App\Support\Ai\Drivers;
use App\Support\Ai\LlmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Cadastro das conexões com provedores de IA (somente administradores). */
class AiProviderController extends Controller
{
    public function index(): View
    {
        $providers = AiProvider::orderByDesc('is_default')->orderByDesc('active')->orderBy('name')->get();

        // Consumo de tokens do mês por provedor (para acompanhar custos).
        $usage = AiMessage::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->startOfMonth())
            ->groupBy('provider_name')
            ->select('provider_name', DB::raw('count(*) as replies'), DB::raw('coalesce(sum(input_tokens),0) as input'), DB::raw('coalesce(sum(output_tokens),0) as output'))
            ->get()
            ->keyBy('provider_name');

        return view('assistant.providers.index', compact('providers', 'usage'));
    }

    public function create(Request $request): View
    {
        $driver = array_key_exists((string) $request->query('tipo'), Drivers::ALL) ? $request->query('tipo') : 'anthropic';
        $preset = Drivers::get($driver);

        return view('assistant.providers.form', ['provider' => new AiProvider([
            'driver' => $driver,
            'name' => preg_replace('/\s*\(.*\)$/', '', $preset['label']),
            'model' => $preset['default_model'],
            'max_tokens' => 4096,
            'active' => true,
            'is_default' => ! AiProvider::exists(),
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = AiProvider::create($this->validated($request));
        if ($provider->is_default || AiProvider::active()->count() === 1) {
            $provider->makeDefault();
        }

        return redirect()->route('ai-providers.index')->with('success', 'Provedor de IA cadastrado. Use "Testar conexão" para validar a chave.');
    }

    public function edit(AiProvider $aiProvider): View
    {
        return view('assistant.providers.form', ['provider' => $aiProvider]);
    }

    public function update(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        $aiProvider->update($this->validated($request, $aiProvider));
        if ($aiProvider->is_default) {
            $aiProvider->makeDefault();
        }

        return redirect()->route('ai-providers.index')->with('success', 'Provedor de IA atualizado.');
    }

    public function destroy(AiProvider $aiProvider): RedirectResponse
    {
        $wasDefault = $aiProvider->is_default;
        $aiProvider->delete();
        if ($wasDefault && ($next = AiProvider::active()->first())) {
            $next->makeDefault();
        }

        return redirect()->route('ai-providers.index')->with('success', 'Provedor de IA excluído.');
    }

    public function makeDefault(AiProvider $aiProvider): RedirectResponse
    {
        $aiProvider->makeDefault();

        return back()->with('success', "\"{$aiProvider->name}\" agora é o provedor padrão do escritório.");
    }

    public function test(AiProvider $aiProvider, LlmClient $client): RedirectResponse
    {
        @set_time_limit(120);

        try {
            $reply = $client->chat($aiProvider, 'Responda de forma curta.', [
                ['role' => 'user', 'content' => 'Responda apenas: "Conexão OK".'],
            ], 200);
            $aiProvider->forceFill(['last_tested_at' => now(), 'last_test_ok' => true])->save();

            return back()->with('success', "Conexão com \"{$aiProvider->name}\" funcionando (modelo {$reply->model}).");
        } catch (AiException $e) {
            $aiProvider->forceFill(['last_tested_at' => now(), 'last_test_ok' => false])->save();
            $message = "Falha ao testar \"{$aiProvider->name}\": ".$e->getMessage();

            // Modelo inexistente: mostra os que a chave pode usar.
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                try {
                    $models = $client->models($aiProvider);
                    if ($models) {
                        $message .= ' Modelos disponíveis para esta chave: '.implode(', ', array_slice($models, 0, 20)).(count($models) > 20 ? '…' : '').'. Edite o provedor e escolha um deles.';
                    }
                } catch (AiException) {
                    // mantém só a mensagem original
                }
            }

            return back()->with('error', $message);
        }
    }

    /** Lista os modelos que a chave informada (ou a já salva) pode usar. */
    public function models(Request $request, LlmClient $client): JsonResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(array_keys(Drivers::ALL))],
            'base_url' => ['nullable', 'url:http,https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
        ]);
        @set_time_limit(60);

        $saved = isset($data['provider_id']) ? AiProvider::find($data['provider_id']) : null;
        $provider = new AiProvider([
            'driver' => $data['driver'],
            'base_url' => $data['base_url'] ?? null,
            'api_key' => filled($data['api_key'] ?? null) ? trim($data['api_key']) : $saved?->api_key,
            'model' => '-',
        ]);

        try {
            $models = $client->models($provider);
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'models' => $models,
            'message' => $models ? count($models).' modelo(s) disponível(is) para esta chave.' : 'O provedor não retornou modelos para esta chave.',
        ]);
    }

    private function validated(Request $request, ?AiProvider $provider = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(array_keys(Drivers::ALL))],
            'base_url' => ['nullable', 'url:http,https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'clear_key' => ['nullable', 'boolean'],
            'model' => ['required', 'string', 'max:150'],
            'max_tokens' => ['required', 'integer', 'min:256', 'max:128000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'system_prompt' => ['nullable', 'string', 'max:5000'],
            'api_version' => ['nullable', 'string', 'max:30'],
            'organization' => ['nullable', 'string', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ], [], [
            'base_url' => 'URL base',
            'api_key' => 'chave de API',
            'model' => 'modelo',
            'max_tokens' => 'limite de tokens',
            'temperature' => 'temperatura',
        ]);

        $preset = Drivers::get($data['driver']);
        $data['base_url'] = isset($data['base_url']) ? rtrim($data['base_url'], '/') : null;
        if ($data['base_url'] === $preset['base_url']) {
            $data['base_url'] = null; // segue a URL padrão do provedor
        }
        if (! $data['base_url'] && ($preset['base_url'] === '' || str_contains($preset['base_url'], 'SEU-'))) {
            throw ValidationException::withMessages(['base_url' => 'Informe a URL base do serviço.']);
        }

        // A chave só é trocada quando uma nova é digitada (o formulário nunca exibe a atual).
        if ($request->boolean('clear_key')) {
            $data['api_key'] = null;
        } elseif (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
            if (! $provider?->api_key && $preset['needs_key']) {
                throw ValidationException::withMessages(['api_key' => 'Informe a chave de API do provedor.']);
            }
        } else {
            $data['api_key'] = trim($data['api_key']);
        }

        $data['options'] = array_filter([
            'api_version' => $data['api_version'] ?? null,
            'organization' => $data['organization'] ?? null,
        ]) ?: null;
        unset($data['api_version'], $data['organization'], $data['clear_key']);

        $data['is_default'] = $request->boolean('is_default');
        $data['active'] = $request->boolean('active');
        if (! $data['active']) {
            $data['is_default'] = false;
        }

        return $data;
    }
}
