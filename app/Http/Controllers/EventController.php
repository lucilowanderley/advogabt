<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Event;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(Request $request): View
    {
        try {
            $month = Carbon::createFromFormat('Y-m', (string) $request->query('mes', now()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            $month = now()->startOfMonth();
        }

        $gridStart = $month->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);
        $userId = $request->query('usuario');

        $events = Event::with(['legalCase', 'user'])
            ->whereBetween('starts_at', [$gridStart, $gridEnd->copy()->endOfDay()])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($request->query('tipo'), fn ($q, $t) => $q->where('type', $t))
            ->orderBy('starts_at')->get();

        $tasks = $request->boolean('tarefas', true)
            ? Task::open()->whereNotNull('due_at')
                ->whereBetween('due_at', [$gridStart, $gridEnd->copy()->endOfDay()])
                ->when($userId, fn ($q) => $q->forUser((int) $userId))
                ->orderBy('due_at')->get()
            : collect();

        $byDay = [];
        foreach ($events as $event) {
            $byDay[$event->starts_at->format('Y-m-d')]['events'][] = $event;
        }
        foreach ($tasks as $task) {
            $byDay[$task->due_at->format('Y-m-d')]['tasks'][] = $task;
        }

        $weeks = [];
        for ($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay()) {
            $weeks[intdiv($gridStart->diffInDays($day), 7)][] = $day->copy();
        }

        $upcoming = Event::with('legalCase')->where('starts_at', '>=', now()->startOfDay())
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('starts_at')->limit(10)->get();

        $users = User::where('active', true)->orderBy('name')->get();

        return view('events.index', compact('month', 'weeks', 'byDay', 'upcoming', 'users'));
    }

    public function create(Request $request): View
    {
        $date = $this->queryDate($request) ?? today();
        $event = new Event([
            'type' => 'reuniao',
            'starts_at' => $date->copy()->setTime(9, 0),
            'ends_at' => $date->copy()->setTime(10, 0),
            'user_id' => $request->user()->id,
            'legal_case_id' => $request->query('processo'),
            'contact_id' => $request->query('contato'),
        ]);

        return view('events.form', $this->formData($event));
    }

    public function store(Request $request): RedirectResponse
    {
        $event = Event::create($this->validated($request));

        return redirect()->route('events.index', ['mes' => $event->starts_at->format('Y-m')])->with('success', 'Compromisso agendado.');
    }

    public function edit(Event $event): View
    {
        return view('events.form', $this->formData($event));
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $event->update($this->validated($request));

        return redirect()->route('events.index', ['mes' => $event->starts_at->format('Y-m')])->with('success', 'Compromisso atualizado.');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $month = $event->starts_at->format('Y-m');
        $event->delete();

        return redirect()->route('events.index', ['mes' => $month])->with('success', 'Compromisso excluído.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Options::EVENT_TYPES))],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'all_day' => ['nullable', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $allDay = $request->boolean('all_day');
        $start = Carbon::parse($data['date'].' '.($allDay ? '00:00' : ($data['start_time'] ?? '09:00')));
        $end = (! $allDay && ! empty($data['end_time'])) ? Carbon::parse($data['date'].' '.$data['end_time']) : null;
        if ($end && $end->lt($start)) {
            $end = null;
        }

        unset($data['date'], $data['start_time'], $data['end_time']);

        return $data + ['starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay];
    }

    private function formData(Event $event): array
    {
        return [
            'event' => $event,
            'users' => User::where('active', true)->orderBy('name')->get(),
            'cases' => LegalCase::orderBy('title')->get(['id', 'title', 'number']),
            'contacts' => Contact::orderBy('name')->get(['id', 'name']),
        ];
    }
}
