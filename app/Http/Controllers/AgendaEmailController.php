<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\Task;
use App\Support\Agenda\AgendaMail;
use App\Support\Agenda\Ics;
use App\Support\Agenda\OfficeMailer;
use App\Support\Agenda\OfficeMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Integrações › Agenda e e-mail: assinatura da agenda (.ics) e envio de e-mails automáticos. */
class AgendaEmailController extends Controller
{
    public function index(Request $request): View
    {
        $isAdmin = $request->user()->isAdmin();
        $host = (string) $request->getHost();

        return view('agenda-email.index', [
            'feeds' => CalendarFeed::where('user_id', $request->user()->id)->orderBy('id')->get(),
            'settings' => MailSetting::current() ?? MailSetting::defaults(),
            'logs' => $isAdmin ? MailLog::latest('id')->limit(15)->get() : collect(),
            'sentWeek' => MailLog::where('created_at', '>=', now()->subDays(7))->selectRaw('count(*) as total, sum(case when success then 1 else 0 end) as ok')->first(),
            'feedCount' => CalendarFeed::count(),
            'localHost' => ! str_contains($host, '.') || (bool) preg_match('/^(127\.|10\.|192\.168\.)|\.(test|local|localhost|lan|home|internal)$/', $host),
        ]);
    }

    // --- Assinatura da agenda (cada usuário) ---------------------------------

