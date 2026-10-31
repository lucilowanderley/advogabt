<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\User;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $view = $request->query('view', 'lista');

        $query = Contact::query()
            ->withCount('legalCases')
            ->search($request->query('q'))
            ->when($request->query('categoria'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('responsavel'), fn ($q, $u) => $q->where('user_id', $u));

        if ($view === 'funil') {
            $stages = collect(Options::CRM_STAGES)->map(fn ($label, $key) => [
                'label' => $label,
                'contacts' => (clone $query)->where('crm_stage', $key)->orderBy('updated_at', 'desc')->get(),
            ]);

            return view('contacts.funnel', compact('stages', 'view'));
        }

        $contacts = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('contacts.index', compact('contacts', 'view'));
    }

    public function create(Request $request): View
    {
        $contact = new Contact([
            'person_type' => 'PF',
            'category' => $request->query('categoria', 'cliente'),
            'crm_stage' => $request->query('etapa', 'cliente'),
            'nationality' => 'brasileiro(a)',
            'user_id' => $request->user()->id,
        ]);

        return view('contacts.form', ['contact' => $contact, 'users' => User::where('active', true)->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contact = Contact::create($this->validated($request));

        return redirect()->route('contacts.show', $contact)->with('success', 'Contato cadastrado.');
    }

    public function show(Contact $contact): View
    {
        $contact->load([
            'legalCases' => fn ($q) => $q->latest(),
            'tasks' => fn ($q) => $q->with('users')->orderByRaw("status = 'concluida'")->latest()->limit(15),
            'financialEntries' => fn ($q) => $q->orderByDesc('due_date')->limit(15),
            'events' => fn ($q) => $q->where('starts_at', '>=', today())->orderBy('starts_at')->limit(10),
            'owner',
        ]);

        return view('contacts.show', compact('contact'));
    }

    public function edit(Contact $contact): View
    {
        return view('contacts.form', ['contact' => $contact, 'users' => User::where('active', true)->orderBy('name')->get()]);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $contact->update($this->validated($request, $contact));

        return redirect()->route('contacts.show', $contact)->with('success', 'Contato atualizado.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $contact->delete();

        return redirect()->route('contacts.index')->with('success', 'Contato excluído.');
    }

    public function moveStage(Request $request, Contact $contact): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['crm_stage' => ['required', Rule::in(array_keys(Options::CRM_STAGES))]]);
        $contact->crm_stage = $data['crm_stage'];
        if ($contact->crm_stage === 'cliente') {
            $contact->category = 'cliente';
        }
        $contact->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', 'Etapa atualizada.');
    }

    private function validated(Request $request, ?Contact $contact = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'person_type' => ['required', Rule::in(['PF', 'PJ'])],
            'category' => ['required', Rule::in(array_keys(Options::CONTACT_CATEGORIES))],
            'crm_stage' => ['required', Rule::in(array_keys(Options::CRM_STAGES))],
            'document' => ['nullable', 'string', 'max:20'],
            'rg' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'profession' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'zip_code' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', Rule::in(Options::UFS)],
            'origin' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'user_id' => ['nullable', 'exists:users,id'],
        ]);
    }
}
