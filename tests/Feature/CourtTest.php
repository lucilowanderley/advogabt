<?php

namespace Tests\Feature;

use App\Models\CaseMovement;
use App\Models\Contact;
use App\Models\CourtLawyer;
use App\Models\CourtPublication;
use App\Models\CourtSetting;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Courts\BusinessDays;
use App\Support\Courts\Cnj;
use App\Support\Courts\Courts;
use App\Support\Integrations\Routine;
use App\Support\Integrations\Webhooks;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CourtTest extends TestCase
{
    use RefreshDatabase;

    private const TJSP = '1000000-66.2026.8.26.0100';

    private const TRF1 = '0000832-35.2018.4.01.3202';

    private User $admin;

    private User $lawyer;

    private LegalCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00'));
        $this->admin = User::where('role', 'admin')->first();
        $this->lawyer = User::create(['name' => 'Ana Lima', 'email' => 'ana@escritorio.com.br', 'password' => 'secreta123', 'role' => 'advogado', 'active' => true, 'oab' => 'SP 345.678']);
        $client = Contact::create(['name' => 'Maria Souza', 'category' => 'cliente']);
        $this->case = LegalCase::create(['title' => 'Maria x Banco', 'number' => self::TJSP, 'contact_id' => $client->id, 'responsible_id' => $this->lawyer->id, 'status' => 'ativo', 'area' => 'civel', 'phase' => 'inicial', 'client_role' => 'autor']);
    }

    private function settings(array $attrs = []): CourtSetting
    {
        $s = new CourtSetting(array_merge(['datajud_enabled' => true, 'datajud_hours' => 24, 'datajud_notify' => true, 'djen_enabled' => true, 'auto_deadline' => true, 'deadline_days' => 15], $attrs));
        $s->save();

        return $s;
    }

    private function datajudSource(array $movements): array
    {
        return ['hits' => ['hits' => [['_source' => [
            'numeroProcesso' => Cnj::digits(self::TJSP),
            'tribunal' => 'TJSP',
            'grau' => 'G1',
            'dataAjuizamento' => '20260105103000',
            'dataHoraUltimaAtualizacao' => '2026-11-08T03:00:00.000Z',
            'classe' => ['codigo' => 7, 'nome' => 'Procedimento Comum Cível'],
            'orgaoJulgador' => ['codigo' => 1, 'nome' => '1ª Vara Cível do Foro Central'],
            'movimentos' => $movements,
        ]]]]];
    }

    private function djenItem(int $id, string $number, string $text = 'Fica a parte intimada para se manifestar no prazo de 5 (cinco) dias.'): array
    {
        return [
            'id' => $id,
            'data_disponibilizacao' => '2026-11-06',
            'siglaTribunal' => 'TJSP',
            'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Despacho',
            'nomeOrgao' => '1ª Vara Cível',
            'nomeClasse' => 'PROCEDIMENTO COMUM CÍVEL',
            'texto' => '<p>'.$text.'</p>',
            'numero_processo' => Cnj::digits($number),
            'link' => 'https://comunicaapi.pje.jus.br/api/v1/comunicacao/abc/certidao',
            'destinatarios' => [['nome' => 'MARIA SOUZA', 'polo' => 'A']],
            'destinatarioadvogados' => [['advogado' => ['nome' => 'ANA LIMA', 'numero_oab' => '123456', 'uf_oab' => 'SP']]],
        ];
    }

    public function test_cnj_number_validation_and_datajud_alias(): void
    {
        $this->assertTrue(Cnj::isValid(self::TJSP));
        $this->assertTrue(Cnj::isValid(self::TRF1));
        $this->assertFalse(Cnj::isValid('1000000-67.2026.8.26.0100'));
        $this->assertSame('tjsp', Cnj::alias(self::TJSP));
        $this->assertSame('trf1', Cnj::alias(self::TRF1));
        $this->assertSame('tjdft', Cnj::alias('0000000-00.2026.8.07.0001'));
        $this->assertSame('trt2', Cnj::alias('0000000-00.2026.5.02.0001'));
        $this->assertSame('tre-sp', Cnj::alias('0000000-00.2026.6.26.0001'));
        $this->assertNull(Cnj::alias('0000000-00.2026.1.00.0000')); // STF não está no Datajud
        $this->assertSame(self::TJSP, Cnj::mask(Cnj::digits(self::TJSP)));
        $this->assertSame(['123456', 'SP'], CourtLawyer::parse('OAB/SP 123.456'));
        $this->assertSame(['98765', 'RJ'], CourtLawyer::parse('98.765/RJ'));
    }

    public function test_deadline_counts_business_days_from_publication(): void
    {
        $days = new BusinessDays;
        // Disponibilizada na sexta 06/11/2026: publicada na segunda 09/11, prazo começa 10/11; 20/11 é feriado nacional.
        $this->assertSame('2026-11-09', $days->publicationDate(CarbonImmutable::parse('2026-11-06'))->toDateString());
        $this->assertSame('2026-12-01', $days->deadline(CarbonImmutable::parse('2026-11-06'), 15)->toDateString());
        $this->assertSame('2026-11-16', $days->deadline(CarbonImmutable::parse('2026-11-06'), 5)->toDateString());
        // Recesso forense: disponibilizada em 18/12 só começa a contar depois de 20/01.
        $this->assertSame('2027-01-22', $days->deadline(CarbonImmutable::parse('2026-12-18'), 1)->toDateString());
        // Feriado local informado pelo escritório.
        $local = new BusinessDays("10/11\n11/11/2026");
        $this->assertSame('2026-11-18', $local->deadline(CarbonImmutable::parse('2026-11-06'), 5)->toDateString());
        // Sexta-feira Santa de 2027 é 26/03.
        $this->assertFalse($days->isBusinessDay(CarbonImmutable::parse('2027-03-26')));
    }

    public function test_datajud_imports_movements_quietly_first_then_notifies(): void
    {
        $this->settings();
        $hook = Webhook::create(['name' => 'n8n', 'url' => 'https://n8n.exemplo.com/hook', 'events' => ['andamento.criado'], 'active' => true, 'secret' => 'segredo']);
        Webhooks::flush();
        $movements = [
            ['codigo' => 26, 'nome' => 'Distribuição', 'dataHora' => '2026-01-05T13:30:00.000Z'],
            ['codigo' => 85, 'nome' => 'Petição', 'dataHora' => '20260210120000', 'complementosTabelados' => [['nome' => 'Contestação', 'descricao' => 'tipo_de_peticao']]],
        ];
        Http::fake([
            'api-publica.datajud.cnj.jus.br/*' => Http::sequence()
                ->push($this->datajudSource($movements))
                ->push($this->datajudSource(array_merge($movements, [['codigo' => 11009, 'nome' => 'Despacho', 'dataHora' => '2026-11-05T15:00:00.000Z']]))),
            'n8n.exemplo.com/*' => Http::response('ok'),
        ]);

        $this->assertSame(2, Courts::syncCase($this->case));
        $this->case->refresh();
        $this->assertSame('1ª Vara Cível do Foro Central', $this->case->court_division);
        $this->assertSame('Procedimento Comum Cível', $this->case->action_type);
        $this->assertSame('2026-01-05', $this->case->distributed_at->toDateString());
        $this->assertNotNull($this->case->datajud_checked_at);
        $this->assertNull($this->case->datajud_error);
        $this->assertTrue(CaseMovement::where('description', 'Petição (Contestação)')->where('source', 'datajud')->exists());
        $this->assertSame(0, WebhookDelivery::where('webhook_id', $hook->id)->count(), 'a primeira importação não avisa');

        $this->assertSame(1, Courts::syncCase($this->case));
        $this->assertSame(3, $this->case->movements()->count());
        $this->assertSame(1, WebhookDelivery::where('webhook_id', $hook->id)->where('event', 'andamento.criado')->count());

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api_publica_tjsp/_search')
            && $r->hasHeader('Authorization', 'APIKey '.CourtSetting::PUBLIC_KEY)
            && $r['query']['match']['numeroProcesso'] === Cnj::digits(self::TJSP));
    }

    public function test_datajud_errors_are_saved_on_the_case(): void
    {
        $this->settings();
        Http::fake(['api-publica.datajud.cnj.jus.br/*' => Http::sequence()->push(['error' => 'unauthorized'], 401)->push(['hits' => ['hits' => []]])]);

        $this->actingAs($this->lawyer)->post(route('cases.court.refresh', $this->case))->assertSessionHas('error');
        $this->assertStringContainsString('chave', $this->case->fresh()->datajud_error);

        $this->case->forceFill(['datajud_error' => null])->saveQuietly();
        $this->assertSame(0, Courts::syncCase($this->case));
        $this->assertStringContainsString('não encontrado', $this->case->fresh()->datajud_error);
    }

    public function test_djen_publications_are_captured_linked_and_create_deadlines(): void
    {
        $this->settings();
        $lawyer = CourtLawyer::create(['name' => 'Ana Lima', 'oab_number' => '123456', 'oab_uf' => 'SP', 'user_id' => $this->lawyer->id, 'active' => true]);
        $hook = Webhook::create(['name' => 'n8n', 'url' => 'https://n8n.exemplo.com/hook', 'events' => ['publicacao.recebida'], 'active' => true, 'secret' => 'segredo']);
        Webhooks::flush();
        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response(['status' => 'success', 'count' => 2, 'items' => [
                $this->djenItem(501, self::TJSP),
                $this->djenItem(502, self::TRF1, 'Intimação da sentença.'),
            ]]),
            'api-publica.datajud.cnj.jus.br/*' => Http::response(['hits' => ['hits' => []]]),
            'n8n.exemplo.com/*' => Http::response('ok'),
        ]);

        $result = Routine::run();
        $this->assertSame(2, $result['publications']);

        $linked = CourtPublication::where('external_id', '501')->sole();
        $this->assertSame($this->case->id, $linked->legal_case_id);
        $this->assertSame($lawyer->id, $linked->court_lawyer_id);
        $this->assertSame(5, $linked->deadline_days);
        $this->assertSame('2026-11-09', $linked->published_at->toDateString());
        $task = $linked->task;
        $this->assertNotNull($task);
        $this->assertTrue($task->is_deadline);
        $this->assertSame('2026-11-16', $task->due_at->toDateString()); // 5 dias úteis
        $this->assertSame([$this->lawyer->id], $task->users()->pluck('users.id')->all());
        $this->assertTrue($this->case->movements()->where('source', 'djen')->exists());

        $orphan = CourtPublication::where('external_id', '502')->sole();
        $this->assertNull($orphan->legal_case_id);
        $this->assertNull($orphan->task_id);
        $this->assertSame(2, WebhookDelivery::where('webhook_id', $hook->id)->where('event', 'publicacao.recebida')->count());

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'comunicaapi.pje.jus.br')
            && $r['numeroOab'] === '123456' && $r['ufOab'] === 'SP' && $r['dataDisponibilizacaoInicio'] === '2026-11-02');

        // Nova busca não duplica; o processo cadastrado depois é vinculado sozinho.
        LegalCase::create(['title' => 'Previdenciário João', 'number' => self::TRF1, 'status' => 'ativo', 'area' => 'previdenciario', 'phase' => 'inicial', 'client_role' => 'autor']);
        Courts::run(force: true);
        $this->assertSame(2, CourtPublication::count());
        $this->assertNotNull($orphan->fresh()->legal_case_id);
        $this->assertNotNull($orphan->fresh()->task_id);
        $this->assertSame('2026-11-09', $lawyer->fresh()->synced_until->toDateString());
    }

    public function test_publication_screens_and_actions(): void
    {
        $s = $this->settings(['auto_deadline' => false]);
        $p = Courts::storePublication($this->djenItem(900, self::TRF1, 'Intime-se.'), null, $s);
        $this->assertNull($p->task_id);

        $this->actingAs($this->lawyer)->get(route('publications.index'))->assertOk()->assertSee('Intimação · Despacho')->assertSee('processo não cadastrado');
        $this->actingAs($this->lawyer)->get(route('publications.show', $p))->assertOk()->assertSee('Intime-se.')->assertSee('01/12/2026', false);
        $this->actingAs($this->lawyer)->getJson(route('publications.suggest', [$p, 'dias' => 5]))->assertJson(['due' => '2026-11-16']);

        $this->actingAs($this->lawyer)->post(route('publications.link', $p), ['legal_case_id' => $this->case->id])->assertSessionHas('success');
        $this->assertSame($this->case->id, $p->fresh()->legal_case_id);

        $this->actingAs($this->lawyer)->post(route('publications.deadline', $p), ['days' => 15, 'due_date' => '2026-11-30', 'users' => [$this->admin->id]])
            ->assertRedirect(route('publications.show', $p));
        $p->refresh();
        $this->assertSame('tratada', $p->status);
        $this->assertSame('2026-11-30', $p->task->due_at->toDateString());
        $this->assertStringContainsString('informado manualmente', $p->task->description);
        $this->assertSame([$this->admin->id], $p->task->users()->pluck('users.id')->all());

        $this->actingAs($this->lawyer)->post(route('publications.status', $p), ['status' => 'nova'])->assertSessionHas('success');
        $this->assertSame('nova', $p->fresh()->status);
        $this->actingAs($this->lawyer)->post(route('publications.status', $p), ['status' => 'descartada', 'voltar' => 'lista'])->assertRedirect(route('publications.index'));

        $this->actingAs($this->lawyer)->get(route('cases.show', $this->case))->assertOk()->assertSee('Tribunal')->assertSee('DJEN');
        $this->actingAs($this->lawyer)->get(route('dashboard'))->assertOk()->assertSee('Publicações');
    }

    public function test_admin_configures_courts(): void
    {
        $this->actingAs($this->lawyer)->get(route('courts.settings'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('courts.settings'))->assertOk()->assertSee('A integração ainda está desligada');
        $this->assertFalse(Courts::hasWork());

        $this->actingAs($this->admin)->post(route('courts.save'), [
            'datajud_enabled' => '1', 'djen_enabled' => '1', 'auto_deadline' => '1',
            'datajud_hours' => 12, 'deadline_days' => 15, 'holidays' => "25/01\n", 'datajud_api_key' => 'APIKey nova-chave',
        ])->assertSessionHas('success');
        $s = CourtSetting::sole();
        $this->assertSame('nova-chave', $s->apiKey());
        $this->assertSame(12, $s->datajud_hours);
        $this->assertTrue(Courts::hasWork());

        $this->actingAs($this->admin)->post(route('courts.lawyers.import'))->assertSessionHas('success');
        $this->assertTrue(CourtLawyer::where('oab_number', '345678')->where('oab_uf', 'SP')->where('user_id', $this->lawyer->id)->exists());
        $this->actingAs($this->admin)->post(route('courts.lawyers.store'), ['name' => 'Ana', 'oab_number' => '345.678', 'oab_uf' => 'SP'])->assertSessionHasErrors('oab_number');
        $this->actingAs($this->admin)->post(route('courts.lawyers.store'), ['name' => 'Bruno', 'oab_number' => '0234.567', 'oab_uf' => 'rj'])->assertSessionHas('success');
        $this->assertTrue(CourtLawyer::where('oab_number', '234567')->where('oab_uf', 'RJ')->exists());

        Http::fake(['api-publica.datajud.cnj.jus.br/*' => Http::response($this->datajudSource([['codigo' => 26, 'nome' => 'Distribuição', 'dataHora' => '2026-01-05T13:30:00.000Z']]))]);
        $this->actingAs($this->admin)->post(route('courts.datajud.test'), ['number' => self::TJSP])->assertSessionHas('success');
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'APIKey nova-chave'));
        $this->actingAs($this->admin)->post(route('courts.datajud.test'), ['number' => '123'])->assertSessionHas('error');

        $this->actingAs($this->admin)->get(route('integrations.index'))->assertOk()->assertSee(route('publications.index'), false);
        $this->actingAs($this->admin)->get(route('courts.settings'))->assertOk()->assertSee('OAB/RJ 234567');
    }
}
