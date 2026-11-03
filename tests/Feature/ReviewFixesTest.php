<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\BillingCharge;
use App\Models\CaseMovement;
use App\Models\Contact;
use App\Models\CourtSetting;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Support\Billing\Billing;
use App\Support\Courts\Cnj;
use App\Support\Courts\Courts;
use App\Support\Format;
use App\Support\Integrations\Webhooks;
use App\Support\Whatsapp\WhatsappClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Correções da revisão geral do código. */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        $this->withoutDefer();
        $this->admin = User::where('role', 'admin')->first();
    }

    public function test_money_with_thousand_dots_is_not_read_as_decimal(): void
    {
        $this->assertSame(1500.0, Format::parseMoney('1.500'));
        $this->assertSame(1234567.0, Format::parseMoney('1.234.567'));
        $this->assertSame(1500.5, Format::parseMoney('1.500,50'));
        $this->assertSame(1500.0, Format::parseMoney('1500.00'));
        $this->assertSame(1.5, Format::parseMoney('1.5'));
        $this->assertSame(250.0, Format::parseMoney('R$ 250'));
        $this->assertSame(99.9, Format::parseMoney(99.9));
        $this->assertNull(Format::parseMoney(''));

        $this->actingAs($this->admin)->post(route('finance.store'), [
            'type' => 'receita', 'description' => 'Honorários', 'amount' => '1.500', 'due_date' => today()->toDateString(),
        ])->assertRedirect();
        $this->assertEquals(1500, (float) FinancialEntry::where('description', 'Honorários')->value('amount'));
    }

    public function test_whatsapp_number_from_another_area_code_is_not_the_client(): void
    {
        $client = Contact::create(['name' => 'Cliente SP', 'category' => 'cliente', 'whatsapp' => '(11) 98123-4567']);
        $noDdd = Contact::create(['name' => 'Sem DDD', 'category' => 'lead', 'phone' => '3131-2929']);

        $this->assertSame([$client->id], Contact::idsByPhone('5511981234567')->all());
        $this->assertSame([$client->id], Contact::idsByPhone('551181234567')->all(), 'sem o 9º dígito');
        $this->assertSame([$client->id], Contact::idsByPhone('011 98123-4567')->all());
        $this->assertSame([], Contact::idsByPhone('5521981234567')->all(), 'mesmo número, outro DDD');
        $this->assertNull(Contact::findByPhone('5521981234567'));
        // Cadastro sem DDD continua sendo achado pelos 8 últimos dígitos.
        $this->assertSame([$noDdd->id], Contact::idsByPhone('5531931312929')->all());
    }

    public function test_evolution_lid_messages_use_the_real_number(): void
    {
        $channel = WhatsappChannel::create(['name' => 'W', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'x', 'api_key' => 'k', 'active' => true]);
        $parsed = app(WhatsappClient::class)->parse($channel, ['event' => 'messages.upsert', 'data' => [
            'key' => ['remoteJid' => '123456789012345@lid', 'remoteJidAlt' => '5511981234567@s.whatsapp.net', 'fromMe' => false, 'id' => 'ABC'],
            'message' => ['conversation' => 'oi'],
        ]]);
        $this->assertCount(1, $parsed);
        $this->assertSame('5511981234567', $parsed[0]->phone);

        $this->assertSame([], app(WhatsappClient::class)->parse($channel, ['event' => 'messages.upsert', 'data' => [
            'key' => ['remoteJid' => '123456789012345@lid', 'fromMe' => false, 'id' => 'DEF'],
            'message' => ['conversation' => 'oi'],
        ]]), 'sem o número real, a mensagem continua ignorada');
    }

    public function test_datajud_does_not_reimport_first_instance_when_case_reaches_second(): void
    {
        $number = '1000000-66.2026.8.26.0100';
        $case = LegalCase::create(['title' => 'Maria x Banco', 'number' => $number, 'status' => 'ativo', 'area' => 'civel', 'phase' => 'inicial', 'client_role' => 'autor']);
        (new CourtSetting(['datajud_enabled' => true, 'datajud_hours' => 24, 'datajud_notify' => false, 'deadline_days' => 15]))->save();
        $source = fn (string $grau, array $movements) => ['_source' => [
            'numeroProcesso' => Cnj::digits($number), 'tribunal' => 'TJSP', 'grau' => $grau, 'movimentos' => $movements,
        ]];
        $g1 = [['codigo' => 26, 'nome' => 'Distribuição', 'dataHora' => '2026-01-05T13:30:00.000Z'], ['codigo' => 193, 'nome' => 'Sentença', 'dataHora' => '2026-06-01T13:30:00.000Z']];
        $g2 = [['codigo' => 26, 'nome' => 'Distribuição', 'dataHora' => '2026-08-01T13:30:00.000Z']];
        Http::fake(['api-publica.datajud.cnj.jus.br/*' => Http::sequence()
            ->push(['hits' => ['hits' => [$source('G1', $g1)]]])
            ->push(['hits' => ['hits' => [$source('G1', $g1), $source('G2', $g2)]]])
            ->push(['hits' => ['hits' => [$source('G1', $g1), $source('G2', $g2)]]])]);

        $this->assertSame(2, Courts::syncCase($case));
        $this->assertSame(1, Courts::syncCase($case), 'só o movimento novo do 2º grau entra');
        $this->assertSame(0, Courts::syncCase($case));
        $this->assertSame(3, CaseMovement::where('legal_case_id', $case->id)->count());
        $this->assertTrue(CaseMovement::where('description', '[2º grau] Distribuição')->exists());
    }

    public function test_deactivated_user_loses_open_session(): void
    {
        $user = User::create(['name' => 'Estagiário', 'email' => 'est@escritorio.com.br', 'password' => 'secreta123', 'role' => 'estagiario', 'active' => true]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['active' => false])->save();
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_redirect_field_only_accepts_internal_urls(): void
    {
        $data = ['type' => 'receita', 'description' => 'Teste', 'amount' => '10,00', 'due_date' => today()->toDateString()];
        $base = rtrim(url('/'), '/');

        $this->actingAs($this->admin)->post(route('finance.store'), $data + ['redirect' => $base.'/contatos'])->assertRedirect($base.'/contatos');
        $this->actingAs($this->admin)->post(route('finance.store'), $data + ['redirect' => $base.'.evil.com/x'])->assertRedirect(route('finance.index', ['mes' => today()->format('Y-m')]));
        $this->actingAs($this->admin)->post(route('finance.store'), $data + ['redirect' => '/\\evil.com'])->assertRedirect(route('finance.index', ['mes' => today()->format('Y-m')]));
        $this->actingAs($this->admin)->post(route('finance.store'), $data + ['redirect' => '//evil.com'])->assertRedirect(route('finance.index', ['mes' => today()->format('Y-m')]));
    }

    public function test_invalid_date_in_url_does_not_break_forms(): void
    {
        $this->actingAs($this->admin)->get(route('tasks.create', ['data' => 'amanhã-talvez']))->assertOk();
        $this->actingAs($this->admin)->get(route('events.create', ['data' => 'xyz']))->assertOk();
        $this->actingAs($this->admin)->get(route('tasks.create', ['data' => '2026-12-01']))->assertOk()->assertSee('2026-12-01');
    }

    public function test_cnab_return_prefers_exact_our_number_over_partial_match(): void
    {
        $make = fn (string $bankNumber) => tap(BillingCharge::create(['provider' => 'bb', 'method' => 'boleto', 'description' => 'C'.$bankNumber, 'amount' => 100, 'due_date' => today(), 'status' => 'pendente']))
            ->forceFill(['bank_number' => $bankNumber])->save();
        $make('0000001234');
        $make('000000123'); // contido em "0000001234", mas não é o mesmo número
        $date = today()->format('dmY');
        $line = function (string $segment, array $parts): string {
            $l = str_repeat(' ', 240);
            $l = substr_replace($l, $segment, 13, 1);
            foreach ($parts as [$pos, $value]) {
                $l = substr_replace($l, $value, $pos, strlen($value));
            }

            return $l;
        };
        $file = implode("\n", [
            str_pad('00100000', 142).'2'.str_repeat(' ', 97),
            $line('T', [[0, '001'], [7, '3'], [15, '06'], [37, str_pad('0000001234', 20)], [58, str_pad('X', 15)], [81, '000000000010000']]),
            $line('U', [[0, '001'], [7, '3'], [15, '06'], [77, '000000000010000'], [137, $date], [145, $date]]),
        ]);

        $r = Billing::importReturn($file);
        $this->assertSame(1, $r['paid']);
        $this->assertSame('pago', BillingCharge::where('bank_number', '0000001234')->value('status'));
        $this->assertSame('pendente', BillingCharge::where('bank_number', '000000123')->value('status'));
    }

    public function test_csv_export_neutralizes_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", ReportController::safeCell('=HYPERLINK("x")'));
        $this->assertSame("'@SUM(A1)", ReportController::safeCell('@SUM(A1)'));
        $this->assertSame('-1.234,56', ReportController::safeCell('-1.234,56'));
        $this->assertSame('Maria', ReportController::safeCell('Maria'));
        $this->assertSame(10, ReportController::safeCell(10));
    }
}
