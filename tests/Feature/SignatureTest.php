<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DocumentTemplate;
use App\Models\LegalCase;
use App\Models\SignatureProvider;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Integrations\Routine;
use App\Support\Integrations\Webhooks;
use App\Support\Signature\Signatures;
use App\Support\Signature\TextPdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lawyer;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->withoutDefer();
        Signatures::forget();
        $this->admin = User::where('role', 'admin')->first();
        $this->lawyer = User::create(['name' => 'Ana Lima', 'email' => 'ana@escritorio.com.br', 'password' => 'secreta123', 'role' => 'advogado', 'active' => true]);
        $this->client = Contact::create(['name' => 'Maria Souza', 'category' => 'cliente', 'email' => 'maria@exemplo.com', 'phone' => '(11) 98888-7777', 'document' => '123.456.789-09']);
    }

    private function clicksign(): SignatureProvider
    {
        $p = new SignatureProvider(['driver' => 'clicksign', 'environment' => 'sandbox', 'active' => true, 'is_default' => true]);
        $p->api_token = 'tok-clicksign';
        $p->save();

        return $p;
    }

    private function d4sign(): SignatureProvider
    {
        $p = new SignatureProvider(['driver' => 'd4sign', 'environment' => 'sandbox', 'active' => true, 'safe_uuid' => 'safe-1234', 'safe_name' => 'Contratos']);
        $p->api_token = 'live_tok';
        $p->crypt_key = 'live_crypt';
        $p->save();

        return $p;
    }

    private string $envelopeStatus = 'running';

    private function fakeClickSign(string $envelopeStatus = 'running'): void
    {
        $this->envelopeStatus = $envelopeStatus;
        Http::fake([
            'sandbox.clicksign.com/api/v3/envelopes/env-1/documents/doc-1/events' => Http::response(['data' => []]),
            'sandbox.clicksign.com/api/v3/envelopes/env-1/documents/doc-1' => Http::response(['data' => ['id' => 'doc-1', 'links' => ['files' => ['signed' => 'https://files.clicksign.test/signed.pdf']]]]),
            'sandbox.clicksign.com/api/v3/envelopes/env-1/documents' => Http::response(['data' => ['id' => 'doc-1']], 201),
            'sandbox.clicksign.com/api/v3/envelopes/env-1/signers' => Http::sequence()->push(['data' => ['id' => 'sig-1']], 201)->push(['data' => ['id' => 'sig-2']], 201),
            'sandbox.clicksign.com/api/v3/envelopes/env-1/requirements' => Http::response(['data' => ['id' => 'req']], 201),
            'sandbox.clicksign.com/api/v3/envelopes/env-1/notifications' => Http::response(['data' => ['id' => 'n']], 201),
            'sandbox.clicksign.com/api/v3/envelopes/env-1' => fn () => Http::response(['data' => ['id' => 'env-1', 'attributes' => ['status' => $this->envelopeStatus]]]),
            'sandbox.clicksign.com/api/v3/envelopes' => Http::response(['data' => ['id' => 'env-1', 'attributes' => ['status' => 'draft']]], 201),
            'files.clicksign.test/*' => Http::response("%PDF-1.4\nassinado", 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    private function sendForm(array $extra = []): array
    {
        return $extra + [
            'title' => 'Contrato de honorários - Maria Souza',
            'provider' => 'clicksign',
            'source' => 'texto',
            'content' => "CONTRATO DE HONORÁRIOS\n\nCONTRATANTE: Maria Souza, CPF 123.456.789-09.\n\nCLÁUSULA 1ª – DO OBJETO\nPrestação de serviços advocatícios (ação de cobrança).",
            'contact_id' => $this->client->id,
            'signers' => [
                ['name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'phone' => '(11) 98888-7777', 'document' => '123.456.789-09', 'role' => 'parte', 'auth' => 'email'],
                ['name' => 'Ana Lima', 'email' => 'ana@escritorio.com.br', 'role' => 'advogado', 'auth' => 'email'],
            ],
        ];
    }

    public function test_text_pdf_is_a_valid_multi_page_document(): void
    {
        $text = "PROCURAÇÃO AD JUDICIA\n\n".str_repeat("Pelo presente instrumento particular de mandato, o(a) OUTORGANTE nomeia e constitui seu bastante procurador o OUTORGADO – poderes amplos.\n\n", 40);
        $pdf = TextPdf::fromText($text, 'Procuração', 'ADVOGABT');

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertGreaterThanOrEqual(2, preg_match_all('#/Type /Page /#', $pdf));
        $this->assertStringContainsString(mb_convert_encoding('PROCURAÇÃO', 'Windows-1252', 'UTF-8'), $pdf);
        // xref aponta para os objetos corretos
        preg_match('/startxref\n(\d+)/', $pdf, $m);
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
    }

    public function test_create_form_is_prefilled_from_template_and_client(): void
    {
        $template = DocumentTemplate::where('category', 'contrato')->first();
        $this->actingAs($this->lawyer)->get(route('signatures.create', ['modelo' => $template->id, 'contact_id' => $this->client->id]))
            ->assertOk()
            ->assertSee('CONTRATANTE: Maria Souza', false)
            ->assertSee('maria@exemplo.com')
            ->assertSee('ana@escritorio.com.br')
            ->assertSee('Certificado digital ICP-Brasil');

        $this->actingAs($this->lawyer)->get(route('templates.show', $template))->assertOk()->assertSee('Enviar para assinatura');
    }

    public function test_sends_to_clicksign_and_completes_by_routine(): void
    {
        $this->clicksign();
        $this->fakeClickSign();
        $hook = Webhook::create(['name' => 'n8n', 'url' => 'https://n8n.exemplo.com/hook', 'events' => ['assinatura.concluida'], 'active' => true, 'secret' => 'segredo-webhook']);
        Webhooks::flush();

        $this->actingAs($this->lawyer)->post(route('signatures.store'), $this->sendForm(['icp_brasil' => '1', 'deadline_at' => now()->addDays(10)->toDateString()]))
            ->assertRedirect()->assertSessionHas('success');

        $sr = SignatureRequest::with('signers')->sole();
        $this->assertSame('enviado', $sr->status);
        $this->assertSame('env-1', $sr->external_id);
        $this->assertSame('doc-1', $sr->external_document_id);
        $this->assertSame(['sig-1', 'sig-2'], $sr->signers->pluck('external_id')->all());
        Storage::disk('local')->assertExists($sr->file_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($sr->file_path));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://sandbox.clicksign.com/api/v3/envelopes'
            && $r->header('Authorization')[0] === 'tok-clicksign' && $r['data']['attributes']['locale'] === 'pt-BR' && isset($r['data']['attributes']['deadline_at']));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/requirements') && ($r['data']['attributes']['auth'] ?? null) === 'icp_brasil');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/requirements') && ($r['data']['attributes']['role'] ?? null) === 'lawyer');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r['data']['attributes']['status'] === 'running');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/signers') && ($r['data']['attributes']['documentation'] ?? null) === '123.456.789-09');

        // Ainda em andamento: a rotina consulta e nada muda.
        SignatureRequest::whereKey($sr->id)->update(['next_check_at' => now()->subMinute()]);
        Routine::run();
        $this->assertSame('enviado', $sr->fresh()->status);

        // A ClickSign finaliza o envelope: a rotina baixa o PDF assinado e avisa o webhook.
        $this->fakeClickSign('closed');
        SignatureRequest::whereKey($sr->id)->update(['next_check_at' => now()->subMinute()]);
        $result = Routine::run();
        $this->assertSame(1, $result['signatures']);
        $sr->refresh();
        $this->assertSame('assinado', $sr->status);
        $this->assertNotNull($sr->completed_at);
        $this->assertSame("%PDF-1.4\nassinado", Storage::disk('local')->get($sr->signed_path));
        $this->assertSame(0, $sr->signers()->where('status', '!=', 'assinado')->count());
        $this->assertSame(1, WebhookDelivery::where('webhook_id', $hook->id)->where('event', 'assinatura.concluida')->count());

        $this->actingAs($this->lawyer)->get(route('signatures.download', [$sr, 'assinado']))->assertOk();
        $this->actingAs($this->lawyer)->get(route('signatures.show', $sr))->assertOk()->assertSee('PDF assinado');
        $this->actingAs($this->lawyer)->get(route('contacts.show', $this->client))->assertOk()->assertSee('Contrato de honorários - Maria Souza');
    }

    public function test_platform_webhook_triggers_status_check(): void
    {
        $p = $this->clicksign();
        $this->fakeClickSign();
        $this->actingAs($this->lawyer)->post(route('signatures.store'), $this->sendForm());
        $sr = SignatureRequest::sole();

        $this->fakeClickSign('closed');
        $this->postJson('/api/assinatura/'.$p->webhook_token, ['event' => ['name' => 'auto_close'], 'document' => ['key' => 'doc-1']])
            ->assertOk()->assertJson(['documentos' => 1]);
        $this->assertSame('assinado', $sr->fresh()->status);
        $this->assertNotNull($p->fresh()->last_webhook_at);

        // Token desconhecido e ids que não são de nenhum documento.
        $this->postJson('/api/assinatura/'.str_repeat('x', 40), ['document' => ['key' => 'doc-1']])->assertNotFound();
        $this->postJson('/api/assinatura/'.$p->webhook_token, ['document' => ['key' => 'outro-documento']])->assertOk()->assertJson(['documentos' => 0]);
    }

    public function test_sends_to_d4sign_with_icp_and_completes(): void
    {
        $this->d4sign();
        Http::fake([
            'sandbox.d4sign.com.br/api/v1/documents/safe-1234/uploadbinary*' => Http::response(['uuid' => 'doc-d4']),
            'sandbox.d4sign.com.br/api/v1/documents/doc-d4/createlist*' => Http::response(['message' => [['key_signer' => 'ks-1', 'email' => 'maria@exemplo.com']]]),
            'sandbox.d4sign.com.br/api/v1/documents/doc-d4/sendtosigner*' => Http::response(['message' => 'File sent to successfully signing']),
            'sandbox.d4sign.com.br/api/v1/documents/doc-d4/list*' => Http::response([['list' => [['email' => 'maria@exemplo.com', 'signed' => '1', 'date_signed' => '2026-11-03 10:00:00']]]]),
            'sandbox.d4sign.com.br/api/v1/documents/doc-d4/download*' => Http::response(['url' => 'https://d4sign.test/file.pdf', 'name' => 'x']),
            'sandbox.d4sign.com.br/api/v1/documents/doc-d4*' => Http::response([['uuidDoc' => 'doc-d4', 'statusId' => '3', 'statusName' => 'Aguardando Assinaturas']]),
            'd4sign.test/*' => Http::response('%PDF-1.7 d4', 200),
        ]);

        $form = $this->sendForm(['provider' => 'd4sign', 'icp_brasil' => '1']);
        $form['signers'] = [$form['signers'][0]];
        $this->actingAs($this->lawyer)->post(route('signatures.store'), $form)->assertSessionHas('success');
        $sr = SignatureRequest::with('signers')->sole();
        $this->assertSame('doc-d4', $sr->external_id);
        $this->assertSame('ks-1', $sr->signers[0]->external_id);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/createlist') && $r['signers'][0]['certificadoicpbr'] === '1' && $r['signers'][0]['act'] === '4'
            && str_contains($r->url(), 'tokenAPI=live_tok'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/webhooks')); // localhost: sem webhook

        // Ainda aguardando: o signatário que já assinou é marcado.
        $this->actingAs($this->lawyer)->post(route('signatures.refresh', $sr))->assertSessionHas('success');
        $this->assertSame('enviado', $sr->fresh()->status);
        $this->assertSame('assinado', $sr->signers()->first()->status);
    }

    public function test_send_failure_keeps_document_and_allows_retry(): void
    {
        $p = $this->clicksign();
        $p->api_token = 'errado';
        $p->save();
        Http::fake(['sandbox.clicksign.com/*' => fn (HttpRequest $r) => $r->header('Authorization')[0] === 'errado'
            ? Http::response(['errors' => [['detail' => 'Access Token inválido']]], 401) : null]);

        $this->actingAs($this->lawyer)->post(route('signatures.store'), $this->sendForm())->assertSessionHas('error');
        $sr = SignatureRequest::sole();
        $this->assertSame('erro', $sr->status);
        $this->assertStringContainsString('Access Token inválido', $sr->last_error);
        $this->actingAs($this->lawyer)->get(route('signatures.show', $sr))->assertOk()->assertSee('Tentar enviar de novo');

        $p->api_token = 'tok-clicksign';
        $p->save();
        $this->fakeClickSign();
        $this->actingAs($this->lawyer)->post(route('signatures.resend', $sr))->assertSessionHas('success');
        $this->assertSame('enviado', $sr->fresh()->status);
    }

    public function test_manual_icp_brasil_flow_checks_the_signed_pdf(): void
    {
        $form = $this->sendForm(['provider' => 'manual']);
        unset($form['signers']);
        $this->actingAs($this->lawyer)->post(route('signatures.store'), $form)->assertSessionHas('success');
        $sr = SignatureRequest::sole();
        $this->assertTrue($sr->icp_brasil);
        $this->assertSame('enviado', $sr->status);
        $this->actingAs($this->lawyer)->get(route('signatures.download', [$sr, 'original']))->assertOk();

        // PDF sem assinatura digital é recusado.
        $plain = UploadedFile::fake()->createWithContent('assinado.pdf', TextPdf::fromText('teste'));
        $this->actingAs($this->lawyer)->post(route('signatures.attach', $sr), ['file' => $plain])->assertSessionHas('error');
        $this->assertSame('enviado', $sr->fresh()->status);

        // PDF com assinatura PAdES de certificado ICP-Brasil.
        $der = str_pad("\x30\x82 O=ICP-Brasil OU=AC SOLUTI CN=ANA LIMA", 200, "\x00");
        $signed = "%PDF-1.7\n1 0 obj << /Type /Sig /ByteRange [0 100 300 50] /Contents <".bin2hex($der)."> >>\nendobj\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('assinado.pdf', $signed);
        $this->assertSame(['signatures' => 1, 'icp' => true], Signatures::inspect($signed));
        $this->actingAs($this->lawyer)->post(route('signatures.attach', $sr), ['file' => $file])->assertSessionHas('success');
        $sr->refresh();
        $this->assertSame('assinado', $sr->status);
        Storage::disk('local')->assertExists($sr->signed_path);
    }

    public function test_settings_are_admin_only_and_keep_secrets(): void
    {
        $this->actingAs($this->lawyer)->get(route('signatures.settings'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('signatures.settings'))->assertOk()->assertSee('ClickSign')->assertSee('D4Sign');

        $this->actingAs($this->admin)->post(route('signatures.providers.save', 'clicksign'), ['environment' => 'sandbox', 'api_token' => 'segredo-123', 'active' => '1'])
            ->assertRedirect(route('signatures.settings'));
        $p = SignatureProvider::forDriver('clicksign');
        $this->assertSame('segredo-123', $p->api_token);
        $this->assertNotSame('segredo-123', $p->getRawOriginal('api_token'));

        // Salvar de novo sem a chave mantém a anterior.
        $this->actingAs($this->admin)->post(route('signatures.providers.save', 'clicksign'), ['environment' => 'producao', 'active' => '1']);
        $this->assertSame('segredo-123', $p->fresh()->api_token);

        Http::fake(['app.clicksign.com/*' => Http::response(['data' => []])]);
        $this->actingAs($this->admin)->post(route('signatures.providers.test', 'clicksign'))->assertSessionHas('success');
        $this->assertTrue($p->fresh()->last_test_ok);

        // D4Sign: lista os cofres com as chaves digitadas.
        Http::fake(['sandbox.d4sign.com.br/api/v1/safes*' => Http::response([['uuid_safe' => 'safe-9', 'name-safe' => 'Contratos']])]);
        $this->actingAs($this->admin)->postJson(route('signatures.safes'), ['environment' => 'sandbox', 'api_token' => 'a', 'crypt_key' => 'b'])
            ->assertOk()->assertJsonPath('safes.0.uuid', 'safe-9');

        $this->actingAs($this->admin)->get(route('integrations.index'))->assertOk()->assertSee('Documentos enviados');
    }

    public function test_pages_render(): void
    {
        $case = LegalCase::first() ?? LegalCase::create(['title' => 'Ação de cobrança', 'contact_id' => $this->client->id, 'area' => 'civel', 'phase' => 'inicial', 'status' => 'ativo']);
        $this->actingAs($this->lawyer)->get(route('signatures.index'))->assertOk();
        $this->actingAs($this->lawyer)->get(route('signatures.create', ['legal_case_id' => $case->id]))->assertOk();
        $this->actingAs($this->lawyer)->get(route('cases.show', $case))->assertOk()->assertSee('Assinaturas');
    }
}
