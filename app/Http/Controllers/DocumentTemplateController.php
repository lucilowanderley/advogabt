<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\DocumentTemplate;
use App\Models\LegalCase;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DocumentTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $templates = DocumentTemplate::with('user')
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->when($request->query('categoria'), fn ($q, $c) => $q->where('category', $c))
            ->orderBy('name')->get();

        return view('templates.index', compact('templates'));
    }

    public function create(): View
    {
        return view('templates.form', ['template' => new DocumentTemplate(['category' => 'outro'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = DocumentTemplate::create($this->validated($request) + ['user_id' => $request->user()->id]);

        return redirect()->route('templates.show', $template)->with('success', 'Modelo criado.');
    }

    public function show(DocumentTemplate $template): View
    {
        return view('templates.show', [
            'template' => $template,
            'contacts' => Contact::orderBy('name')->get(['id', 'name']),
            'cases' => LegalCase::orderBy('title')->get(['id', 'title', 'number']),
        ]);
    }

    public function edit(DocumentTemplate $template): View
    {
        return view('templates.form', compact('template'));
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request));

        return redirect()->route('templates.show', $template)->with('success', 'Modelo atualizado.');
    }

    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Modelo excluído.');
    }

    /** Gera o documento preenchido, pronto para imprimir / salvar em PDF. */
    public function generate(Request $request, DocumentTemplate $template): View
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
        ]);

        $contact = isset($data['contact_id']) ? Contact::find($data['contact_id']) : null;
        $case = isset($data['legal_case_id']) ? LegalCase::with('client')->find($data['legal_case_id']) : null;

        return view('templates.print', [
            'template' => $template,
            'content' => $template->render($contact, $case, $request->user()),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(Options::TEMPLATE_CATEGORIES))],
            'content' => ['required', 'string'],
        ]);
    }
}
