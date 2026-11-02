<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Support\Integrations\ApiData;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $from = $this->date($request, 'from') ?? today();
        $to = $this->date($request, 'to') ?? $from->copy()->addDays(30);

        $query = Event::with(['legalCase', 'contact', 'user'])
            ->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('contact_id'), fn ($q, $id) => $q->where('contact_id', $id))
            ->orderBy('starts_at');

        return $this->paginated($request, $query, fn (Event $e) => ApiData::event($e));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(array_keys(Options::EVENT_TYPES))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['user_id'] ??= $this->authorId($request);
        $event = Event::create($data)->fresh(['legalCase', 'contact', 'user']);

        return $this->item(ApiData::event($event), 201);
    }
}
