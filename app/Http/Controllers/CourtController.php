<?php

namespace App\Http\Controllers;

use App\Models\CourtLawyer;
use App\Models\CourtPublication;
use App\Models\CourtSetting;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\Courts\Cnj;
use App\Support\Courts\CourtException;
use App\Support\Courts\Courts;
use App\Support\Courts\DatajudClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Tribunais: configuração (administradores), caixa de publicações (equipe) e consulta do processo no Datajud. */
class CourtController extends Controller
{
    // --- Configuração ----------------------------------------------------------

    public function settings(): View
    {
        $settings = CourtSetting::current();

        return view('courts.settings', [
            'settings' => $settings,
            'lawyers' => CourtLawyer::with('user')->withCount(['publications as fresh_count' => fn ($q) => $q->where('status', 'nova')])->orderBy('name')->get(),
            'users' => User::where('active', true)->orderBy('name')->get(),
            'monitored' => Courts::monitoredCases()->count(),
            'withErrors' => Courts::monitoredCases()->whereNotNull('datajud_error')->count(),
            'invalidNumbers' => Courts::monitoredCases()->get(['id', 'number'])->reject(fn ($c) => Cnj::isValid($c->number))->count(),
            'checkedToday' => LegalCase::where('datajud_checked_at', '>=', today())->count(),
            'fresh' => CourtPublication::unread()->count(),
            'publicKey' => CourtSetting::PUBLIC_KEY,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'datajud_api_key' => ['nullable', 'string', 'max:300'],
            'datajud_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'deadline_days' => ['required', 'integer', 'min:1', 'max:365'],
            'holidays' => ['nullable', 'string', 'max:5000'],
        ], [], ['datajud_api_key' => 'chave da API', 'datajud_hours' => 'intervalo', 'deadline_days' => 'prazo padrão', 'holidays' => 'feriados']);

        $s = CourtSetting::current();
        $s->fill([
            'datajud_enabled' => $request->boolean('datajud_enabled'),
            'datajud_notify' => $request->boolean('datajud_notify'),
            'djen_enabled' => $request->boolean('djen_enabled'),
            'auto_deadline' => $request->boolean('auto_deadline'),
            'datajud_hours' => $data['datajud_hours'],
            'deadline_days' => $data['deadline_days'],
            'holidays' => $data['holidays'] ?? null,
        ]);
        if ($request->boolean('reset_key')) {
            $s->datajud_api_key = null;
        } elseif (filled($data['datajud_api_key'] ?? null)) {
            $s->datajud_api_key = preg_replace('/^APIKey\s+/i', '', trim($data['datajud_api_key']));
        }
        $s->save();

        return back()->with('success', 'Configuração dos tribunais salva. A rotina das integrações consulta o Datajud e o DJEN sozinha; use "Buscar agora" para não esperar.');
    }

