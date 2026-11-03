@extends('layouts.app', ['title' => 'Agenda'])
@php use App\Support\Options; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Agenda</h1>
        <div class="sub">Audiências, reuniões, perícias e prazos do escritório.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('agenda-email.index') }}" title="Ver a agenda no Google Agenda, Outlook ou iPhone"><x-icon name="refresh" class="sm" /> Sincronizar com Google/Outlook</a>
        <a class="btn btn-primary" href="{{ route('events.create') }}"><x-icon name="plus" class="sm" /> Novo compromisso</a>
    </div>
</div>

<div class="grid grid-wide">
    <div class="card">
        <div class="card-head">
            <div class="row">
                <a class="btn btn-sm" href="{{ route('events.index', array_merge(request()->query(), ['mes' => $month->copy()->subMonth()->format('Y-m')])) }}"><x-icon name="chevron-left" class="sm" /></a>
                <h2 style="min-width: 170px; text-align: center; text-transform: capitalize">{{ $month->translatedFormat('F Y') }}</h2>
                <a class="btn btn-sm" href="{{ route('events.index', array_merge(request()->query(), ['mes' => $month->copy()->addMonth()->format('Y-m')])) }}"><x-icon name="chevron-right" class="sm" /></a>
                <a class="btn btn-sm btn-ghost" href="{{ route('events.index', array_merge(request()->query(), ['mes' => now()->format('Y-m')])) }}">Hoje</a>
            </div>
            <form method="get" class="row">
                <input type="hidden" name="mes" value="{{ $month->format('Y-m') }}">
                <select name="usuario" data-autosubmit style="width: auto">
                    <option value="">Toda a equipe</option>
                    @foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('usuario') == $u->id)>{{ $u->name }}</option>@endforeach
                </select>
                <select name="tipo" data-autosubmit style="width: auto">
                    <option value="">Todos os tipos</option>
                    @foreach (Options::EVENT_TYPES as $k => $l)<option value="{{ $k }}" @selected(request('tipo') === $k)>{{ $l }}</option>@endforeach
                </select>
            </form>
        </div>
        <div class="table-wrap">
            <table class="calendar">
                <thead><tr>@foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d)<th>{{ $d }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($weeks as $week)
                    <tr>
                        @foreach ($week as $day)
                            @php $key = $day->format('Y-m-d'); $items = $byDay[$key] ?? []; @endphp
                            <td class="{{ $day->month !== $month->month ? 'other' : '' }} {{ $day->isToday() ? 'today' : '' }}">
                                <div class="num"><span>{{ $day->day }}</span><a class="add" href="{{ route('events.create', ['data' => $key]) }}" title="Agendar neste dia"><x-icon name="plus" class="sm" /></a></div>
                                @foreach ($items['events'] ?? [] as $event)
                                    <a class="cal-item {{ $event->type }}" href="{{ route('events.edit', $event) }}" title="{{ $event->title }}{{ $event->location ? ' — '.$event->location : '' }}">
                                        {{ $event->all_day ? '' : $event->starts_at->format('H:i') }} {{ $event->title }}
                                    </a>
                                @endforeach
                                @foreach ($items['tasks'] ?? [] as $task)
                                    <a class="cal-item task" href="{{ route('tasks.show', $task) }}" title="Tarefa: {{ $task->title }}">
                                        <x-icon name="check-square" class="sm" style="width: 11px; height: 11px; vertical-align: -1px" /> {{ $task->title }}
                                    </a>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-body legend">
            <span><i style="background: var(--red)"></i> Audiência</span>
            <span><i style="background: var(--blue)"></i> Reunião</span>
            <span><i style="background: var(--purple)"></i> Prazo</span>
            <span><i style="background: var(--cyan)"></i> Perícia</span>
            <span><i style="background: var(--accent)"></i> Tarefa com prazo</span>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>Próximos compromissos</h3></div>
        <div class="card-body flush">
            @if ($upcoming->isEmpty())
                <x-empty icon="calendar">Nenhum compromisso agendado.</x-empty>
            @else
                <ul class="list">
                    @foreach ($upcoming as $event)
                        <li>
                            <div class="date-box"><div class="d">{{ $event->starts_at->format('d') }}</div><div class="m">{{ $event->starts_at->translatedFormat('M') }}</div></div>
                            <div class="grow">
                                <a class="title" href="{{ route('events.edit', $event) }}">{{ $event->title }}</a>
                                <div class="small muted">{{ $event->all_day ? 'Dia inteiro' : $event->starts_at->format('H:i') }} · {{ Options::label(Options::EVENT_TYPES, $event->type) }}</div>
                                @if ($event->legalCase)<div class="small faint">{{ $event->legalCase->display_name }}</div>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection
