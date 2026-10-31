@extends('layouts.app', ['title' => 'Processos'])
@php use App\Support\Options; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Processos</h1>
        <div class="sub">Controle dos processos judiciais e administrativos do escritório.</div>
    </div>
    <a class="btn btn-primary" href="{{ route('cases.create') }}"><x-icon name="plus" class="sm" /> Novo processo</a>
</div>

<div class="tabs">
    @foreach (Options::CASE_STATUS + ['todos' => 'Todos'] as $k => $l)
        <a href="{{ route('cases.index', array_merge(request()->except('page', 'status'), ['status' => $k])) }}" class="{{ $status === $k ? 'active' : '' }}">
            {{ $l }} <span class="count">{{ $k === 'todos' ? $counts->sum() : ($counts[$k] ?? 0) }}</span>
        </a>
    @endforeach
</div>

<form class="toolbar" method="get">
    <input type="hidden" name="status" value="{{ $status }}">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Número, título, cliente, parte contrária..."></div>
    <select name="area" data-autosubmit>
        <option value="">Todas as áreas</option>
        @foreach (Options::CASE_AREAS as $k => $l)<option value="{{ $k }}" @selected(request('area') === $k)>{{ $l }}</option>@endforeach
    </select>
    <select name="responsavel" data-autosubmit>
        <option value="">Todos os responsáveis</option>
        @foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('responsavel') == $u->id)>{{ $u->name }}</option>@endforeach
    </select>
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Processo</th><th>Cliente</th><th>Área / fase</th><th>Órgão</th><th>Responsável</th><th class="right">Tarefas</th></tr></thead>
            <tbody>
            @forelse ($cases as $case)
                <tr>
                    <td>
                        <a class="title" href="{{ route('cases.show', $case) }}">{{ $case->title }}</a>
                        <div class="small faint">{{ $case->number ?: 'Sem número' }}</div>
                    </td>
                    <td>
                        @if ($case->client)<a href="{{ route('contacts.show', $case->client) }}">{{ $case->client->name }}</a>@else — @endif
                        @if ($case->opposing_party)<div class="small faint">x {{ $case->opposing_party }}</div>@endif
                    </td>
                    <td>
                        <span class="badge">{{ Options::label(Options::CASE_AREAS, $case->area) }}</span>
                        <div class="small muted">{{ Options::label(Options::CASE_PHASES, $case->phase) }}</div>
                    </td>
                    <td class="small">{{ $case->court_division ?: '—' }}@if ($case->district)<div class="faint">{{ $case->district }}</div>@endif</td>
                    <td>@if ($case->responsible)<div class="row"><x-avatar :user="$case->responsible" /> <span class="small">{{ $case->responsible->first_name }}</span></div>@else — @endif</td>
                    <td class="right">{{ $case->open_tasks_count }}</td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty icon="scale">Nenhum processo encontrado.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $cases->links() }}
</div>
@endsection
