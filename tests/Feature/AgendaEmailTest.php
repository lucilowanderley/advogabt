<?php

namespace Tests\Feature;

use App\Models\CalendarFeed;
use App\Models\Contact;
use App\Models\Event;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\Task;
use App\Models\User;
use App\Support\Agenda\AgendaMail;
use App\Support\Agenda\CalendarLinks;
use App\Support\Agenda\Ics;
use App\Support\Agenda\OfficeMessage;
use App\Support\Integrations\Routine;
use App\Support\Integrations\Webhooks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AgendaEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('role', 'admin')->first();
        $this->lawyer = User::create(['name' => 'Ana Lima', 'email' => 'ana@escritorio.com.br', 'password' => 'secreta123', 'role' => 'advogado', 'active' => true]);
        $this->withoutDefer();
        AgendaMail::forget();
    }

    private function enableMail(array $extra = []): MailSetting
    {
        $s = MailSetting::defaults();
        $s->fill(['from_address' => 'escritorio@gmail.com', 'username' => 'escritorio@gmail.com'] + $extra);
        $s->password = 'abcdefghijklmnop';
        $s->save();
        AgendaMail::forget();

        return $s;
    }

    private function event(array $attrs = []): Event
    {
        return Event::create($attrs + [
            'title' => 'Audiência de conciliação', 'type' => 'audiencia', 'starts_at' => now()->addDays(5)->setTime(14, 0),
            'ends_at' => now()->addDays(5)->setTime(15, 0), 'all_day' => false, 'location' => 'Fórum, sala 3', 'user_id' => $this->lawyer->id,
        ]);
    }

    public function test_page_renders_and_catalog_card_is_available(): void
    {
        $this->actingAs($this->admin)->get('/integracoes/agenda-email')->assertOk()->assertSee('E-mail do escritório')->assertSee('Minha agenda no Google Agenda');
        $this->get('/integracoes')->assertSee('Agenda e e-mail')->assertSee(route('agenda-email.index'));

        // A equipe vê a parte da agenda, mas não a configuração do e-mail.
        $this->actingAs($this->lawyer)->get('/integracoes/agenda-email')->assertOk()->assertDontSee('E-mail do escritório');
        $this->post('/integracoes/agenda-email/email', [])->assertForbidden();
        $this->post('/integracoes/agenda-email/email/testar')->assertForbidden();
    }

    public function test_feed_shows_own_events_and_deadlines_with_secret_token(): void
    {
        $mine = $this->event();
        $other = $this->event(['title' => 'Reunião de outra pessoa', 'type' => 'reuniao', 'user_id' => $this->admin->id]);
        $deadline = Task::create(['title' => 'Contestação', 'status' => 'a_fazer', 'priority' => 'alta', 'due_at' => now()->addDays(3)->startOfDay(), 'is_deadline' => true]);
        $deadline->users()->attach($this->lawyer->id);
        $plain = Task::create(['title' => 'Ligar para cliente', 'status' => 'a_fazer', 'priority' => 'media', 'due_at' => now()->addDays(2)->setTime(10, 0)]);
        $plain->users()->attach($this->lawyer->id);

        $this->actingAs($this->lawyer)->post('/integracoes/agenda-email/links', ['scope' => 'meus', 'tasks' => 'prazos', 'name' => ''])->assertRedirect();
        $feed = CalendarFeed::where('user_id', $this->lawyer->id)->firstOrFail();
        $this->assertSame(48, strlen($feed->token));

        auth()->logout();
        $ics = $this->get("/calendario/{$feed->token}.ics")->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Audiência: Audiência de conciliação', $ics);
        $this->assertStringContainsString('UID:'.Ics::uid($mine), $ics);
        $this->assertStringContainsString('DTSTART:'.$mine->starts_at->copy()->utc()->format('Ymd\THis\Z'), $ics);
        $this->assertStringContainsString('LOCATION:Fórum\, sala 3', $ics);
        $this->assertStringContainsString('SUMMARY:Prazo: Contestação', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:'.$deadline->due_at->format('Ymd'), $ics);
        $this->assertStringNotContainsString('Reunião de outra pessoa', $ics);
        $this->assertStringNotContainsString('Ligar para cliente', $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        $this->assertNotNull($feed->fresh()->last_accessed_at);

        // Equipe + todas as tarefas.
        $team = CalendarFeed::create(['user_id' => $this->lawyer->id, 'name' => 'Equipe', 'scope' => 'equipe', 'tasks' => 'todas']);
        $ics = $this->get("/calendario/{$team->token}.ics")->assertOk()->getContent();
        $this->assertStringContainsString('Reunião de outra pessoa', $ics);
        $this->assertStringContainsString('Tarefa: Ligar para cliente', $ics);

        // Link errado ou usuário desativado: 404. Link trocado: o antigo para de funcionar.
        $this->get('/calendario/'.str_repeat('a', 48).'.ics')->assertNotFound();
        $old = $feed->token;
        $this->actingAs($this->lawyer)->post("/integracoes/agenda-email/links/{$feed->id}/trocar")->assertRedirect();
        $this->get("/calendario/{$old}.ics")->assertNotFound();
        $this->lawyer->update(['active' => false]);
        $this->get('/calendario/'.$feed->fresh()->token.'.ics')->assertNotFound();
    }

    public function test_users_manage_only_their_own_links(): void
    {
        $feed = CalendarFeed::create(['user_id' => $this->admin->id, 'name' => 'Admin', 'scope' => 'equipe', 'tasks' => 'prazos']);
        $this->actingAs($this->lawyer)->post("/integracoes/agenda-email/links/{$feed->id}/trocar")->assertForbidden();
        $this->delete("/integracoes/agenda-email/links/{$feed->id}")->assertForbidden();
        $this->actingAs($this->admin)->delete("/integracoes/agenda-email/links/{$feed->id}")->assertRedirect();
        $this->assertModelMissing($feed);
    }

    public function test_single_event_downloads_and_add_to_calendar_links(): void
    {
        $event = $this->event(['notes' => "Levar documentos; testemunhas"]);
        $res = $this->actingAs($this->lawyer)->get("/agenda/{$event->id}/ics")->assertOk();
        $content = str_replace("\r\n ", '', $res->getContent()); // desdobra as linhas longas
        $this->assertStringContainsString('METHOD:PUBLISH', $content);
        $this->assertStringContainsString('Levar documentos\; testemunhas', $content);
        $this->get(route('events.edit', $event))->assertOk()->assertSee('Google Agenda')->assertSee(route('events.ics', $event));

        $google = CalendarLinks::google($event);
        $this->assertStringContainsString('action=TEMPLATE', $google);
        $this->assertStringContainsString('dates='.Ics::utc($event->starts_at).'%2F'.Ics::utc($event->ends_at), $google);
        $this->assertStringContainsString('outlook.office.com/calendar/0/deeplink/compose', CalendarLinks::outlook($event));
    }

    public function test_admin_saves_mail_settings_and_sends_test(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post('/integracoes/agenda-email/email', [
            'provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'from_address' => 'escritorio@gmail.com', 'password' => 'abcd efgh ijkl mnop', 'from_name' => 'Escritório Teste',
            'active' => 1, 'send_invites' => 1, 'remind_events' => 1, 'remind_hours' => 24, 'daily_digest' => 1, 'digest_hour' => 7,
        ])->assertRedirect(route('agenda-email.index'))->assertSessionHasNoErrors();

        $s = MailSetting::current();
        $this->assertSame('abcdefghijklmnop', $s->password);
        $this->assertSame('escritorio@gmail.com', $s->username);
        $this->assertNotSame('abcdefghijklmnop', $s->getRawOriginal('password'));
        $this->assertFalse($s->invite_clients);

        // Em branco mantém a senha.
        $this->post('/integracoes/agenda-email/email', ['provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'from_address' => 'escritorio@gmail.com', 'remind_hours' => 24, 'digest_hour' => 7, 'active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('abcdefghijklmnop', MailSetting::current()->password);

        $this->post('/integracoes/agenda-email/email/testar', ['to' => 'eu@exemplo.com'])->assertSessionHas('success');
        Mail::assertSent(OfficeMessage::class, fn (OfficeMessage $m) => $m->hasTo('eu@exemplo.com') && ($m->from[0]['address'] ?? null) === 'escritorio@gmail.com' && str_contains($m->subjectLine, 'Teste'));
        $this->assertTrue(MailSetting::current()->last_test_ok);
        $this->assertSame(1, MailLog::where('kind', 'teste')->where('success', true)->count());

        $this->post('/integracoes/agenda-email/email', ['provider' => 'smtp', 'host' => 'smtp bad', 'port' => 99999, 'encryption' => 'x', 'from_address' => 'nope'])
            ->assertSessionHasErrors(['host', 'port', 'encryption', 'from_address']);
    }

    public function test_invites_follow_event_changes_and_cancellation(): void
    {
        Mail::fake();
        $this->enableMail(['invite_clients' => true]);
        $client = Contact::create(['name' => 'Maria Souza', 'email' => 'maria@cliente.com', 'category' => 'cliente']);

        $this->actingAs($this->admin)->post('/agenda', [
            'title' => 'Audiência de instrução', 'type' => 'audiencia', 'date' => now()->addDays(10)->format('Y-m-d'), 'start_time' => '09:30', 'end_time' => '10:30',
            'location' => 'Fórum', 'user_id' => $this->lawyer->id, 'contact_id' => $client->id, 'notes' => 'Segredo interno',
        ])->assertRedirect();
        $event = Event::latest('id')->first();
        $this->assertSame(1, $event->sequence);
        $this->assertSame('ana@escritorio.com.br,maria@cliente.com', $event->invited_emails);

        Mail::assertSent(OfficeMessage::class, function (OfficeMessage $m) {
            return $m->hasTo('ana@escritorio.com.br') && str_starts_with($m->subjectLine, 'Convite:')
                && str_contains($m->ics, 'METHOD:REQUEST') && str_contains($m->ics, 'ATTENDEE;CN="Ana Lima"')
                && str_contains($m->ics, 'Segredo interno');
        });
        // O cliente recebe o convite sem as observações internas e sem link do sistema.
        Mail::assertSent(OfficeMessage::class, fn (OfficeMessage $m) => $m->hasTo('maria@cliente.com') && ! str_contains($m->ics, 'Segredo interno') && ! str_contains($m->ics, 'URL:') && $m->buttonUrl === null);

        // Só observação mudou: sem novo convite.
        Mail::fake();
        $event->update(['notes' => 'Outra nota']);
        Mail::assertNothingSent();

        // Mudou o horário e o responsável: atualização para o novo, cancelamento para o antigo.
        $event->update(['starts_at' => $event->starts_at->copy()->addHour(), 'user_id' => $this->admin->id]);
        $event->refresh();
        $this->assertSame(2, $event->sequence);
        Mail::assertSent(OfficeMessage::class, fn (OfficeMessage $m) => $m->hasTo('ana@escritorio.com.br') && $m->icsMethod === 'CANCEL' && str_contains($m->ics, 'STATUS:CANCELLED'));
        Mail::assertSent(OfficeMessage::class, fn (OfficeMessage $m) => $m->hasTo($this->admin->email) && str_starts_with($m->subjectLine, 'Atualizado:') && str_contains($m->ics, 'SEQUENCE:2'));

        // Excluído: cancelamento para quem tinha o convite.
        Mail::fake();
        $this->delete("/agenda/{$event->id}")->assertRedirect();
        Mail::assertSent(OfficeMessage::class, fn (OfficeMessage $m) => $m->hasTo('maria@cliente.com') && $m->icsMethod === 'CANCEL' && str_contains($m->ics, 'SEQUENCE:3'));
        Mail::assertSent(OfficeMessage::class, 2);
    }

    public function test_no_mail_when_disabled(): void
    {
        Mail::fake();
        $this->enableMail(['active' => false]);
        $this->event();
        $this->assertSame(0, Routine::run()['reminders']);
        Mail::assertNothingSent();
    }

    public function test_reminders_and_daily_digest_run_once(): void
    {
        Mail::fake();
        $this->enableMail(['send_invites' => false, 'remind_hours' => 24, 'digest_hour' => 0]);
        $soon = $this->event(['title' => 'Reunião com cliente', 'type' => 'reuniao', 'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4)]);
        $later = $this->event(['starts_at' => now()->addDays(3)]);
        $deadline = Task::create(['title' => 'Recurso', 'status' => 'a_fazer', 'priority' => 'alta', 'due_at' => today()->subDay(), 'is_deadline' => true]);
        $deadline->users()->attach($this->lawyer->id);

        $result = Routine::run();
        $this->assertGreaterThanOrEqual(1, $result['reminders']); // + compromissos de demonstração próximos
        $this->assertGreaterThanOrEqual(1, $result['digests']);
        Mail::assertSent(OfficeMessage::class, fn (OfficeMessage $m) => $m->hasTo('ana@escritorio.com.br') && str_starts_with($m->subjectLine, 'Lembrete:') && str_contains($m->subjectLine, 'Reunião com cliente'));
        Mail::assertSent(OfficeMessage::class, fn (OfficeMessage $m) => $m->hasTo('ana@escritorio.com.br') && str_starts_with($m->subjectLine, 'Sua agenda de hoje')
            && collect($m->items)->contains(fn ($i) => $i['title'] === 'Prazo: Recurso' && $i['late']));
        $this->assertNotNull($soon->fresh()->reminded_at);
        $this->assertNull($later->fresh()->reminded_at);

        Mail::fake();
        $again = Routine::run();
        $this->assertSame(0, $again['reminders']);
        $this->assertSame(0, $again['digests']);
        Mail::assertNothingSent();

        // Remarcado: novo lembrete.
        $soon->update(['starts_at' => now()->addHours(5)]);
        $this->assertNull($soon->fresh()->reminded_at);
        $this->assertSame(1, Routine::run()['reminders']);
    }

    public function test_smtp_errors_are_explained(): void
    {
        $this->assertStringContainsString('senha de app', \App\Support\Agenda\OfficeMailer::explain('Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted."'));
        $this->assertStringContainsString('Não foi possível conectar', \App\Support\Agenda\OfficeMailer::explain('Connection could not be established with host "smtp.x.com:587": stream_socket_client(): php_network_getaddresses'));
    }
}
