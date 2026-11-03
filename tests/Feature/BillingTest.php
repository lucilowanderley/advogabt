<?php

namespace Tests\Feature;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use App\Models\FinancialEntry;
use App\Models\FiscalInvoice;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Billing\Billing;
use App\Support\Integrations\Routine;
use App\Support\Integrations\Webhooks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lawyer;

    private Contact $client;

    private FinancialEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        $this->withoutDefer();
        Billing::forget();
        Cache::flush();
        $this->admin = User::where('role', 'admin')->first();
        $this->lawyer = User::create(['name' => 'Ana Lima', 'email' => 'ana@escritorio.com.br', 'password' => 'secreta123', 'role' => 'advogado', 'active' => true]);
        $this->client = Contact::create([
            'name' => 'Maria Souza', 'category' => 'cliente', 'email' => 'maria@exemplo.com', 'whatsapp' => '(11) 98888-7777',
            'document' => '123.456.789-09', 'address' => 'Rua das Flores, 10', 'city' => 'São Paulo', 'state' => 'SP', 'zip_code' => '01001-000',
        ]);
        $this->entry = FinancialEntry::create([
            'type' => 'receita', 'description' => 'Honorários iniciais', 'category' => 'honorarios_contratuais', 'amount' => 1500,
            'due_date' => today()->addDays(10), 'contact_id' => $this->client->id, 'user_id' => $this->admin->id,
        ]);
    }

    private function asaas(array $settings = []): BillingProvider
    {
        $p = new BillingProvider(['driver' => 'asaas', 'environment' => 'sandbox', 'active' => true, 'is_default' => true,
            'fine_percent' => 2, 'interest_percent' => 1, 'notify_customer' => true]);
        $p->api_key = '$aact_hmlg_teste';
        $p->settings = $settings ?: null;
        $p->save();

        return $p;
    }

    private function inter(): BillingProvider
    {
        $p = new BillingProvider(['driver' => 'inter', 'environment' => 'sandbox', 'active' => true, 'client_id' => 'cli-123',
            'fine_percent' => 2, 'interest_percent' => 1]);
        $p->client_secret = 'segredo';
        $p->certificate = "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n";
        $p->private_key = "-----BEGIN PRIVATE KEY-----\nBBB\n-----END PRIVATE KEY-----\n";
        $p->save();

        return $p;
    }

    /** Asaas falso: cliente novo, pagamento criado, linha digitável e Pix; situação controlada por $status. */
    private function fakeAsaas(string &$status = 'PENDING', array &$sent = []): void
    {
        Http::fake(function (HttpRequest $r) use (&$status, &$sent) {
            $sent[] = $r->method().' '.$r->url();
            $url = $r->url();
            if (str_contains($url, '/customers') && $r->method() === 'GET') {
                return Http::response(['data' => []]);
            }
            if (str_contains($url, '/customers')) {
                return Http::response(['id' => 'cus_000001']);
            }
            if (str_ends_with(parse_url($url, PHP_URL_PATH), '/v3/payments') && $r->method() === 'POST') {
                return Http::response(['id' => 'pay_123', 'status' => 'PENDING', 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_123', 'bankSlipUrl' => 'https://sandbox.asaas.com/b/pdf/pay_123']);
            }
            if (str_contains($url, '/identificationField')) {
                return Http::response(['identificationField' => '23793381286000000000300000000400184340000150000', 'barCode' => '23791843400001500003381260000000000000000000']);
            }
            if (str_contains($url, '/pixQrCode')) {
                return Http::response(['payload' => '00020101021226820014br.gov.bcb.pix', 'encodedImage' => base64_encode('png')]);
            }
            if (str_contains($url, '/payments/pay_123') && $r->method() === 'DELETE') {
                return Http::response(['deleted' => true, 'id' => 'pay_123']);
            }
            if (str_contains($url, '/payments/pay_123')) {
                return Http::response(['id' => 'pay_123', 'status' => $status, 'value' => 1500, 'billingType' => 'PIX',
                    'clientPaymentDate' => $status === 'RECEIVED' ? today()->format('Y-m-d') : null, 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_123']);
            }
            if (str_ends_with(parse_url($url, PHP_URL_PATH), '/v3/invoices') && $r->method() === 'POST') {
                return Http::response(['id' => 'inv_777', 'status' => 'SCHEDULED']);
            }
            if (str_contains($url, '/invoices/inv_777/authorize')) {
                return Http::response(['id' => 'inv_777', 'status' => 'SYNCHRONIZED']);
            }
            if (str_contains($url, '/invoices/inv_777')) {
                return Http::response(['id' => 'inv_777', 'status' => 'AUTHORIZED', 'number' => '1234', 'validationCode' => 'ABCD-1234',
                    'pdfUrl' => 'https://sandbox.asaas.com/nf/inv_777.pdf', 'xmlUrl' => 'https://sandbox.asaas.com/nf/inv_777.xml']);
            }
            if (str_contains($url, '/myAccount/commercialInfo')) {
                return Http::response(['companyName' => 'Escritório Teste']);
            }
            if (str_contains($url, '/invoices/municipalServices')) {
                return Http::response(['data' => [['id' => '250', 'description' => '17.14 - Advocacia', 'issTax' => 5]]]);
            }

            return Http::response(['errors' => [['description' => 'rota não simulada: '.$url]]], 404);
        });
    }

    public function test_integration_card_is_available_and_pages_render(): void
    {
        $this->actingAs($this->admin)->get(route('integrations.index'))->assertOk()->assertSee('Cobrança e notas fiscais')->assertSee('Cobranças');
        $this->actingAs($this->admin)->get(route('billing.settings'))->assertOk()->assertSee('Banco Inter')->assertSee('Nota fiscal de serviço');
        $this->actingAs($this->lawyer)->get(route('billing.index'))->assertOk()->assertSee('Receitas para cobrar')->assertSee('Honorários iniciais');
        $this->actingAs($this->lawyer)->get(route('billing.invoices'))->assertOk();
        $this->actingAs($this->lawyer)->get(route('billing.settings'))->assertForbidden();
    }

    public function test_admin_saves_and_tests_asaas(): void
    {
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'asaas'), [
            'environment' => 'sandbox', 'api_key' => '$aact_hmlg_abc', 'fine_percent' => '2,00', 'interest_percent' => '1', 'active' => 1, 'is_default' => 1, 'notify_customer' => 1,
        ])->assertRedirect(route('billing.settings'));
        $p = BillingProvider::forDriver('asaas');
        $this->assertSame('$aact_hmlg_abc', $p->api_key);
        $this->assertNotSame('$aact_hmlg_abc', $p->getRawOriginal('api_key'), 'a chave fica criptografada');

        $this->actingAs($this->admin)->post(route('billing.providers.test', 'asaas'))->assertSessionHas('success');
        $this->assertTrue($p->fresh()->last_test_ok);
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api-sandbox.asaas.com/v3/') && $r->hasHeader('access_token', '$aact_hmlg_abc'));
    }

    public function test_fine_above_two_percent_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'asaas'), [
            'environment' => 'sandbox', 'api_key' => 'x', 'fine_percent' => '10', 'interest_percent' => '1',
        ])->assertSessionHasErrors('fine_percent');
    }

    public function test_generates_asaas_charge_with_slip_and_pix(): void
    {
        $this->asaas();
        $status = 'PENDING';
        $sent = [];
        $this->fakeAsaas($status, $sent);

        $this->actingAs($this->lawyer)->get(route('billing.create', ['lancamento' => $this->entry->id]))->assertOk()->assertSee('Maria Souza');
        $response = $this->actingAs($this->lawyer)->post(route('billing.store'), [
            'financial_entry_id' => $this->entry->id, 'provider' => 'asaas', 'method' => 'boleto_pix', 'due_date' => today()->addDays(10)->format('Y-m-d'),
        ]);
        $charge = BillingCharge::firstOrFail();
        $response->assertRedirect(route('billing.show', $charge));
        $this->assertSame('pendente', $charge->status);
        $this->assertSame('pay_123', $charge->external_id);
        $this->assertSame('cus_000001', $charge->external_customer_id);
        $this->assertStringStartsWith('2379', $charge->digitable_line);
        $this->assertNotEmpty($charge->pix_payload);
        $this->assertNotNull($charge->next_check_at);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/payments') && $r['billingType'] === 'BOLETO'
            && $r['value'] == 1500 && $r['customer'] === 'cus_000001' && $r['fine']['value'] == 2 && $r['interest']['value'] == 1);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/customers') && $r->method() === 'POST' && $r['cpfCnpj'] === '12345678909');

        $this->actingAs($this->lawyer)->get(route('billing.show', $charge))->assertOk()
            ->assertSee('Pix copia e cola')->assertSee('wa.me/5511988887777', false)->assertSee('Enviar pelo WhatsApp');
        $this->actingAs($this->lawyer)->get(route('finance.index', ['mes' => $this->entry->due_date->format('Y-m')]))->assertOk()->assertSee('Boleto/Pix');
        $this->actingAs($this->lawyer)->get(route('finance.edit', $this->entry))->assertOk()->assertSee('Cobrança #'.$charge->id);

        // Uma cobrança em aberto por lançamento.
        $this->actingAs($this->lawyer)->post(route('billing.store'), [
            'financial_entry_id' => $this->entry->id, 'provider' => 'asaas', 'method' => 'pix', 'due_date' => today()->addDays(10)->format('Y-m-d'),
        ])->assertSessionHas('error');
        $this->assertSame(1, BillingCharge::count());
    }

    public function test_requires_payer_document(): void
    {
        $this->asaas();
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $this->client->update(['document' => null]);

        $this->actingAs($this->lawyer)->post(route('billing.store'), [
            'financial_entry_id' => $this->entry->id, 'provider' => 'asaas', 'method' => 'boleto_pix', 'due_date' => today()->addDays(3)->format('Y-m-d'),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'CPF ou CNPJ'));
        $this->assertSame('erro', BillingCharge::first()->status);
    }

    public function test_routine_marks_entry_paid_and_issues_nfse_automatically(): void
    {
        $this->asaas(['nfse_enabled' => true, 'nfse_auto' => true, 'service_id' => '250', 'service_name' => 'Advocacia', 'iss' => 5,
            'description' => 'Honorários: {descricao} ({cliente})']);
        Webhook::create(['name' => 'n8n', 'url' => 'https://n8n.exemplo.com/hook', 'events' => ['pagamento.recebido', 'nota_fiscal.emitida'], 'active' => true, 'secret' => 'abc']);
        Webhooks::flush();
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $charge = Billing::issue($this->entry, 'asaas', 'boleto_pix', today()->addDays(10), $this->admin->id);

        $status = 'RECEIVED';
        $charge->forceFill(['next_check_at' => now()->subMinute()])->save();
        $result = Routine::run();
        $this->assertSame(1, $result['charges']);

        $charge->refresh();
        $this->entry->refresh();
        $this->assertSame('pago', $charge->status);
        $this->assertSame('pix', $charge->paid_with);
        $this->assertNotNull($this->entry->paid_at);
        $this->assertSame('pix', $this->entry->payment_method);
        $this->assertStringContainsString('Baixa automática', (string) $this->entry->notes);

        $inv = FiscalInvoice::firstOrFail();
        $this->assertSame('inv_777', $inv->external_id);
        $this->assertSame($charge->id, $inv->billing_charge_id);
        $this->assertSame('Honorários: Honorários iniciais (Maria Souza)', $inv->service_description);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/invoices') && $r['payment'] === 'pay_123'
            && $r['municipalServiceId'] === '250' && $r['taxes']['iss'] == 5);

        // A nota é autorizada pela prefeitura: a rotina confirma e avisa o webhook.
        $inv->forceFill(['next_check_at' => now()->subMinute()])->save();
        Routine::run();
        $inv->refresh();
        $this->assertSame('emitida', $inv->status);
        $this->assertSame('1234', $inv->number);
        $this->assertSame(1, WebhookDelivery::where('event', 'pagamento.recebido')->count());
        $this->assertSame(1, WebhookDelivery::where('event', 'nota_fiscal.emitida')->count());
    }

    public function test_platform_webhook_triggers_check(): void
    {
        $p = $this->asaas();
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $charge = Billing::issue($this->entry, 'asaas', 'pix', today()->addDays(10));
        $status = 'RECEIVED';

        $this->postJson('/api/cobranca/'.$p->webhook_token, ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_123', 'status' => 'RECEIVED']])
            ->assertOk()->assertJson(['ok' => true, 'itens' => 1]);
        $this->assertSame('pago', $charge->fresh()->status);
        $this->assertNotNull($this->entry->fresh()->paid_at);
        $this->assertNotNull($p->fresh()->last_webhook_at);

        $this->postJson('/api/cobranca/'.str_repeat('x', 40), [])->assertNotFound();
    }

    public function test_cancel_charge_cancels_on_platform(): void
    {
        $this->asaas();
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $charge = Billing::issue($this->entry, 'asaas', 'boleto_pix', today()->addDays(10), $this->lawyer->id);

        $this->actingAs($this->lawyer)->post(route('billing.cancel', $charge))->assertSessionHas('success');
        $this->assertSame('cancelado', $charge->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/payments/pay_123'));
        $this->assertNull($this->entry->fresh()->paid_at);
    }

    public function test_banco_inter_charge_with_certificate(): void
    {
        $this->inter();
        $sent = [];
        Http::fake(function (HttpRequest $r) use (&$sent) {
            $sent[] = $r;
            if (str_ends_with($r->url(), '/oauth/v2/token')) {
                return Http::response(['access_token' => 'tok-inter', 'expires_in' => 3600]);
            }
            if (str_ends_with($r->url(), '/cobranca/v3/cobrancas') && $r->method() === 'POST') {
                return Http::response(['codigoSolicitacao' => 'b6f1c7d0-1111-2222-3333-444455556666']);
            }
            if (str_contains($r->url(), '/cobranca/v3/cobrancas/b6f1c7d0-1111-2222-3333-444455556666')) {
                return Http::response([
                    'cobranca' => ['codigoSolicitacao' => 'b6f1c7d0-1111-2222-3333-444455556666', 'situacao' => 'A_RECEBER'],
                    'boleto' => ['linhaDigitavel' => '07790001161234567890123456789012345678901234', 'codigoBarras' => '0779'],
                    'pix' => ['pixCopiaECola' => '00020101021226990014br.gov.bcb.pix'],
                ]);
            }

            return Http::response([], 404);
        });

        $charge = Billing::issue($this->entry, 'inter', 'boleto_pix', today()->addDays(10));
        $this->assertSame('b6f1c7d0-1111-2222-3333-444455556666', $charge->external_id);
        $this->assertSame('07790001161234567890123456789012345678901234', $charge->fresh()->digitable_line);
        $this->assertNotEmpty($charge->fresh()->pix_payload);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/oauth/v2/token') && $r['client_id'] === 'cli-123'
            && str_contains((string) $r['scope'], 'boleto-cobranca.write'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/cobranca/v3/cobrancas') && $r->hasHeader('Authorization', 'Bearer tok-inter')
            && $r['pagador']['cpfCnpj'] === '12345678909' && $r['pagador']['tipoPessoa'] === 'FISICA' && $r['pagador']['cep'] === '01001000'
            && $r['multa']['codigo'] === 'PERCENTUAL' && $r['valorNominal'] == 1500);
        $this->assertSame([], glob(storage_path('app/private/billing-tmp/*')) ?: [], 'os arquivos do certificado são apagados depois da chamada');
    }

    public function test_banco_inter_requires_full_address(): void
    {
        $this->inter();
        Http::fake();
        $this->client->update(['zip_code' => null]);

        $this->actingAs($this->lawyer)->post(route('billing.store'), [
            'financial_entry_id' => $this->entry->id, 'provider' => 'inter', 'method' => 'boleto_pix', 'due_date' => today()->addDays(3)->format('Y-m-d'),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'CEP'));
        Http::assertNothingSent();
    }

    public function test_inter_only_offers_slip_with_pix(): void
    {
        $this->inter();
        $this->actingAs($this->lawyer)->post(route('billing.store'), [
            'financial_entry_id' => $this->entry->id, 'provider' => 'inter', 'method' => 'livre', 'due_date' => today()->addDays(3)->format('Y-m-d'),
        ])->assertSessionHas('error');
        $this->assertSame(0, BillingCharge::count());
    }

    public function test_admin_saves_inter_with_certificate_files(): void
    {
        $crt = UploadedFile::fake()->createWithContent('Inter_API_Certificado.crt', "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----");
        $key = UploadedFile::fake()->createWithContent('Inter_API_Chave.key', "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----");
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'inter'), [
            'environment' => 'producao', 'client_id' => 'abc', 'client_secret' => 'def', 'certificate_file' => $crt, 'private_key_file' => $key,
            'fine_percent' => '2', 'interest_percent' => '1', 'active' => 1,
        ])->assertRedirect(route('billing.settings'));
        $p = BillingProvider::forDriver('inter');
        $this->assertTrue($p->isConfigured());
        $this->assertStringContainsString('BEGIN CERTIFICATE', $p->certificate);

        $bad = UploadedFile::fake()->createWithContent('outro.crt', 'não é um certificado');
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'inter'), [
            'environment' => 'producao', 'client_id' => 'abc', 'certificate_file' => $bad, 'fine_percent' => '2', 'interest_percent' => '1',
        ])->assertSessionHas('error');
    }

    public function test_manual_nfse_for_entry_paid_by_hand(): void
    {
        $this->asaas(['nfse_enabled' => true, 'service_code' => '17.14', 'service_name' => 'Advocacia', 'iss' => 2]);
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $this->entry->update(['paid_at' => today()]);

        $this->actingAs($this->lawyer)->post(route('billing.invoices.store'), ['financial_entry_id' => $this->entry->id])->assertSessionHas('success');
        $inv = FiscalInvoice::firstOrFail();
        $this->assertSame('processando', $inv->status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/invoices') && $r['customer'] === 'cus_000001'
            && $r['municipalServiceCode'] === '17.14' && ! isset($r['payment']));

        // Não emite duas notas para o mesmo lançamento.
        $this->actingAs($this->lawyer)->post(route('billing.invoices.store'), ['financial_entry_id' => $this->entry->id])->assertSessionHas('error');

        $this->actingAs($this->lawyer)->post(route('billing.invoices.refresh', $inv))->assertSessionHas('success');
        $this->assertSame('emitida', $inv->fresh()->status);
        $this->actingAs($this->lawyer)->get(route('billing.invoices'))->assertOk()->assertSee('Nº 1234');
        $this->actingAs($this->lawyer)->post(route('billing.invoices.cancel', $inv))->assertForbidden();
    }

    public function test_nfse_service_search_and_settings(): void
    {
        $this->asaas();
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $this->actingAs($this->admin)->post(route('billing.nfse.services'), ['busca' => 'advoca'])
            ->assertRedirect(route('billing.settings'))->assertSessionHas('nfse_services');
        $this->actingAs($this->admin)->post(route('billing.nfse.save'), [
            'nfse_enabled' => 1, 'nfse_auto' => 1, 'service' => '250|17.14 - Advocacia|5', 'description' => 'Honorários: {descricao}',
        ])->assertSessionHas('success');
        $p = BillingProvider::forDriver('asaas');
        $this->assertSame('250', $p->setting('service_id'));
        $this->assertEquals(5, $p->setting('iss'));
        $this->assertTrue($p->setting('nfse_auto'));
        $this->assertNotNull(BillingProvider::nfse());
    }

    public function test_overdue_charge_dispatches_webhook_once(): void
    {
        $this->asaas();
        Webhook::create(['name' => 'n8n', 'url' => 'https://n8n.exemplo.com/hook', 'events' => ['cobranca.vencida'], 'active' => true, 'secret' => 'abc']);
        Webhooks::flush();
        $status = 'PENDING';
        $this->fakeAsaas($status);
        $charge = Billing::issue($this->entry, 'asaas', 'boleto_pix', today()->addDays(1));
        $status = 'OVERDUE';
        Billing::refresh($charge);
        Billing::refresh($charge->fresh());
        $this->assertSame('vencido', $charge->fresh()->status);
        $this->assertSame(1, WebhookDelivery::where('event', 'cobranca.vencida')->count());
    }
}
