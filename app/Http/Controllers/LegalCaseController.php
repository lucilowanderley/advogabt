<?php

namespace App\Http\Controllers;

use App\Models\CaseMovement;
use App\Models\Contact;
use App\Models\LegalCase;
use App\Models\Partner;
use App\Models\User;
use App\Support\Format;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LegalCaseController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', 'ativo');

        $cases = LegalCase::with(['client', 'responsible'])
            ->withCount(['tasks as open_tasks_count' => fn ($q) => $q->open()])
            ->search($request->query('q'))
            ->when($status !== 'todos', fn ($q) => $q->where('status', $status))
            ->when($request->query('area'), fn ($q, $a) => $q->where('area', $a))
            ->when($request->query('responsavel'), fn ($q, $u) => $q->where('responsible_id', $u))
            ->latest()
            ->paginate(20)->withQueryString();

        $counts = LegalCase::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $users = User::where('active', true)->orderBy('name')->get();

        return view('cases.index', compact('cases', 'status', 'counts', 'users'));
    }

    public function create(Request $request): View
    {
        $case = new LegalCase([
            'status' => 'ativo',
            'phase' => 'inicial',
            'area' => 'civel',
            'client_role' => 'autor',
            'contact_id' => $request->query('contato'),
            'responsible_id' => $request->user()->id,
        ]);

        return view('cases.form', $this->formData($case));
    }

    public function store(Request $request): RedirectResponse
    {
        $case = LegalCase::create($this->validated($request));

        return redirect()->route('cases.show', $case)->with('success', 'Processo cadastrado.');
    }

    public function show(LegalCase $legalCase): View
    {
        $legalCase->load([
            'client', 'responsible', 'partner',
            'movements.user',
            'tasks' => fn ($q) => $q->with('users')->orderByRaw("status = 'concluida'")->orderByRaw('due_at is null')->orderBy('due_at'),
            'events' => fn ($q) => $q->orderByDesc('starts_at'),
            'financialEntries' => fn ($q) => $q->orderByDesc('due_date'),
        ]);

        return view('cases.show', ['case' => $legalCase]);
    }

    public function edit(LegalCase $legalCase): View
    {
        return view('cases.form', $this->formData($legalCase));
    }

    public function update(Request $request, LegalCase $legalCase): RedirectResponse
    {
        $legalCase->update($this->validated($request));

        return redirect()->route('cases.show', $legalCase)->with('success', 'Processo atualizado.');
    }

    public function destroy(LegalCase $legalCase): RedirectResponse
    {
        $legalCase->delete();

        return redirect()->route('cases.index')->with('success', 'Processo excluído.');
    }

    public function storeMovement(Request $request, LegalCase $legalCase): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:10000'],
        ]);
        $legalCase->movements()->create($data + ['user_id' => $request->user()->id]);
        $legalCase->touch();

        return redirect()->route('cases.show', $legalCase)->with('success', 'Andamento registrado.');
    }

    public function destroyMovement(LegalCase $legalCase, CaseMovement $movement): RedirectResponse
    {
        abort_unless($movement->legal_case_id === $legalCase->id, 404);
        $movement->delete();

        return redirect()->route('cases.show', $legalCase)->with('success', 'Andamento removido.');
    }

    private function validated(Request $request): array
    {
        $request->merge(['claim_value' => Format::parseMoney($request->input('claim_value'))]);

        return $request->validate([
            'number' => ['nullable', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:255'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'client_role' => ['required', Rule::in(array_keys(Options::CLIENT_ROLES))],
            'opposing_party' => ['nullable', 'string', 'max:255'],
            'area' => ['required', Rule::in(array_keys(Options::CASE_AREAS))],
            'action_type' => ['nullable', 'string', 'max:255'],
            'court' => ['nullable', 'string', 'max:255'],
            'court_division' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'phase' => ['required', Rule::in(array_keys(Options::CASE_PHASES))],
            'status' => ['required', Rule::in(array_keys(Options::CASE_STATUS))],
            'claim_value' => ['nullable', 'numeric', 'min:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'distributed_at' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'exists:users,id'],
            'partner_id' => ['nullable', 'exists:partners,id'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function formData(LegalCase $case): array
    {
        return [
            'case' => $case,
            'contacts' => Contact::orderBy('name')->get(['id', 'name']),
            'users' => User::where('active', true)->orderBy('name')->get(),
            'partners' => Partner::where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
