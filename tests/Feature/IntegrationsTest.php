<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Contact;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Integrations\Routine;
use App\Support\Integrations\Webhooks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('role', 'admin')->first();
        $this->withoutDefer();
        Http::preventStrayRequests();
    }

    private function token(string $access = 'escrita'): string
    {
        return ApiToken::issue('n8n', $access, $this->admin->id)[1];
    }

    private function api(string $token): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function webhook(array $events = ['*']): Webhook
    {
        return Webhook::create(['name' => 'n8n', 'url' => 'https://n8n.example.com/webhook/abc', 'secret' => 'whsec_test_secret_123456', 'events' => $events, 'active' => true]);
    }

    public function test_pages_render_for_admin_and_overview_for_everyone(): void
    {
        $this->actingAs($this->admin);
        $hook = $this->webhook();
        foreach (['/integracoes', '/integracoes/api', '/integracoes/tokens', '/integracoes/webhooks', '/integracoes/webhooks/novo', "/integracoes/webhooks/{$hook->id}", "/integracoes/webhooks/{$hook->id}/editar"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/integracoes')->assertSee('Tribunais')->assertSee('Assinatura digital')->assertSee('WhatsApp e autoatendimento')->assertSee('Cobrança e notas fiscais')->assertDontSee('Em breve');

        $user = User::where('role', '!=', 'admin')->first();
        $this->actingAs($user)->get('/integracoes')->assertOk();
        $this->get('/integracoes/api')->assertOk();
        $this->get('/integracoes/tokens')->assertForbidden();
        $this->get('/integracoes/webhooks')->assertForbidden();
    }

    public function test_token_is_shown_once_and_stored_hashed(): void
    {
        $this->actingAs($this->admin)->post('/integracoes/tokens', ['name' => 'Zapier', 'access' => 'leitura'])
            ->assertRedirect('/integracoes/tokens')->assertSessionHas('new_token');
        $plain = session('new_token')['plain'];
        $this->assertStringStartsWith('advbt_', $plain);
        $token = ApiToken::where('name', 'Zapier')->first();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $plain]);

        $this->api($plain)->getJson('/api/v1/status')->assertOk()->assertJsonPath('token.can_write', false);

        $this->delete("/integracoes/tokens/{$token->id}")->assertRedirect();
        $this->api($plain)->getJson('/api/v1/status')->assertUnauthorized();
    }

    public function test_api_rejects_missing_invalid_and_read_only_tokens(): void
    {
        $this->getJson('/api/v1/processos')->assertUnauthorized();
        $this->api('advbt_invalido')->getJson('/api/v1/processos')->assertUnauthorized();

        $read = $this->token('leitura');
        $this->api($read)->getJson('/api/v1/processos')->assertOk()->assertJsonStructure(['data', 'meta' => ['total'], 'links']);
        $this->api($read)->postJson('/api/v1/contatos', ['name' => 'X'])->assertForbidden();

        [$expired, $plain] = ApiToken::issue('velho', 'escrita', $this->admin->id, now()->subDay());
        $this->api($plain)->getJson('/api/v1/status')->assertUnauthorized();

        $this->api($read)->getJson('/api/v1/processos/999999')->assertNotFound()->assertJsonPath('message', 'Registro não encontrado.');
    }

    public function test_api_reads_every_resource(): void
    {
        $t = $this->token('leitura');
        foreach (['/api/v1/status', '/api/v1/eventos', '/api/v1/processos', '/api/v1/contatos', '/api/v1/tarefas', '/api/v1/prazos?days=30', '/api/v1/agenda', '/api/v1/financeiro?situation=atrasado', '/api/v1/webhooks'] as $url) {
            $this->api($t)->getJson($url)->assertOk();
        }
        $case = LegalCase::first();
        $this->api($t)->getJson("/api/v1/processos/{$case->id}")->assertOk()->assertJsonPath('data.id', $case->id)->assertJsonStructure(['data' => ['movements']]);
        $contact = Contact::first();
        $this->api($t)->getJson("/api/v1/contatos/{$contact->id}")->assertOk()->assertJsonStructure(['data' => ['cases']]);
        $this->api($t)->getJson('/api/v1/processos?updated_since=lixo')->assertStatus(422);
    }

    public function test_contact_lookup_by_whatsapp_number_ignores_formatting(): void
    {
        $contact = Contact::create(['name' => 'Cliente WhatsApp', 'whatsapp' => '(21) 97531-8642']);
        $t = $this->token('leitura');

        $this->api($t)->getJson('/api/v1/contatos?phone=5521975318642')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $contact->id);
        $this->api($t)->getJson('/api/v1/contatos?phone=2175318642')->assertJsonPath('data.0.id', $contact->id); // sem o 9º dígito
        $this->api($t)->getJson('/api/v1/contatos?phone=123')->assertJsonPath('meta.total', 0);
    }

    public function test_api_writes_records_as_token_owner(): void
    {
        $t = $this->token();
        $contactId = $this->api($t)->postJson('/api/v1/contatos', ['name' => 'Lead do site', 'crm_stage' => 'lead', 'whatsapp' => '11999990000'])
            ->assertCreated()->assertJsonPath('data.crm_stage', 'lead')->json('data.id');

        $caseId = $this->api($t)->postJson('/api/v1/processos', ['title' => 'Ação de cobrança', 'contact_id' => $contactId])
            ->assertCreated()->assertJsonPath('data.status', 'ativo')->assertJsonPath('data.responsible.id', $this->admin->id)->json('data.id');
        $this->api($t)->patchJson("/api/v1/processos/{$caseId}", ['phase' => 'recursal'])->assertOk()->assertJsonPath('data.phase', 'recursal');
        $this->api($t)->postJson("/api/v1/processos/{$caseId}/andamentos", ['description' => 'Juntada de petição'])
            ->assertCreated()->assertJsonPath('data.author', $this->admin->name);

        $taskId = $this->api($t)->postJson('/api/v1/tarefas', ['title' => 'Contestação', 'due_at' => today()->addDays(2)->toDateString(), 'is_deadline' => true, 'legal_case_id' => $caseId])
            ->assertCreated()->assertJsonPath('data.assignees.0.id', $this->admin->id)->json('data.id');
        $this->api($t)->patchJson("/api/v1/tarefas/{$taskId}", ['status' => 'concluida'])->assertOk()->assertJsonPath('data.status', 'concluida');

        $this->api($t)->postJson('/api/v1/agenda', ['title' => 'Audiência', 'type' => 'audiencia', 'starts_at' => today()->addWeek()->setTime(14, 0)->toDateTimeString()])->assertCreated();

        $entryId = $this->api($t)->postJson('/api/v1/financeiro', ['type' => 'receita', 'description' => 'Honorários', 'amount' => 1500, 'due_date' => today()->toDateString(), 'contact_id' => $contactId])
            ->assertCreated()->assertJsonPath('data.situation', 'aberto')->json('data.id');
        $this->api($t)->postJson("/api/v1/financeiro/{$entryId}/baixa", ['payment_method' => 'pix'])->assertOk()->assertJsonPath('data.situation', 'pago');

        $this->api($t)->postJson('/api/v1/processos', [])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_events_are_delivered_signed_to_subscribed_webhooks(): void
    {
        Http::fake(['n8n.example.com/*' => Http::response(['ok' => true])]);
        $this->webhook(['processo.criado', 'pagamento.recebido']);

        $case = LegalCase::create(['title' => 'Novo processo via tela']);
        Contact::create(['name' => 'Não assinado']); // evento não assinado: não envia

        $entry = FinancialEntry::create(['type' => 'receita', 'description' => 'Honorários', 'amount' => 900, 'due_date' => today()]);
        $entry->update(['paid_at' => today()]);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($case) {
            $body = $request->body();
            $json = json_decode($body, true);

            return $request->hasHeader('X-Advogabt-Event', 'processo.criado')
                && $request->header('X-Advogabt-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, 'whsec_test_secret_123456')
                && $json['event'] === 'processo.criado'
                && $json['data']['id'] === $case->id
                && $json['data']['status'] === 'ativo';
        });
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Advogabt-Event', 'pagamento.recebido') && $r['data']['amount'] == 900);
        $this->assertSame(2, WebhookDelivery::where('success', true)->count());
    }

    public function test_failed_delivery_is_recorded_and_retried(): void
    {
        Http::fakeSequence('n8n.example.com/*')->push('erro', 500)->push(['ok' => true]);
        $hook = $this->webhook(['contato.criado']);

        Contact::create(['name' => 'Fulano']);
        $delivery = WebhookDelivery::first();
        $this->assertFalse($delivery->success);
        $this->assertSame(500, $delivery->response_status);
        $this->assertSame(1, $hook->fresh()->consecutive_failures);

        $this->travel(2)->minutes();
        $this->assertSame(1, Webhooks::retryFailed());
        $this->assertTrue($delivery->fresh()->success);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(0, $hook->fresh()->consecutive_failures);
    }

    public function test_deadline_warning_is_sent_once_and_again_when_due_date_changes(): void
    {
        Http::fake(['n8n.example.com/*' => Http::response('ok')]);
        $task = Task::create(['title' => 'Prazo de recurso', 'is_deadline' => true, 'due_at' => today()->addDay()->setTime(18, 0)]);
        Task::create(['title' => 'Prazo distante', 'is_deadline' => true, 'due_at' => today()->addDays(30)]);

        $this->assertSame(0, Routine::run()['deadlines']); // sem webhook: nada a avisar e nada é marcado
        $this->assertNull($task->fresh()->deadline_alerted_at);

        $this->webhook(['prazo.vencendo']);
        $first = Routine::run()['deadlines'];
        $this->assertGreaterThanOrEqual(1, $first);
        $this->assertNotNull($task->fresh()->deadline_alerted_at);
        Http::assertSent(fn (Request $r) => $r['event'] === 'prazo.vencendo' && $r['data']['id'] === $task->id && $r['data']['days_left'] === 1);
        $this->assertSame(0, Routine::run()['deadlines']);

        $task->update(['due_at' => today()->addDays(2)]);
        $this->assertNull($task->fresh()->deadline_alerted_at);
        $this->assertSame(1, Routine::run()['deadlines']);
    }

    public function test_webhook_crud_test_button_and_api_subscription(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->actingAs($this->admin);

        $this->post('/integracoes/webhooks', ['name' => 'Make', 'url' => 'https://hook.make.com/xyz', 'events' => ['andamento.criado'], 'active' => 1])->assertRedirect();
        $hook = Webhook::where('name', 'Make')->first();
        $this->assertSame(['andamento.criado'], $hook->events);
        $this->assertStringStartsWith('whsec_', $hook->secret);

        $this->put("/integracoes/webhooks/{$hook->id}", ['name' => 'Make', 'url' => 'https://hook.make.com/xyz', 'all_events' => 1, 'active' => 1])->assertRedirect();
        $this->assertSame(['*'], $hook->fresh()->events);
        $this->post('/integracoes/webhooks', ['name' => 'Sem eventos', 'url' => 'https://x.com'])->assertSessionHasErrors('events');

        $this->post("/integracoes/webhooks/{$hook->id}/testar")->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r['event'] === 'teste');

        $this->post('/integracoes/rotina')->assertSessionHas('success');

        // Assinatura via API (gatilho instantâneo do Zapier/Make)
        $t = $this->token();
        $id = $this->api($t)->postJson('/api/v1/webhooks', ['url' => 'https://hooks.zapier.com/1', 'events' => ['tarefa.criada']])
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'secret']])->json('data.id');
        $this->api($t)->getJson('/api/v1/webhooks')->assertJsonCount(1, 'data');
        $this->api($this->token())->deleteJson("/api/v1/webhooks/{$id}")->assertNotFound(); // outro token não apaga
        $this->api($t)->deleteJson("/api/v1/webhooks/{$id}")->assertNoContent();
    }
}
