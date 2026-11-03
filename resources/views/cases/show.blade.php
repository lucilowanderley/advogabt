@extends('layouts.app', ['title' => $case->title])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('cases.index') }}">Processos</a> / {{ $case->number ?: $case->title }}</div>
<div class="page-head">
    <div>
        <h1>{{ $case->title }}</h1>
        <div class="sub">{{ $case->number ?: 'Sem número' }}</div>
        <div class="row wrap" style="margin-top: 8px">
            <span class="badge {{ $case->status === 'ativo' ? 'green' : '' }}">{{ Options::label(Options::CASE_STATUS, $case->status) }}</span>
            <span class="badge gold">{{ Options::label(Options::CASE_AREAS, $case->area) }}</span>
            <span class="badge">{{ Options::label(Options::CASE_PHASES, $case->phase) }}</span>
        </div>
    </div>
    <div class="page-actions">
        <div class="dropdown">
            <button class="btn" data-dropdown-toggle><x-icon name="plus" class="sm" /> Adicionar <x-icon name="chevron-down" class="sm" /></button>
            <div class="dropdown-menu">
                <a href="{{ route('tasks.create', ['processo' => $case->id, 'contato' => $case->contact_id]) }}"><x-icon name="check-square" /> Tarefa / prazo</a>
                <a href="{{ route('events.create', ['processo' => $case->id, 'contato' => $case->contact_id]) }}"><x-icon name="calendar" /> Audiência / compromisso</a>
                <a href="{{ route('finance.create', ['processo' => $case->id, 'contato' => $case->contact_id, 'tipo' => 'receita']) }}"><x-icon name="arrow-up" /> Receita (honorários)</a>
                <a href="{{ route('finance.create', ['processo' => $case->id, 'tipo' => 'despesa']) }}"><x-icon name="arrow-down" /> Despesa (custas)</a>
            </div>
        </div>
        <a class="btn" href="{{ route('assistant.index', ['processo' => $case->id]) }}"><x-icon name="sparkles" class="sm" /> Perguntar à IA</a>
        <a class="btn" href="{{ route('cases.edit', $case) }}"><x-icon name="edit" class="sm" /> Editar</a>
        <form method="post" action="{{ route('cases.destroy', $case) }}" data-confirm="Excluir este processo e todos os seus andamentos?">@csrf @method('delete')<button class="btn btn-danger"><x-icon name="trash" class="sm" /></button></form>
    </div>
</div>

