<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use App\Support\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TaskController extends Controller
{
    public const COLUMNS = [
        'todas' => 'Todas as tarefas',
        'hoje' => 'Hoje',
        'proximos' => 'Próximos dias',
        'fazendo' => 'Fazendo',
        'concluidas' => 'Concluídas hoje',
    ];

    public function index(Request $request): View
    {
        $view = $request->query('view', 'quadro');
        $scope = $request->query('scope', 'minhas');
        $userId = $scope === 'minhas' ? $request->user()->id : ($scope === 'todas' ? null : (int) $scope);

        $base = Task::with(['users', 'legalCase', 'contact'])
            ->withCount('comments')
            ->forUser($userId)
            ->search($request->query('q'))
            ->when($request->query('grupo'), fn (Builder $q, $g) => $q->where('group', $g));

        $groups = Task::whereNotNull('group')->distinct()->orderBy('group')->pluck('group');
        $users = User::where('active', true)->orderBy('name')->get();

        $summary = [
            'done' => (clone $base)->where('status', 'concluida')->where('completed_at', '>=', now()->startOfMonth())->count(),
            'pending' => (clone $base)->open()->count(),
            'points' => (int) (clone $base)->where('status', 'concluida')->where('completed_at', '>=', now()->startOfMonth())->sum('points'),
        ];

        if ($view === 'lista') {
            $status = $request->query('status', 'abertas');
            $tasks = (clone $base)
                ->when($status === 'abertas', fn ($q) => $q->open())
                ->when(in_array($status, array_keys(Options::TASK_STATUS)), fn ($q) => $q->where('status', $status))
                ->orderByRaw("status = 'concluida'")
                ->orderByRaw('due_at is null')->orderBy('due_at')
                ->paginate(25)->withQueryString();

            return view('tasks.index', compact('view', 'scope', 'tasks', 'groups', 'users', 'summary', 'status'));
        }

        $columns = [
            'todas' => (clone $base)->where('status', 'pendente')->orderByRaw('due_at is null')->orderBy('due_at')->get(),
            'hoje' => (clone $base)->where('status', 'pendente')->where('due_at', '<=', today()->endOfDay())->orderBy('due_at')->get(),
            'proximos' => (clone $base)->where('status', 'pendente')->whereBetween('due_at', [today()->addDay(), today()->addDays(7)->endOfDay()])->orderBy('due_at')->get(),
            'fazendo' => (clone $base)->where('status', 'fazendo')->orderByRaw('due_at is null')->orderBy('due_at')->get(),
            'concluidas' => (clone $base)->where('status', 'concluida')->where('completed_at', '>=', today())->latest('completed_at')->get(),
        ];
        $columnTitles = self::COLUMNS;

        return view('tasks.board', compact('view', 'scope', 'columns', 'columnTitles', 'groups', 'users', 'summary'));
    }

    public function create(Request $request): View
    {
        $task = new Task([
            'status' => 'pendente',
            'priority' => 'normal',
            'points' => 10,
            'legal_case_id' => $request->query('processo'),
            'contact_id' => $request->query('contato'),
            'due_at' => $this->queryDate($request)?->setTime(9, 0),
        ]);

        return view('tasks.form', $this->formData($task) + ['selectedUsers' => [$request->user()->id]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $task = Task::create($data + ['created_by' => $request->user()->id]);
        $task->users()->sync($request->input('users', [$request->user()->id]));

        return redirect()->to($this->backTo($request, route('tasks.index')))->with('success', 'Tarefa criada.');
    }

    public function show(Task $task): View
    {
        $task->load(['users', 'legalCase.client', 'contact', 'creator', 'comments.user']);

        return view('tasks.show', compact('task'));
    }

    public function edit(Task $task): View
    {
        return view('tasks.form', $this->formData($task) + ['selectedUsers' => $task->users()->pluck('users.id')->all()]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $task->update($this->validated($request));
        $task->users()->sync($request->input('users', []));

        return redirect()->to($this->backTo($request, route('tasks.show', $task)))->with('success', 'Tarefa atualizada.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Tarefa excluída.');
    }

    /** Arrastar e soltar no quadro (ou botão de concluir). */
    public function move(Request $request, Task $task): JsonResponse|RedirectResponse
    {
        $column = $request->validate(['column' => ['required', Rule::in(array_keys(self::COLUMNS))]])['column'];

        switch ($column) {
            case 'todas':
                $task->status = 'pendente';
                break;
            case 'hoje':
                $task->status = 'pendente';
                $task->due_at = ($task->due_at && $task->due_at->isToday()) ? $task->due_at : today()->setTime(18, 0);
                break;
            case 'proximos':
                $task->status = 'pendente';
                $inRange = $task->due_at && $task->due_at->between(today()->addDay(), today()->addDays(7)->endOfDay());
                $task->due_at = $inRange ? $task->due_at : today()->addDay()->setTime(18, 0);
                break;
            case 'fazendo':
                $task->status = 'fazendo';
                break;
            case 'concluidas':
                $task->status = 'concluida';
                break;
        }
        $task->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $task->status]);
        }

        return back()->with('success', $task->status === 'concluida' ? 'Tarefa concluída. +'.$task->points.' pontos!' : 'Tarefa atualizada.');
    }

    public function comment(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $task->comments()->create($data + ['user_id' => $request->user()->id]);

        return back()->with('success', 'Comentário adicionado.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(array_keys(Options::TASK_STATUS))],
            'priority' => ['required', Rule::in(array_keys(Options::TASK_PRIORITIES))],
            'group' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'is_deadline' => ['nullable', 'boolean'],
            'points' => ['required', 'integer', 'min:0', 'max:1000'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'users' => ['nullable', 'array'],
            'users.*' => ['exists:users,id'],
        ]);

        $data['due_at'] = ! empty($data['due_date'])
            ? $data['due_date'].' '.($data['due_time'] ?? '00:00')
            : null;
        $data['is_deadline'] = $request->boolean('is_deadline');
        unset($data['due_date'], $data['due_time'], $data['users']);

        return $data;
    }

    private function formData(Task $task): array
    {
        return [
            'task' => $task,
            'users' => User::where('active', true)->orderBy('name')->get(),
            'cases' => LegalCase::orderBy('title')->get(['id', 'title', 'number']),
            'contacts' => Contact::orderBy('name')->get(['id', 'name']),
            'groups' => Task::whereNotNull('group')->distinct()->orderBy('group')->pluck('group'),
        ];
    }
}
