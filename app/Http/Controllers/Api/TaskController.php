<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Support\Integrations\ApiData;
use App\Support\Integrations\Deadlines;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Task::with(['users', 'legalCase', 'contact'])
            ->search($request->query('search'))
            ->when($request->query('status') === 'abertas', fn ($q) => $q->open())
            ->when(in_array($request->query('status'), array_keys(Options::TASK_STATUS), true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('deadlines_only'), fn ($q) => $q->where('is_deadline', true))
            ->when($request->query('user_id'), fn ($q, $id) => $q->forUser((int) $id))
            ->when($request->query('legal_case_id'), fn ($q, $id) => $q->where('legal_case_id', $id))
            ->when($this->date($request, 'due_from'), fn ($q, $d) => $q->where('due_at', '>=', $d->startOfDay()))
            ->when($this->date($request, 'due_to'), fn ($q, $d) => $q->where('due_at', '<=', $d->endOfDay()))
            ->orderByRaw('due_at is null')->orderBy('due_at');

        return $this->paginated($request, $query, fn (Task $t) => ApiData::task($t));
    }

    /** Prazos processuais em aberto que vencem até daqui a N dias (inclui atrasados). */
    public function deadlines(Request $request): JsonResponse
    {
        $days = min(365, max(0, (int) $request->query('days', Deadlines::days())));

        return $this->paginated($request, Deadlines::upcoming($days), fn (Task $t) => ApiData::task($t));
    }

    public function show(Task $task): JsonResponse
    {
        return $this->item(ApiData::task($task->load(['users', 'legalCase', 'contact'])));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $users = $data['users'] ?? array_filter([$this->authorId($request)]);
        unset($data['users']);
        $task = Task::create($data + ['created_by' => $this->authorId($request)]);
        $task->users()->sync($users);

        return $this->item(ApiData::task($task->fresh(['users', 'legalCase', 'contact'])), 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $data = $this->validated($request, true);
        $users = $data['users'] ?? null;
        unset($data['users']);
        $task->update($data);
        if ($users !== null) {
            $task->users()->sync($users);
        }

        return $this->item(ApiData::task($task->fresh(['users', 'legalCase', 'contact'])));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(array_keys(Options::TASK_STATUS))],
            'priority' => ['sometimes', Rule::in(array_keys(Options::TASK_PRIORITIES))],
            'group' => ['nullable', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'is_deadline' => ['sometimes', 'boolean'],
            'points' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'users' => ['sometimes', 'array'],
            'users.*' => ['integer', 'exists:users,id'],
        ]);
    }
}