    public function storeLawyer(Request $request): RedirectResponse
    {
        $request->merge(['oab_number' => ltrim(preg_replace('/\D/', '', (string) $request->input('oab_number')), '0'), 'oab_uf' => strtoupper((string) $request->input('oab_uf'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'oab_number' => ['required', 'string', 'max:8', Rule::unique('court_lawyers')->where('oab_uf', $request->input('oab_uf'))],
            'oab_uf' => ['required', Rule::in(CourtLawyer::UFS)],
            'user_id' => ['nullable', 'exists:users,id'],
        ], ['oab_number.unique' => 'Esta OAB já está cadastrada.'], ['name' => 'nome', 'oab_number' => 'número da OAB', 'oab_uf' => 'UF']);

        CourtLawyer::create($data + ['active' => true]);

        return back()->with('success', "OAB/{$data['oab_uf']} {$data['oab_number']} cadastrada. Na primeira busca o sistema traz as publicações dos últimos ".Courts::DJEN_BACKFILL_DAYS.' dias.');
    }

    /** Cadastra as OABs informadas no perfil dos usuários (campo OAB). */
    public function importLawyers(): RedirectResponse
    {
        $added = 0;
        foreach (User::where('active', true)->whereNotNull('oab')->get() as $user) {
            if (! ($parsed = CourtLawyer::parse($user->oab))) {
                continue;
            }
            [$number, $uf] = $parsed;
            $lawyer = CourtLawyer::firstOrNew(['oab_number' => $number, 'oab_uf' => $uf]);
            if (! $lawyer->exists) {
                $lawyer->fill(['name' => $user->name, 'user_id' => $user->id, 'active' => true])->save();
                $added++;
            }
        }

        return back()->with($added ? 'success' : 'error', $added
            ? "{$added} OAB(s) importada(s) do cadastro de usuários."
            : 'Nenhuma OAB nova: preencha o campo OAB (ex.: "SP 123.456") no cadastro dos usuários ou cadastre abaixo.');
    }

    public function toggleLawyer(CourtLawyer $lawyer): RedirectResponse
    {
        $lawyer->update(['active' => ! $lawyer->active]);

        return back()->with('success', $lawyer->label.($lawyer->active ? ' voltou a ser acompanhada.' : ' não será mais consultada.'));
    }

    public function destroyLawyer(CourtLawyer $lawyer): RedirectResponse
    {
        $lawyer->delete();

        return back()->with('success', $lawyer->label.' removida. As publicações já capturadas continuam na caixa.');
    }

    public function runNow(): RedirectResponse
    {
        @set_time_limit(300);
        if (! CourtSetting::current()->exists) {
            return back()->with('error', 'Salve a configuração primeiro.');
        }
        $r = Courts::run(force: true);
        $errors = CourtLawyer::where('active', true)->whereNotNull('last_error')->pluck('last_error')->unique();

        $message = "Busca concluída: {$r['publications']} publicação(ões) nova(s) no DJEN e {$r['movements']} andamento(s) novo(s) pelo Datajud.";
        if ($errors->isNotEmpty()) {
            return back()->with('error', $message.' Falha no DJEN: '.$errors->first());
        }

        return back()->with('success', $message);
    }

    /** Testa a chave do Datajud com um número de processo. */
    public function testDatajud(Request $request): RedirectResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:40']], [], ['number' => 'número do processo']);
        if (! Cnj::isValid($data['number'])) {
            return back()->withInput()->with('error', 'Número fora do padrão CNJ (NNNNNNN-DD.AAAA.J.TR.OOOO) ou com dígito verificador errado.');
        }
        try {
            $sources = app(DatajudClient::class)->search($data['number'], CourtSetting::current()->apiKey());
        } catch (CourtException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        if (! $sources) {
            return back()->withInput()->with('success', 'O Datajud respondeu, mas não encontrou o processo '.Cnj::mask($data['number']).' na base do '.Cnj::court($data['number']).' (pode ser recente ou sigiloso). A chave está funcionando.');
        }
        $first = $sources[0];
        $movements = collect($sources)->sum(fn ($s) => count((array) ($s['movimentos'] ?? [])));

        return back()->withInput()->with('success', 'Conexão com o Datajud funcionando: '.Cnj::mask($data['number']).' — '.($first['classe']['nome'] ?? 'processo').' em '.($first['orgaoJulgador']['nome'] ?? Cnj::court($data['number'])).", {$movements} movimento(s).");
    }

    // --- Publicações -----------------------------------------------------------

    public function publications(Request $request): View
    {
        $status = $request->query('situacao', 'nova');
        $query = CourtPublication::with(['legalCase', 'lawyer', 'task'])
            ->when(array_key_exists($status, CourtPublication::STATUS), fn ($q) => $q->where('status', $status))
            ->when($request->query('oab'), fn ($q, $id) => $q->where('court_lawyer_id', $id))
            ->when($request->query('vinculo') === 'sem', fn ($q) => $q->whereNull('legal_case_id'))
            ->when($request->query('q'), function ($q, $term) {
                $digits = Cnj::digits($term);
                $q->where(fn ($w) => $w->where('text', 'like', "%{$term}%")->orWhere('organ', 'like', "%{$term}%")
                    ->when(strlen($digits) >= 7, fn ($x) => $x->orWhere('process_number', 'like', "%{$digits}%")));
            })
            ->orderByDesc('available_at')->orderByDesc('id');

        return view('courts.publications', [
            'publications' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'counts' => CourtPublication::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'lawyers' => CourtLawyer::orderBy('name')->get(),
            'settings' => CourtSetting::current(),
        ]);
    }

    public function show(CourtPublication $publication): View
    {
        $publication->load(['legalCase.responsible', 'lawyer', 'task.users', 'handler']);
        $settings = CourtSetting::current();
        $days = $publication->deadline_days ?: (int) $settings->deadline_days;

        return view('courts.show', [
            'p' => $publication,
            'settings' => $settings,
            'days' => $days,
            'suggested' => Courts::suggestedDue($publication, $days, $settings),
            'users' => User::where('active', true)->orderBy('name')->get(),
            'cases' => $publication->legal_case_id ? collect() : LegalCase::orderBy('title')->get(['id', 'number', 'title']),
            'plain' => Courts::plain($publication->text),
        ]);
    }

    /** Recalcula o vencimento sugerido para outra quantidade de dias (usado pelo formulário). */
    public function suggest(Request $request, CourtPublication $publication)
    {
        $days = max(1, min(365, (int) $request->query('dias', 15)));

        return response()->json(['due' => Courts::suggestedDue($publication, $days)->toDateString()]);
    }

    public function createDeadline(Request $request, CourtPublication $publication): RedirectResponse
    {
        if ($publication->task_id && $publication->task) {
            return redirect()->route('tasks.show', $publication->task)->with('success', 'Esta publicação já tem prazo.');
        }
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:250'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'due_date' => ['nullable', 'date'],
            'users' => ['nullable', 'array'],
            'users.*' => ['exists:users,id'],
        ], [], ['days' => 'dias úteis', 'due_date' => 'vencimento']);

        $suggested = Courts::suggestedDue($publication, (int) $data['days'])->toDateString();
        $due = ! empty($data['due_date']) && $data['due_date'] !== $suggested ? $data['due_date'] : null;
        $task = Courts::createDeadline($publication, (int) $data['days'], $due, $data['users'] ?? null, $request->user(), $data['title'] ?? null);
        $this->markHandled($publication, $request->user());

        return redirect()->route('publications.show', $publication)->with('success', 'Prazo criado para '.$task->due_at->format('d/m/Y').' e publicação marcada como tratada.');
    }

