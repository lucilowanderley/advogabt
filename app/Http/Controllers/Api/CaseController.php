<?php

namespace App\Http\Controllers\Api;

use App\Models\LegalCase;
use App\Support\Integrations\ApiData;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CaseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = LegalCase::with(['client', 'responsible'])
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('area'), fn ($q, $a) => $q->where('area', $a))
            ->when($request->query('contact_id'), fn ($q, $id) => $q->where('contact_id', $id))
            ->when($request->query('number'), fn ($q, $n) => $q->where('number', 'like', '%'.preg_replace('/\D/', '%', $n).'%'))
            ->orderByDesc('updated_at');

        return $this->paginated($request, $query, fn (LegalCase $c) => ApiData::legalCase($c));
    }

    public function show(LegalCase $legalCase): JsonResponse
    {
        return $this->item(ApiData::legalCase($legalCase->load(['client', 'responsible', 'movements.user']), true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['responsible_id'] ??= $this->authorId($request);
        $case = LegalCase::create($data)->fresh(['client', 'responsible']);

        return $this->item(ApiData::legalCase($case), 201);
    }

    public function update(Request $request, LegalCase $legalCase): JsonResponse
    {
        $legalCase->update($this->validated($request, true));

        return $this->item(ApiData::legalCase($legalCase->fresh(['client', 'responsible'])));
    }

    public function storeMovement(Request $request, LegalCase $legalCase): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'description' => ['required', 'string', 'max:10000'],
        ]);
        $movement = $legalCase->movements()->create([
            'date' => $data['date'] ?? today()->toDateString(),
            'description' => $data['description'],
            'user_id' => $this->authorId($request),
        ]);
        $legalCase->touch();

        return $this->item(ApiData::movement($movement->load(['legalCase.client', 'user'])), 201);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'number' => ['nullable', 'string', 'max:30'],
            'title' => [$req, 'string', 'max:255'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'client_role' => ['sometimes', Rule::in(array_keys(Options::CLIENT_ROLES))],
            'opposing_party' => ['nullable', 'string', 'max:255'],
            'area' => ['sometimes', Rule::in(array_keys(Options::CASE_AREAS))],
            'action_type' => ['nullable', 'string', 'max:255'],
            'court' => ['nullable', 'string', 'max:255'],
            'court_division' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'phase' => ['sometimes', Rule::in(array_keys(Options::CASE_PHASES))],
            'status' => ['sometimes', Rule::in(array_keys(Options::CASE_STATUS))],
            'claim_value' => ['nullable', 'numeric', 'min:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'distributed_at' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
