<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function index(Request $request): View
    {
        $term = $request->query('q');
        $partners = Partner::withCount('legalCases')
            ->withSum(['financialEntries as paid_total' => fn ($q) => $q->where('type', 'despesa')->whereNotNull('paid_at')], 'amount')
            ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('oab', 'like', "%{$term}%")->orWhere('city', 'like', "%{$term}%")))
            ->when($request->query('tipo'), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('active')->orderBy('name')
            ->paginate(20)->withQueryString();

        return view('partners.index', compact('partners'));
    }

    public function create(): View
    {
        return view('partners.form', ['partner' => new Partner(['type' => 'correspondente', 'active' => true, 'commission_percent' => 0])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $partner = Partner::create($this->validated($request));

        return redirect()->route('partners.show', $partner)->with('success', 'Parceiro cadastrado.');
    }

    public function show(Partner $partner): View
    {
        $partner->load([
            'legalCases' => fn ($q) => $q->with('client')->latest(),
            'financialEntries' => fn ($q) => $q->orderByDesc('due_date'),
        ]);

        return view('partners.show', compact('partner'));
    }

    public function edit(Partner $partner): View
    {
        return view('partners.form', compact('partner'));
    }

    public function update(Request $request, Partner $partner): RedirectResponse
    {
        $partner->update($this->validated($request));

        return redirect()->route('partners.show', $partner)->with('success', 'Parceiro atualizado.');
    }

    public function destroy(Partner $partner): RedirectResponse
    {
        $partner->delete();

        return redirect()->route('partners.index')->with('success', 'Parceiro excluído.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Options::PARTNER_TYPES))],
            'oab' => ['nullable', 'string', 'max:30'],
            'document' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', Rule::in(Options::UFS)],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pix_key' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['active'] = $request->boolean('active');
        $data['commission_percent'] = $data['commission_percent'] ?? 0;

        return $data;
    }
}
