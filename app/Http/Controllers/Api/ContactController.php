<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use App\Support\Integrations\ApiData;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query()
            ->search($request->query('search'))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('crm_stage'), fn ($q, $s) => $q->where('crm_stage', $s))
            ->when($request->query('email'), fn ($q, $e) => $q->where('email', $e))
            ->when($request->query('document'), function ($q, $doc) {
                $digits = preg_replace('/\D/', '', $doc);
                $q->where(fn ($w) => $w->where('document', $doc)->orWhere('document', $digits)
                    ->orWhere('document', 'like', '%'.implode('%', str_split($digits)).'%'));
            })
            ->when($request->query('phone'), fn ($q, $phone) => $this->wherePhone($q, $phone))
            ->orderBy('name');

        return $this->paginated($request, $query, fn (Contact $c) => ApiData::contact($c));
    }

    public function show(Contact $contact): JsonResponse
    {
        $contact->load(['legalCases' => fn ($q) => $q->orderByDesc('updated_at')]);
        $data = ApiData::contact($contact);
        $data['cases'] = $contact->legalCases->map(fn ($c) => [
            'id' => $c->id, 'number' => $c->number, 'title' => $c->title, 'status' => $c->status, 'phase' => $c->phase, 'updated_at' => $c->updated_at?->toIso8601String(),
        ])->all();

        return $this->item($data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['user_id'] ??= $this->authorId($request);
        $contact = Contact::create($data)->fresh();

        return $this->item(ApiData::contact($contact), 201);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $contact->update($this->validated($request, true));

        return $this->item(ApiData::contact($contact->fresh()));
    }

    /**
     * Busca por telefone/WhatsApp ignorando formatação e o 9º dígito/DDI
     * (compara os 8 últimos dígitos), como chega de um bot de WhatsApp.
     */
    private function wherePhone($query, string $phone): void
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 8) {
            $query->whereRaw('1 = 0');

            return;
        }
        $tail = substr($digits, -8);
        $ids = Contact::query()
            ->where(fn ($q) => $q->where('whatsapp', 'like', '%'.substr($tail, -4))->orWhere('phone', 'like', '%'.substr($tail, -4)))
            ->get(['id', 'phone', 'whatsapp'])
            ->filter(fn ($c) => str_ends_with((string) ApiData::digits($c->whatsapp), $tail) || str_ends_with((string) ApiData::digits($c->phone), $tail))
            ->pluck('id');
        $query->whereKey($ids);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'person_type' => ['sometimes', Rule::in(['PF', 'PJ'])],
            'category' => ['sometimes', Rule::in(array_keys(Options::CONTACT_CATEGORIES))],
            'crm_stage' => ['sometimes', Rule::in(array_keys(Options::CRM_STAGES))],
            'document' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'profession' => ['nullable', 'string', 'max:255'],
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
