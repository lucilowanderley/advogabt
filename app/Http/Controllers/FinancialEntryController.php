<?php

namespace App\Http\Controllers;

use App\Models\BillingProvider;
use App\Models\Contact;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Partner;
use App\Support\Format;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinancialEntryController extends Controller
{
    public function index(Request $request): View
    {
        try {
            $month = Carbon::createFromFormat('Y-m', (string) $request->query('mes', now()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            $month = now()->startOfMonth();
        }
        $start = $month->copy();
        $end = $month->copy()->endOfMonth();
        $type = $request->query('tipo');
        $situation = $request->query('situacao');

        $monthQuery = FinancialEntry::whereBetween('due_date', [$start, $end]);

        $summary = [
            'receitas' => (float) (clone $monthQuery)->where('type', 'receita')->sum('amount'),
            'recebido' => (float) (clone $monthQuery)->where('type', 'receita')->paid()->sum('amount'),
            'despesas' => (float) (clone $monthQuery)->where('type', 'despesa')->sum('amount'),
            'pago' => (float) (clone $monthQuery)->where('type', 'despesa')->paid()->sum('amount'),
            'atrasado' => (float) FinancialEntry::where('type', 'receita')->unpaid()->where('due_date', '<', today())->sum('amount'),
        ];
        $summary['saldo'] = $summary['recebido'] - $summary['pago'];
        $summary['previsto'] = $summary['receitas'] - $summary['despesas'];

        $entries = (clone $monthQuery)
            ->with(['contact', 'legalCase', 'partner', 'currentCharge'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($request->query('categoria'), fn ($q, $c) => $q->where('category', $c))
            ->when($situation === 'pago', fn ($q) => $q->paid())
            ->when($situation === 'aberto', fn ($q) => $q->unpaid()->where('due_date', '>=', today()))
            ->when($situation === 'atrasado', fn ($q) => $q->unpaid()->where('due_date', '<', today()))
            ->when($request->query('q'), fn ($q, $t) => $q->where('description', 'like', "%{$t}%"))
            ->orderBy('due_date')->orderBy('id')
            ->paginate(30)->withQueryString();

        $byCategory = (clone $monthQuery)->where('type', 'receita')
            ->selectRaw('category, sum(amount) as total')->groupBy('category')->orderByDesc('total')->get();

        $canCharge = BillingProvider::usable()->isNotEmpty();

        return view('finance.index', compact('entries', 'summary', 'month', 'byCategory', 'canCharge'));
    }

    public function create(Request $request): View
    {
        $entry = new FinancialEntry([
            'type' => $request->query('tipo', 'receita'),
            'due_date' => today(),
            'contact_id' => $request->query('contato'),
            'legal_case_id' => $request->query('processo'),
            'partner_id' => $request->query('parceiro'),
        ]);

        return view('finance.form', $this->formData($entry));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $installments = max(1, min(60, (int) $request->input('installments', 1)));
        $due = Carbon::parse($data['due_date']);

        for ($i = 0; $i < $installments; $i++) {
            FinancialEntry::create(array_merge($data, [
                'description' => $installments > 1 ? $data['description'].' ('.($i + 1).'/'.$installments.')' : $data['description'],
                'due_date' => $due->copy()->addMonthsNoOverflow($i),
                'paid_at' => $i === 0 ? ($data['paid_at'] ?? null) : null,
                'user_id' => $request->user()->id,
            ]));
        }

        return redirect()->to($this->backTo($request, route('finance.index', ['mes' => $due->format('Y-m')])))
            ->with('success', $installments > 1 ? "{$installments} parcelas lançadas." : 'Lançamento registrado.');
    }

    public function edit(FinancialEntry $entry): View
    {
        return view('finance.form', $this->formData($entry));
    }

    public function update(Request $request, FinancialEntry $entry): RedirectResponse
    {
        $entry->update($this->validated($request));

        return redirect()->to($this->backTo($request, route('finance.index', ['mes' => $entry->due_date->format('Y-m')])))
            ->with('success', 'Lançamento atualizado.');
    }

    public function destroy(FinancialEntry $entry): RedirectResponse
    {
        $month = $entry->due_date->format('Y-m');
        $entry->delete();

        return redirect()->route('finance.index', ['mes' => $month])->with('success', 'Lançamento excluído.');
    }

    /** Dá baixa (marca como pago/recebido) ou estorna. */
    public function togglePaid(FinancialEntry $entry): RedirectResponse
    {
        $entry->paid_at = $entry->paid_at ? null : today();
        $entry->save();

        $msg = $entry->paid_at
            ? ($entry->type === 'receita' ? 'Recebimento confirmado.' : 'Pagamento confirmado.')
            : 'Baixa estornada.';

        return back()->with('success', $msg);
    }

    private function validated(Request $request): array
    {
        $request->merge(['amount' => Format::parseMoney($request->input('amount'))]);

        return $request->validate([
            'type' => ['required', Rule::in(array_keys(Options::FINANCE_TYPES))],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(array_keys(Options::FINANCE_CATEGORIES))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['required', 'date'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::in(array_keys(Options::PAYMENT_METHODS))],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
            'partner_id' => ['nullable', 'exists:partners,id'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function formData(FinancialEntry $entry): array
    {
        return [
            'entry' => $entry,
            'contacts' => Contact::orderBy('name')->get(['id', 'name']),
            'cases' => LegalCase::orderBy('title')->get(['id', 'title', 'number']),
            'partners' => Partner::orderBy('name')->get(['id', 'name']),
        ];
    }
}
