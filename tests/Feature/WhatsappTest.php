<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Contact;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Support\Integrations\Webhooks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsappTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contact $client;

    private LegalCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('role', 'admin')->first();
        $this->withoutDefer();
        Http::preventStrayRequests();
        Storage::fake('local');

        $this->client = Contact::create(['name' => 'Joana Prado', 'category' => 'cliente', 'whatsapp' => '(21) 97531-8642']);
        $this->case = LegalCase::create(['title' => 'Ação de alimentos', 'number' => '0009999-11.2026.8.19.0001', 'contact_id' => $this->client->id]);
        $this->case->movements()->create(['date' => today(), 'description' => 'Concluso para despacho']);
    }

    private function channel(array $attrs = []): WhatsappChannel
    {
        return WhatsappChannel::create(array_merge([
            'name' => 'WhatsApp', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'escritorio',
            'api_key' => 'evo-key', 'active' => true, 'bot_enabled' => true,
        ], $attrs));
    }

    private function evolution(string $text, string $phone = '5521975318642', ?string $id = null, ?array $message = null): array
    {
        return ['event' => 'messages.upsert', 'instance' => 'escritorio', 'data' => [
            'key' => ['remoteJid' => "{$phone}@s.whatsapp.net", 'fromMe' => false, 'id' => $id ?? uniqid('M')],
            'pushName' => 'Joana',
            'message' => $message ?? ['conversation' => $text],
        ]];
    }

    private function fakeServices(string $aiText = 'Seu processo está concluso para despacho, ou seja, aguardando decisão do juiz.'): void
    {
        Http::fake([
            'evo.example.com/*' => Http::response(['key' => ['id' => 'OUT1']]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $aiText]], 'model' => 'claude-test', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]),
        ]);
    }

    private function sentTexts(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'message/sendText'))
            ->map(fn (Request $r) => $r['text'])->values()->all();
    }

    public function test_known_client_gets_menu_and_case_status_from_ai(): void
    {
        $this->fakeServices();
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model' => 'claude-opus-5-5', 'max_tokens' => 1000, 'active' => true, 'is_default' => true]);
        $channel = $this->channel();

        $this->postJson("/api/whatsapp/{$channel->webhook_token}", $this->evolution('Oi'))->assertOk()->assertJson(['received' => 1]);
        $conv = WhatsappConversation::first();
        $this->assertSame($this->client->id, $conv->contact_id);
        $this->assertStringContainsString('Olá, Joana!', $this->sentTexts()[0]);

        $this->postJson("/api/whatsapp/{$channel->webhook_token}", $this->evolution('1'))->assertOk();
        $this->assertStringContainsString('concluso para despacho', $this->sentTexts()[1]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic.com') && str_contains($r['system'], '0009999-11.2026.8.19.0001') && str_contains($r['system'], 'Concluso para despacho'));
        $this->assertSame(4, $conv->messages()->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendText/escritorio') && $r->hasHeader('apikey', 'evo-key') && $r['number'] === '5521975318642');
    }

    public function test_unknown_number_is_never_told_about_cases(): void
    {
        $this->fakeServices();
        $channel = $this->channel();
        $this->postJson("/api/whatsapp/{$channel->webhook_token}", $this->evolution('1', '5511900001111'))->assertOk();

        $this->assertStringContainsString('Não encontrei o seu cadastro', $this->sentTexts()[0]);
        $this->postJson("/api/whatsapp/{$channel->webhook_token}", $this->evolution('Maria Silva, CPF 123', '5511900001111'))->assertOk();
        $this->assertTrue(Task::where('title', 'like', 'Identificar cliente no WhatsApp%')->exists());
        $this->assertStringNotContainsString('alimentos', implode(' ', $this->sentTexts()));
    }

    public function test_scheduling_handoff_documents_and_duplicates(): void
    {
        $this->fakeServices();
        $channel = $this->channel(['team_user_ids' => [$this->admin->id]]);
        $url = "/api/whatsapp/{$channel->webhook_token}";

        $this->postJson($url, $this->evolution('quero agendar uma reunião'));
        $this->postJson($url, $this->evolution('terça à tarde, online'));
        $task = Task::where('title', 'like', 'Agendar atendimento (WhatsApp)%')->first();
        $this->assertNotNull($task);
        $this->assertStringContainsString('terça à tarde', $task->description);
        $this->assertSame([$this->admin->id], $task->users->pluck('id')->all());

        $pdf = base64_encode('%PDF-1.4 teste');
        $this->postJson($url, $this->evolution('', id: 'DOC1', message: ['documentMessage' => ['fileName' => 'rg.pdf', 'mimetype' => 'application/pdf'], 'base64' => $pdf]));
        $this->postJson($url, $this->evolution('', id: 'DOC1', message: ['documentMessage' => ['fileName' => 'rg.pdf', 'mimetype' => 'application/pdf'], 'base64' => $pdf])); // repetido
        $doc = WhatsappMessage::where('external_id', 'DOC1')->sole();
        Storage::disk('local')->assertExists($doc->media_path);
        $this->assertTrue(Task::where('title', 'like', 'Documento recebido%')->exists());

        $this->postJson($url, $this->evolution('quero falar com um atendente'));
        $conv = WhatsappConversation::first();
        $this->assertSame('humano', $conv->mode);
        $before = count($this->sentTexts());
        $this->postJson($url, $this->evolution('alô?'));
        $this->assertCount($before, $this->sentTexts()); // robô fica quieto com a equipe

        // grupos e mensagens enviadas por nós são ignorados
        $this->postJson($url, ['event' => 'messages.upsert', 'data' => ['key' => ['remoteJid' => '123@g.us', 'fromMe' => false, 'id' => 'G1'], 'message' => ['conversation' => 'oi']]])->assertJson(['received' => 0]);
        $this->postJson('/api/whatsapp/token-errado', $this->evolution('oi'))->assertNotFound();
    }

    public function test_team_inbox_reply_and_mode(): void
    {
        $this->fakeServices();
        $channel = $this->channel();
        $this->postJson("/api/whatsapp/{$channel->webhook_token}", $this->evolution('Oi'));
        $conv = WhatsappConversation::first();

        $user = User::where('role', '!=', 'admin')->first();
        $this->actingAs($user)->get('/whatsapp')->assertOk()->assertSee('Joana Prado');
        $this->get("/whatsapp/{$conv->id}")->assertOk()->assertSee('Assumir conversa');
        $this->assertSame(0, $conv->fresh()->unread);
        $this->getJson("/whatsapp/{$conv->id}/novas?after=0")->assertOk()->assertJsonStructure(['html', 'last_id']);

        $this->post("/whatsapp/{$conv->id}/mensagens", ['body' => 'Olá Joana, aqui é da equipe.'])->assertSessionHas('success');
        $this->assertSame('humano', $conv->fresh()->mode);
        $this->assertSame($user->id, $conv->fresh()->assigned_user_id);
        $this->assertContains('Olá Joana, aqui é da equipe.', $this->sentTexts());

        $this->patch("/whatsapp/{$conv->id}/modo", ['mode' => 'bot'])->assertRedirect();
        $this->assertSame('bot', $conv->fresh()->mode);
        $this->get('/whatsapp/canais')->assertForbidden();
    }

    public function test_meta_verification_signature_and_parsing(): void
    {
        $this->fakeServices();
        $channel = $this->channel(['driver' => 'meta', 'base_url' => null, 'instance' => null, 'phone_number_id' => '123456', 'api_key' => 'meta-token', 'app_secret' => 'segredo-app']);
        $url = "/api/whatsapp/{$channel->webhook_token}";

        $this->get("{$url}?hub.mode=subscribe&hub.verify_token={$channel->verify_token}&hub.challenge=987")->assertOk()->assertSee('987');
        $this->get("{$url}?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=987")->assertForbidden();

        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => '5521975318642', 'profile' => ['name' => 'Joana']]],
            'messages' => [['from' => '5521975318642', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'Oi']]],
        ]]]]]];
        $body = json_encode($payload);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=errada'], $body)->assertForbidden();
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'segredo-app')], $body)->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com/v21.0/123456/messages') && $r->hasHeader('Authorization', 'Bearer meta-token') && $r['to'] === '5521975318642');
        $this->assertSame('Joana', WhatsappConversation::first()->profile_name);
    }

    public function test_client_is_notified_of_new_movement_when_enabled(): void
    {
        $this->fakeServices();
        $this->channel(['notify_movements' => true]);
        $this->case->movements()->create(['date' => today(), 'description' => 'Audiência designada para 10/12']);

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('Audiência designada', $texts[0]); // sem IA: mensagem padrão
        $this->assertSame('sistema', WhatsappMessage::latest('id')->first()->sender);
    }

    public function test_channel_admin_pages_and_connection_test(): void
    {
        Http::fake(['evo.example.com/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]), 'evo.example.com/*' => Http::response(['key' => ['id' => 'X']])]);
        $this->actingAs($this->admin);
        $this->get('/whatsapp')->assertOk()->assertSee('Conecte o WhatsApp do escritório');
        $this->get('/whatsapp/canais/novo?tipo=evolution')->assertOk();
        $this->get('/whatsapp/canais/novo?tipo=meta')->assertOk();

        $this->post('/whatsapp/canais', ['name' => 'Escritório', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'escritorio', 'active' => 1, 'bot_enabled' => 1])
            ->assertSessionHasErrors('api_key');
        $this->post('/whatsapp/canais', ['name' => 'Escritório', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'escritorio', 'api_key' => 'k1', 'active' => 1, 'bot_enabled' => 1])->assertRedirect();
        $channel = WhatsappChannel::first();
        $this->get("/whatsapp/canais/{$channel->id}/editar")->assertOk()->assertSee($channel->webhook_url)->assertDontSee('k1');

        $this->post("/whatsapp/canais/{$channel->id}/testar", ['phone' => '(21) 97531-8642'])->assertSessionHas('success');
        $this->assertTrue($channel->fresh()->last_test_ok);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendText') && $r['number'] === '5521975318642');

        $this->put("/whatsapp/canais/{$channel->id}", ['name' => 'Novo nome', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'escritorio', 'active' => 1])->assertRedirect();
        $this->assertSame('k1', $channel->fresh()->api_key);
        $this->assertFalse($channel->fresh()->bot_enabled);
        $this->get('/integracoes')->assertOk()->assertSee('WhatsApp e autoatendimento');
    }
}
