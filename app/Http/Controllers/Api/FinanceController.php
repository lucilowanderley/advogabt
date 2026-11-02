<?php

namespace App\Http\Controllers\Api;

use App\Models\FinancialEntry;
use App\Support\Integrations\ApiData;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinancialEntry::with(['contact', 'legalCase'])
            ->when(in_array($request->query('type'), ['receita', 'despesa'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->query('situation') === 'pago', fn ($q) => $q->paid())
            ->when($request->query('situation') === 'aberto', fn ($q) => $q->unpaid()->where('due_date', '>=', today()))
            ->when($request->query('situation') === 'atrasado', fn ($q) => $q->unpaid()->where('due_date', '<', today()))
            ->when($this->date($request, 'due_from'), fn ($q, $d) => $q->where('due_date', '>=', $d->toDateString()))
            ->when($this->date($request, 'due_to'), fn ($q, $d) => $q->where('due_date', '<=', $d->toDateString()))
            ->when($request->query('contact_id'), fn ($q, $id) => $q->where('contact_id', $id))
            ->when($request->query('legal_case_id'), fn ($q, $id) => $q->where('legal_case_id', $id))
            ->orderBy('due_date');

        return $this->paginated($request, $query, fn (FinancialEntry $f) => ApiData::financialEntry($f));
    }

    public function show(FinancialEntry $entry): JsonResponse
    {
        return $this->item(ApiData::financialEntry($entry->load(['contact', 'legalCase'])));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Options::FINANCE_TYPES))],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['required', 'date'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::in(array_keys(Options::PAYMENT_METHODS))],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
            'notes' => ['nullable', 'string'],
        ]);
        $entry = FinancialEntry::create($data + ['user_id' => $this->authorId($request)])->fresh(['contact', 'legalCase']);

        return $this->item(ApiData::financialEntry($entry), 201);
    }

    /** Baixa (pagamento). Ex.: chamado por n8n quando o banco confirma um Pix. */
    public function pay(Request $request, FinancialEntry $entry): JsonResponse
    {
        $data = $request->validate([
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::in(array_keys(Options::PAYMENT_METHODS))],
        ]);
        $entry->update([
            'paid_at' => $data['paid_at'] ?? today()->toDateString(),
            'payment_method' => $data['payment_method'] ?? $entry->payment_method,
        ]);

        return $this->item(ApiData::financialEntry($entry->fresh(['contact', 'legalCase'])));
    }
}