    public function link(Request $request, CourtPublication $publication): RedirectResponse
    {
        $data = $request->validate(['legal_case_id' => ['required', 'exists:legal_cases,id']], [], ['legal_case_id' => 'processo']);
        Courts::link($publication, LegalCase::findOrFail($data['legal_case_id']));

        return back()->with('success', 'Publicação vinculada ao processo e registrada nos andamentos.');
    }

    public function setStatus(Request $request, CourtPublication $publication): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(array_keys(CourtPublication::STATUS))]])['status'];
        if ($status === 'nova') {
            $publication->forceFill(['status' => 'nova', 'handled_by' => null, 'handled_at' => null])->save();
        } else {
            $this->markHandled($publication, $request->user(), $status);
        }

        return $request->input('voltar') === 'lista'
            ? redirect()->route('publications.index')->with('success', 'Publicação marcada como '.mb_strtolower(CourtPublication::STATUS[$status]['label']).'.')
            : back()->with('success', 'Publicação marcada como '.mb_strtolower(CourtPublication::STATUS[$status]['label']).'.');
    }

    private function markHandled(CourtPublication $p, User $user, string $status = 'tratada'): void
    {
        $p->forceFill(['status' => $status, 'handled_by' => $user->id, 'handled_at' => now()])->save();
    }

    // --- Processo ----------------------------------------------------------------

    public function refreshCase(LegalCase $legalCase): RedirectResponse
    {
        @set_time_limit(120);
        try {
            $count = Courts::syncCase($legalCase);
        } catch (CourtException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($legalCase->fresh()->datajud_error) {
            return back()->with('error', $legalCase->fresh()->datajud_error);
        }

        return back()->with('success', $count ? "{$count} andamento(s) novo(s) importado(s) do Datajud." : 'Consulta feita: nenhum andamento novo no Datajud.');
    }

    public function toggleCase(LegalCase $legalCase): RedirectResponse
    {
        $legalCase->forceFill(['datajud_sync' => ! $legalCase->datajud_sync])->saveQuietly();

        return back()->with('success', $legalCase->datajud_sync ? 'O processo voltará a ser consultado no Datajud.' : 'O processo não será mais consultado no Datajud.');
    }
}