    public function storeFeed(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'scope' => ['required', Rule::in(array_keys(CalendarFeed::SCOPES))],
            'tasks' => ['required', Rule::in(array_keys(CalendarFeed::TASKS))],
        ]);
        abort_if(CalendarFeed::where('user_id', $request->user()->id)->count() >= 10, 422, 'Limite de 10 links por usuário.');

        $feed = CalendarFeed::create([
            'scope' => $data['scope'],
            'tasks' => $data['tasks'],
            'user_id' => $request->user()->id,
            'name' => ($data['name'] ?? null) ?: ($data['scope'] === 'equipe' ? 'Agenda da equipe' : 'Minha agenda').' · '.config('app.name'),
        ]);

        return redirect()->route('agenda-email.index')->with('new_feed', $feed->id)->with('success', 'Link criado. Copie e cole no Google Agenda ou no Outlook.');
    }

    public function regenerateFeed(Request $request, CalendarFeed $feed): RedirectResponse
    {
        abort_unless($feed->user_id === $request->user()->id, 403);
        $feed->forceFill(['token' => CalendarFeed::newToken(), 'last_accessed_at' => null])->save();

        return back()->with('new_feed', $feed->id)->with('success', 'Link trocado. O antigo parou de funcionar: assine de novo com o link novo.');
    }

    public function destroyFeed(Request $request, CalendarFeed $feed): RedirectResponse
    {
        abort_unless($feed->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $feed->delete();

        return back()->with('success', 'Link excluído. A agenda assinada deixa de ser atualizada.');
    }

    /** Link público (secreto) lido pelo Google Agenda, Outlook, iPhone... */
    public function feed(string $token): Response
    {
        $feed = CalendarFeed::with('user')->where('token', $token)->first();
        abort_if(! $feed || ! $feed->user?->active, 404);

        if (! $feed->last_accessed_at || $feed->last_accessed_at->lt(now()->subMinutes(10))) {
            $feed->forceFill(['last_accessed_at' => now()])->saveQuietly();
        }

        $from = now()->subDays(90)->startOfDay();
        $to = now()->addDays(400)->endOfDay();
        $mine = $feed->scope !== 'equipe';

        $events = Event::with(['legalCase', 'contact', 'user'])
            ->whereBetween('starts_at', [$from, $to])
            ->when($mine, fn ($q) => $q->where('user_id', $feed->user_id))
            ->orderBy('starts_at')->limit(3000)->get();

        $tasks = $feed->tasks === 'nenhuma' ? collect() : Task::with(['legalCase', 'contact', 'users'])->open()
            ->whereNotNull('due_at')->whereBetween('due_at', [$from, $to])
            ->when($feed->tasks === 'prazos', fn ($q) => $q->where('is_deadline', true))
            ->when($mine, fn ($q) => $q->forUser($feed->user_id))
            ->orderBy('due_at')->limit(3000)->get();

        return response(Ics::calendar($feed->name, $events, $tasks), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Um compromisso em .ics (abre no Outlook/iPhone ou importa no Google Agenda). */
    public function eventIcs(Event $event): Response
    {
        $event->loadMissing(['legalCase', 'contact', 'user']);

        return response(Ics::single($event), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="compromisso-'.$event->id.'.ics"',
        ]);
    }

    // --- E-mail do escritório (administradores) ------------------------------

    public function saveMail(Request $request): RedirectResponse
    {
        $s = MailSetting::current() ?? new MailSetting;
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(MailSetting::PROVIDERS))],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(array_keys(MailSetting::ENCRYPTIONS))],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'remind_hours' => ['required', 'integer', 'between:1,168'],
            'digest_hour' => ['required', 'integer', 'between:0,23'],
        ], [], [
            'host' => 'servidor SMTP', 'port' => 'porta', 'username' => 'usuário', 'password' => 'senha',
            'from_address' => 'e-mail do remetente', 'from_name' => 'nome do remetente', 'reply_to' => 'responder para',
            'remind_hours' => 'antecedência do lembrete', 'digest_hour' => 'horário do resumo',
        ]);

        $password = $data['password'] ?? null;
        unset($data['password']);
        // Gmail e Outlook: o usuário é o próprio e-mail.
        $data['username'] = ($data['username'] ?? null) ?: ($data['provider'] !== 'smtp' ? $data['from_address'] : null);
        foreach (['active', 'send_invites', 'invite_clients', 'remind_events', 'remind_clients', 'daily_digest'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        $s->fill($data);
        if (filled($password)) {
            // A senha de app do Google é mostrada em blocos com espaços ("abcd efgh ijkl mnop").
            $s->password = $s->provider === 'gmail' ? preg_replace('/\s+/', '', $password) : $password;
        } elseif ($request->boolean('clear_password')) {
            $s->password = null;
        }
        $s->save();
        AgendaMail::forget();

        return redirect()->route('agenda-email.index')->with('success', 'Configuração de e-mail salva.'.($s->last_test_ok ? '' : ' Use "Enviar e-mail de teste" para conferir.'));
    }

    public function testMail(Request $request): RedirectResponse
    {
        $s = MailSetting::current();
        if (! $s?->isConfigured()) {
            return back()->with('error', 'Salve a configuração de e-mail antes de testar.');
        }
        $data = $request->validate(['to' => ['nullable', 'email', 'max:255']]);
        $to = $data['to'] ?? $request->user()->email;

        $error = OfficeMailer::send($s, 'teste', [$to => $request->user()->name], new OfficeMessage(
            subjectLine: 'Teste de e-mail · '.config('app.name'),
            heading: 'Tudo certo!',
            lines: ['Este é um e-mail de teste enviado pela conta '.$s->from_address.' ('.$s->provider_label.').',
                'A partir de agora o sistema consegue enviar convites, lembretes e o resumo diário de prazos.'],
            buttonUrl: route('agenda-email.index'),
            buttonLabel: 'Voltar às configurações',
        ));
        $s->forceFill(['last_test_at' => now(), 'last_test_ok' => $error === null, 'last_error' => $error])->save();

        return $error
            ? back()->with('error', 'Não foi possível enviar: '.$error)
            : back()->with('success', "E-mail de teste enviado para {$to}. Confira a caixa de entrada (e o spam).");
    }

    public function sendDigest(): RedirectResponse
    {
        $s = MailSetting::enabled();
        if (! $s) {
            return back()->with('error', 'Ative e configure o envio de e-mails primeiro.');
        }
        @set_time_limit(120);
        $count = AgendaMail::digest($s, true);

        return back()->with('success', $count ? "Resumo enviado para {$count} pessoa(s)." : 'Ninguém tem compromissos hoje nem prazos próximos: nenhum resumo enviado.');
    }
}
