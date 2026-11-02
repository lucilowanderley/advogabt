<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiProvider;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\Ai\CertificateStore;
use App\Support\Ai\Drivers;
use App\Support\Ai\LlmClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('role', 'admin')->first();
        $this->actingAs($this->admin);
        Http::preventStrayRequests();
    }

    private function provider(string $driver = 'anthropic', array $attrs = []): AiProvider
    {
        $preset = Drivers::get($driver);

        return AiProvider::create(array_merge([
            'name' => 'IA '.$driver,
            'driver' => $driver,
            'api_key' => 'chave-secreta-123456',
            'model' => $preset['default_model'] ?: 'meu-modelo',
            'base_url' => $preset['base_url'] === '' || str_contains($preset['base_url'], 'SEU-') ? 'https://ia.exemplo.com/v1' : null,
            'max_tokens' => 1000,
            'active' => true,
            'is_default' => true,
        ], $attrs));
    }

    public function test_pages_render_with_and_without_providers(): void
    {
        $this->get('/assistente')->assertOk()->assertSee('Conecte uma inteligência artificial');
        $this->get('/assistente/provedores')->assertOk()->assertSee('Nenhum provedor cadastrado');
        foreach (array_keys(Drivers::ALL) as $driver) {
            $this->get('/assistente/provedores/create?tipo='.$driver)->assertOk();
        }

        $provider = $this->provider();
        $case = LegalCase::first();
        $this->get('/assistente')->assertOk()->assertSee('Como posso ajudar');
        $this->get('/assistente?processo='.$case->id)->assertOk()->assertSee('Resumir o processo');
        $this->get('/assistente/provedores')->assertOk()->assertSee($provider->model)->assertSee('Comercial (pago)');
        $this->assertSame('gratis', Drivers::pricing('ollama'));
        $this->assertSame('freemium', Drivers::pricing('openrouter', 'meta-llama/llama-3.3-70b-instruct:free'));
        foreach (Drivers::ALL as $d) {
            $this->assertArrayHasKey($d['pricing'], Drivers::PRICING);
        }
        $this->get('/assistente/provedores/'.$provider->id.'/edit')->assertOk()->assertDontSee('chave-secreta-123456');
        $this->get('/processos/'.$case->id)->assertOk()->assertSee('Perguntar à IA');
    }

    public function test_only_admins_manage_providers(): void
    {
        $user = User::factory()->create(['role' => 'advogado', 'active' => true]);
        $this->actingAs($user)->get('/assistente/provedores')->assertForbidden();
        $this->actingAs($user)->post('/assistente/provedores', [])->assertForbidden();
        $this->actingAs($user)->get('/assistente')->assertOk();
    }

    public function test_admin_creates_provider_with_encrypted_key_and_default(): void
    {
        $this->post('/assistente/provedores', [
            'driver' => 'anthropic', 'name' => 'Claude', 'model' => 'claude-opus-5-5',
            'api_key' => 'sk-ant-teste-987654', 'max_tokens' => 4096, 'active' => 1,
        ])->assertRedirect('/assistente/provedores');

        $provider = AiProvider::first();
        $this->assertSame('sk-ant-teste-987654', $provider->api_key);
        $this->assertTrue($provider->is_default);
        $this->assertNull($provider->base_url);
        $this->assertStringNotContainsString('sk-ant-teste-987654', DB::table('ai_providers')->value('api_key'));

        // Atualizar sem digitar a chave mantém a atual
        $this->put('/assistente/provedores/'.$provider->id, [
            'driver' => 'anthropic', 'name' => 'Claude Sonnet', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 2048, 'active' => 1, 'is_default' => 1,
        ])->assertRedirect();
        $this->assertSame('sk-ant-teste-987654', $provider->fresh()->api_key);
        $this->assertSame('claude-sonnet-5-5', $provider->fresh()->model);

        // Chave obrigatória para provedores na nuvem; URL obrigatória para "compatível"
        $this->post('/assistente/provedores', ['driver' => 'openai', 'name' => 'GPT', 'model' => 'gpt-5', 'max_tokens' => 1000])->assertSessionHasErrors('api_key');
        $this->post('/assistente/provedores', ['driver' => 'openai_compatible', 'name' => 'Local', 'model' => 'x', 'max_tokens' => 1000])->assertSessionHasErrors('base_url');
        $this->post('/assistente/provedores', ['driver' => 'ollama', 'name' => 'Ollama', 'model' => 'llama3.1', 'max_tokens' => 1000, 'active' => 1])->assertSessionHasNoErrors();
    }

    public function test_chat_with_claude_sends_case_context_and_stores_reply(): void
    {
        $provider = $this->provider('anthropic');
        $case = LegalCase::first();

        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => "## Resumo\n\n- Ponto **um**"]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ])]);

        $response = $this->post('/assistente', ['message' => 'Resuma o processo', 'legal_case_id' => $case->id]);
        $conversation = AiConversation::first();
        $response->assertRedirect(route('assistant.show', $conversation).'#fim');

        Http::assertSent(function (Request $r) use ($case) {
            return $r->url() === 'https://api.anthropic.com/v1/messages'
                && $r->header('x-api-key')[0] === 'chave-secreta-123456'
                && $r->header('anthropic-version')[0] === '2023-06-01'
                && $r['model'] === 'claude-opus-5-5'
                && $r['messages'] === [['role' => 'user', 'content' => 'Resuma o processo']]
                && str_contains($r['system'], $case->title)
                && ! isset($r['temperature']);
        });

        $this->assertSame($case->id, $conversation->legal_case_id);
        $reply = $conversation->messages()->where('role', 'assistant')->first();
        $this->assertSame(150, $reply->input_tokens + $reply->output_tokens);
        $this->get(route('assistant.show', $conversation))->assertOk()
            ->assertSee('<strong>um</strong>', false)
            ->assertSee('Resuma o processo');

        // Segunda mensagem via JSON (envio sem recarregar)
        $this->postJson(route('assistant.send', $conversation), ['message' => 'E agora?'])
            ->assertOk()->assertJsonPath('redirect', null)->assertJsonStructure(['html']);
        Http::assertSent(fn (Request $r) => count($r['messages']) === 3 && $r['messages'][1]['role'] === 'assistant');
    }

    public function test_openai_gemini_ollama_and_compatible_protocols(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['model' => 'gpt-5', 'choices' => [['message' => ['content' => 'Olá do GPT'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3]]),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Olá do Gemini']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 4, 'candidatesTokenCount' => 2]]),
            'localhost:11434/*' => Http::response(['model' => 'llama3.1', 'message' => ['role' => 'assistant', 'content' => 'Olá do Ollama'], 'done' => true]),
            'ia.exemplo.com/*' => Http::response(['choices' => [['message' => ['content' => 'Olá compatível']]]]),
            'api.mistral.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Olá Mistral']]]]),
        ]);

        foreach (['openai' => 'Olá do GPT', 'gemini' => 'Olá do Gemini', 'ollama' => 'Olá do Ollama', 'openai_compatible' => 'Olá compatível', 'mistral' => 'Olá Mistral'] as $driver => $expected) {
            AiProvider::query()->update(['is_default' => false]);
            $provider = $this->provider($driver, ['api_key' => $driver === 'ollama' ? null : 'k-'.$driver]);
            $this->post('/assistente', ['message' => 'Oi', 'ai_provider_id' => $provider->id]);
            $conversation = AiConversation::latest('id')->first();
            $this->assertSame($expected, $conversation->messages()->where('role', 'assistant')->value('content'), $driver);
        }

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer k-openai')
            && $r['max_completion_tokens'] === 1000
            && $r['messages'][0]['role'] === 'system');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'models/gemini-2.5-pro:generateContent')
            && $r->hasHeader('x-goog-api-key', 'k-gemini') && isset($r['systemInstruction']));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:11434/api/chat' && $r['stream'] === false);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mistral.ai/v1/chat/completions' && $r['max_tokens'] === 1000);
    }

    public function test_provider_errors_are_friendly_and_retry_works(): void
    {
        $provider = $this->provider('anthropic');
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)
            ->push(['content' => [['type' => 'text', 'text' => 'Agora foi']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);

        $this->post('/assistente', ['message' => 'Teste']);
        $conversation = AiConversation::first();
        $error = $conversation->messages()->where('is_error', true)->first();
        $this->assertNotNull($error);
        $this->assertStringContainsString('chave de API foi recusada', $error->content);
        $this->get(route('assistant.show', $conversation))->assertSee('Tentar novamente');

        $this->post(route('assistant.retry', $conversation))->assertRedirect();
        $this->assertSame(0, $conversation->messages()->where('is_error', true)->count());
        $this->assertSame('Agora foi', $conversation->messages()->get()->last()->content);
    }

    public function test_connection_test_marks_provider(): void
    {
        $provider = $this->provider('openai');
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Conexão OK']]]])]);

        $this->post(route('ai-providers.test', $provider))->assertSessionHas('success');
        $this->assertTrue($provider->fresh()->last_test_ok);
    }

    public function test_conversations_are_private_and_can_become_templates(): void
    {
        $this->provider('anthropic');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'PROCURAÇÃO AD JUDICIA']]])]);
        $this->post('/assistente', ['message' => 'Procuração']);
        $conversation = AiConversation::first();
        $reply = $conversation->messages()->where('role', 'assistant')->first();

        $this->post(route('assistant.template', $reply))->assertRedirect();
        $this->assertDatabaseHas('document_templates', ['content' => 'PROCURAÇÃO AD JUDICIA']);

        $other = User::factory()->create(['role' => 'advogado', 'active' => true]);
        $this->actingAs($other)->get(route('assistant.show', $conversation))->assertNotFound();
        $this->actingAs($other)->post(route('assistant.send', $conversation), ['message' => 'x'])->assertNotFound();
        $this->actingAs($other)->post(route('assistant.template', $reply))->assertNotFound();

        $this->actingAs($this->admin)->delete(route('assistant.destroy', $conversation))->assertRedirect('/assistente');
        $this->assertDatabaseCount('ai_conversations', 0);
    }

    public function test_markdown_reply_escapes_html(): void
    {
        $this->provider('anthropic');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '<script>alert(1)</script> [x](javascript:alert(1))']]])]);
        $this->post('/assistente', ['message' => 'oi']);
        $page = $this->get(route('assistant.show', AiConversation::first()))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringNotContainsString('href="javascript:', $page);
    }

    public function test_connection_failures_explain_the_real_cause(): void
    {
        $provider = $this->provider('groq');
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)'));

        $this->post(route('ai-providers.test', $provider))->assertSessionHas('error', fn ($msg) => str_contains($msg, 'certificado SSL')
            && str_contains($msg, 'cURL error 60') && ! str_contains($msg, 'see https'));
        $this->assertFalse($provider->fresh()->last_test_ok);

        $this->assertStringContainsString('DNS', LlmClient::connectionMessage($provider, 'cURL error 6: Could not resolve host: api.groq.com'));
        $this->assertStringContainsString('AI_TIMEOUT', LlmClient::connectionMessage($provider, 'cURL error 28: Operation timed out'));
        $this->assertNotFalse(LlmClient::caBundle());
    }

    public function test_windows_certificates_are_imported_when_https_is_intercepted(): void
    {
        CertificateStore::$windows = true;
        @unlink(CertificateStore::path());
        $provider = $this->provider('groq');

        Process::fake([
            '*' => Process::result("-----BEGIN CERTIFICATE-----\r\nQUJD\r\n-----END CERTIFICATE-----\r\n"),
        ]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 60: SSL certificate OpenSSL verify result: self-signed certificate in certificate chain (19)');
            }

            return Http::response(['choices' => [['message' => ['content' => 'Conexão OK']]]]);
        });

        try {
            $this->post(route('ai-providers.test', $provider))->assertSessionHas('success');
            $this->assertSame(2, $calls);
            $this->assertSame(CertificateStore::path(), LlmClient::caBundle());
            $this->assertStringContainsString('QUJD', file_get_contents(CertificateStore::path()));
            Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'Cert:'));

            // Se continuar falhando, a mensagem explica a interceptação (antivírus/proxy)
            $message = LlmClient::connectionMessage($provider, 'cURL error 60: SSL certificate OpenSSL verify result: self-signed certificate in certificate chain (19)');
            $this->assertStringContainsString('antivírus', $message);
        } finally {
            CertificateStore::$windows = null;
            @unlink(CertificateStore::path());
        }
    }

    public function test_lists_models_available_for_the_key(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['data' => [['id' => 'openai/gpt-oss-120b'], ['id' => 'whisper-large-v3'], ['id' => 'llama-3.1-8b-instant']]]),
            'api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-opus-5-5'], ['id' => 'claude-sonnet-5-5']]]),
            'generativelanguage.googleapis.com/*' => Http::response(['models' => [
                ['name' => 'models/gemini-2.5-pro', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']],
            ]]),
            'localhost:11434/*' => Http::response(['models' => [['name' => 'llama3.1:latest']]]),
        ]);

        $this->postJson(route('ai-providers.models'), ['driver' => 'groq', 'api_key' => 'gsk_x'])
            ->assertOk()->assertJsonPath('models', ['llama-3.1-8b-instant', 'openai/gpt-oss-120b']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.groq.com/openai/v1/models' && $r->hasHeader('Authorization', 'Bearer gsk_x'));

        $this->postJson(route('ai-providers.models'), ['driver' => 'anthropic', 'api_key' => 'sk-ant'])
            ->assertOk()->assertJsonPath('models', ['claude-opus-5-5', 'claude-sonnet-5-5']);
        $this->postJson(route('ai-providers.models'), ['driver' => 'gemini', 'api_key' => 'g'])
            ->assertOk()->assertJsonPath('models', ['gemini-2.5-pro']);
        $this->postJson(route('ai-providers.models'), ['driver' => 'ollama'])
            ->assertOk()->assertJsonPath('models', ['llama3.1:latest']);

        // Usa a chave já salva quando o campo fica em branco
        $saved = $this->provider('anthropic', ['api_key' => 'sk-salva']);
        $this->postJson(route('ai-providers.models'), ['driver' => 'anthropic', 'provider_id' => $saved->id])->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic.com/v1/models') && $r->hasHeader('x-api-key', 'sk-salva'));
    }

    public function test_model_not_found_shows_available_models(): void
    {
        $provider = $this->provider('groq', ['model' => 'llama-3.3-70b-versatile']);
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response(['error' => ['message' => 'The model `llama-3.3-70b-versatile` does not exist or you do not have access to it.']], 404),
            'api.groq.com/openai/v1/models' => Http::response(['data' => [['id' => 'openai/gpt-oss-120b']]]),
        ]);

        $this->post(route('ai-providers.test', $provider))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Modelos disponíveis para esta chave: openai/gpt-oss-120b'));
    }
}