<div class="grid grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><x-icon name="history" /> Andamentos</h3></div>
            <div class="card-body">
                <form method="post" action="{{ route('cases.movements.store', $case) }}" class="form-grid">
                    @csrf
                    <div class="field col-3"><input type="date" name="date" value="{{ old('date', today()->format('Y-m-d')) }}" required></div>
                    <div class="field col-9"><textarea name="description" rows="2" placeholder="Descreva o andamento, despacho, decisão ou publicação..." required style="min-height: 42px"></textarea></div>
                    <div class="col-12 form-actions" style="padding-top: 0"><button class="btn btn-primary btn-sm"><x-icon name="plus" class="sm" /> Registrar andamento</button></div>
                </form>
            </div>
            @if ($case->movements->isEmpty())
                <x-empty icon="history">Nenhum andamento registrado.</x-empty>
            @else
                <ul class="timeline" style="padding-bottom: 12px">
                    @foreach ($case->movements as $m)
                        <li>
                            <div class="row between">
                                <span class="when">{{ $m->date->format('d/m/Y') }} · {{ $m->user?->name ?? 'Sistema' }}</span>
                                <form method="post" action="{{ route('cases.movements.destroy', [$case, $m]) }}" data-confirm="Remover este andamento?">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Remover"><x-icon name="x" class="sm" /></button></form>
                            </div>
                            <div class="pre">{{ $m->description }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @include('cloud._card', ['owner' => $case, 'type' => 'processo'])

        @include('signatures._card', ['owner' => $case, 'type' => 'processo'])

        <div class="card">
            <div class="card-head">
                <h3><x-icon name="check-square" /> Tarefas e prazos</h3>
                <a class="btn btn-sm btn-ghost" href="{{ route('tasks.create', ['processo' => $case->id, 'contato' => $case->contact_id]) }}"><x-icon name="plus" class="sm" /></a>
            </div>
            <div class="card-body flush">
                @if ($case->tasks->isEmpty())
                    <x-empty icon="check-square">Nenhuma tarefa.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($case->tasks as $task)
                            <li>
                                <div class="grow">
                                    <a class="title" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                    <div class="small muted">@if ($task->is_deadline)<span class="badge purple">Prazo</span> @endif{{ $task->users->pluck('name')->implode(', ') }}</div>
                                </div>
                                <span class="small {{ $task->isOverdue() ? 'text-red' : 'muted' }}">{{ Format::relativeDay($task->due_at) }}</span>
                                <span class="badge {{ $task->status === 'concluida' ? 'green' : ($task->status === 'fazendo' ? 'blue' : '') }}">{{ Options::label(Options::TASK_STATUS, $task->status) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h3><x-icon name="wallet" /> Financeiro do processo</h3></div>
            <div class="card-body flush">@include('finance._mini', ['entries' => $case->financialEntries])</div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h3>Informações</h3></div>
            <div class="card-body">
                <dl class="dl" style="grid-template-columns: 110px 1fr">
                    <dt>Cliente</dt><dd>@if ($case->client)<a class="link" href="{{ route('contacts.show', $case->client) }}">{{ $case->client->name }}</a><div class="small faint">{{ Options::label(Options::CLIENT_ROLES, $case->client_role) }}</div>@else — @endif</dd>
                    <dt>Parte contrária</dt><dd>{{ $case->opposing_party ?: '—' }}</dd>
                    <dt>Tipo de ação</dt><dd>{{ $case->action_type ?: '—' }}</dd>
                    <dt>Tribunal</dt><dd>{{ $case->court ?: '—' }}</dd>
                    <dt>Vara</dt><dd>{{ $case->court_division ?: '—' }}</dd>
                    <dt>Comarca</dt><dd>{{ $case->district ?: '—' }}</dd>
                    <dt>Distribuição</dt><dd>{{ Format::date($case->distributed_at) }}</dd>
                    <dt>Valor da causa</dt><dd>{{ $case->claim_value !== null ? Format::money($case->claim_value) : '—' }}</dd>
                    <dt>Honorários êxito</dt><dd>{{ $case->fee_percent !== null ? rtrim(rtrim(number_format((float) $case->fee_percent, 2, ',', '.'), '0'), ',').'%' : '—' }}</dd>
                    <dt>Responsável</dt><dd>@if ($case->responsible)<div class="row"><x-avatar :user="$case->responsible" /> {{ $case->responsible->name }}</div>@else — @endif</dd>
                    <dt>Parceiro</dt><dd>@if ($case->partner)<a class="link" href="{{ route('partners.show', $case->partner) }}">{{ $case->partner->name }}</a>@else — @endif</dd>
                </dl>
                @if ($case->notes)<hr><div class="pre small">{{ $case->notes }}</div>@endif
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h3><x-icon name="calendar" /> Audiências e compromissos</h3></div>
            <div class="card-body flush">
                @if ($case->events->isEmpty())
                    <x-empty icon="calendar">Nada agendado.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($case->events as $event)
                            <li>
                                <div class="date-box"><div class="d">{{ $event->starts_at->format('d') }}</div><div class="m">{{ $event->starts_at->translatedFormat('M') }}</div></div>
                                <div class="grow"><a class="title" href="{{ route('events.edit', $event) }}">{{ $event->title }}</a><div class="small muted">{{ Options::label(Options::EVENT_TYPES, $event->type) }} · {{ $event->starts_at->format('d/m/Y H:i') }}</div></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
