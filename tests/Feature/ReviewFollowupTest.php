<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CourtSetting;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Support\Courts\Cnj;
use App\Support\Courts\Courts;
use App\Support\Integrations\Routine;
use App\Support\Integrations\Webhooks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Decisões aplicadas depois da revisão geral. */
class ReviewFollowupTest extends TestCase
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

    public function test_datajud_sends_one_whatsapp_message_for_many_new_movements(): void
    {
        $number = '1000000-66.2026.8.26.0100';
        $client = Contact::create(['name' => 'Joana Prado', 'category' => 'cliente', 'whatsapp' => '(21) 97531-8642']);
        $case = LegalCase::create(['title' => 'Ação', 'number' => $number, 'contact_id' => $client->id, 'status' => 'ativo', 'area' => 'civel', 'phase' => 'inicial', 'client_role' => 'autor']);
        (new CourtSetting(['datajud_enabled' => true, 'datajud_hours' => 24, 'datajud_notify' => true, 'deadline_days' => 15]))->save();
        WhatsappChannel::create(['name' => 'W', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'x', 'api_key' => 'k', 'active' => true, 'notify_movements' => true]);
        $mv = fn (int $code, string $name, string $date) => ['codigo' => $code, 'nome' => $name, 'dataHora' => $date];
        $source = fn (array $movements) => ['hits' => ['hits' => [['_source' => ['numeroProcesso' => Cnj::digits($number), 'tribunal' => 'TJSP', 'grau' => 'G1', 'movimentos' => $movements]]]]];
        $first = [$mv(26, 'Distribuição', '2026-01-05T13:30:00.000Z')];
        Http::fake([
            'api-publica.datajud.cnj.jus.br/*' => Http::sequence()->push($source($first))->push($source(array_merge($first, [
                $mv(51, 'Conclusão', '2026-10-01T10:00:00.000Z'), $mv(11009, 'Despacho', '2026-10-02T10:00:00.000Z'), $mv(581, 'Juntada', '2026-10-03T10:00:00.000Z'),
            ]))),
            'evo.example.com/*' => Http::response(['key' => ['id' => 'OUT']]),
        ]);

        $this->assertSame(1, Courts::syncCase($case)); // primeira importação: silenciosa
        $this->assertSame(3, Courts::syncCase($case));

        $sent = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), 'message/sendText'))->values();
        $this->assertCount(1, $sent, 'um aviso só para os 3 andamentos');
        $this->assertStringContainsString('3 novidades', $sent[0]['text']);
        $this->assertStringContainsString('Despacho', $sent[0]['text']);
    }

    public function test_manual_movement_still_notifies_client(): void
    {
        $client = Contact::create(['name' => 'Joana Prado', 'category' => 'cliente', 'whatsapp' => '(21) 97531-8642']);
        $case = LegalCase::create(['title' => 'Ação', 'contact_id' => $client->id, 'status' => 'ativo', 'area' => 'civel', 'phase' => 'inicial', 'client_role' => 'autor']);
        WhatsappChannel::create(['name' => 'W', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'x', 'api_key' => 'k', 'active' => true, 'notify_movements' => true]);
        Http::fake(['evo.example.com/*' => Http::response(['key' => ['id' => 'OUT']])]);

        $case->movements()->create(['date' => today(), 'description' => 'Audiência designada']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'message/sendText') && str_contains($r['text'], 'Audiência designada'));
    }

    public function test_admin_is_warned_about_default_passwords(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('senha padrão')->assertSee($this->admin->email);

        $this->actingAs($this->admin)->put(route('profile.update'), [
            'name' => $this->admin->name, 'email' => $this->admin->email,
            'current_password' => 'advogabt', 'password' => 'NovaSenha#2026', 'password_confirmation' => 'NovaSenha#2026',
        ])->assertRedirect();
        User::where('role', '!=', 'admin')->update(['active' => false]);
        $this->actingAs($this->admin->fresh())->get(route('dashboard'))->assertOk()->assertDontSee('senha padrão');

        $lawyer = User::create(['name' => 'Ana', 'email' => 'ana2@escritorio.com.br', 'password' => 'advogabt', 'role' => 'advogado', 'active' => true]);
        $this->actingAs($lawyer)->get(route('dashboard'))->assertOk()->assertDontSee('senha padrão');
    }

    public function test_background_routine_finds_the_cli_php(): void
    {
        $bin = Routine::phpBinary();
        $this->assertNotNull($bin);
        $this->assertMatchesRegularExpression('/^php(\d[\d.]*)?(\.exe)?$/', strtolower(basename($bin)));
    }
}
