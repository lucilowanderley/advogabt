@extends('reports.layout')
@php use App\Support\Format; $k = $team['kpis']; $months = $reports->months(); @endphp

@section('report')
<div class="grid grid-4 mb">
    @include('reports._kpi', ['icon' => 'check-check', 'color' => 'green', 'value' => $k['done'], 'label' => 'Tarefas concluídas · '.number_format($k['points'], 0, ',', '.').' pontos'])
    @include('reports._kpi', ['icon' => 'clock', 'color' => 'blue', 'value' => Format::percent($k['on_time']), 'label' => 'Concluídas no prazo'])
    @include('reports._kpi', ['icon' => 'alert', 'color' => 'red', 'value' => $k['overdue'], 'label' => 'Atrasadas de '.$k['open'].' em aberto'])
    @include('reports._kpi', ['icon' => 'calendar', 'color' => 'purple', 'value' => $k['events'], 'label' => 'Compromissos · '.$k['hearings'].' audiência(s)'])
</div>

<div class="card mb">
    <div class="card-head"><h2><x-icon name="gavel" /> Prazos processuais</h2></div>
    <div class="card-body">
        <div class="grid grid-4">
            <div><div class="stat-value">{{ $k['deadlines_done'] }}</div><div class="small muted">cumpridos no período</div></div>
            <div><div class="stat-value text-green">{{ Format::percent($k['deadlines_on_time']) }}</div><div class="small muted">cumpridos dentro do prazo</div></div>
            <div><div class="stat-value {{ $k['deadlines_overdue'] ? 'text-red' : '' }}">{{ $k['deadlines_overdue'] }}</div><div class="small muted">vencidos e ainda em aberto</div></div>
            <div><div class="stat-value text-orange">{{ $k['deadlines_next'] }}</div><div class="small muted">vencem nos próximos 30 dias</div></div>
        </div>
    </div>
</div>

<div class="card mb">
    <div class="card-head"><h2><x-icon name="trophy" /> Produtividade por pessoa</h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Usuário</th><th class="right">Concluídas</th><th class="right">Pontos</th><th class="right">No prazo</th><th class="right">Em aberto</th><th class="right">Atrasadas</th></tr></thead>
            <tbody>
            @foreach ($team['people'] as $p)
                <tr>
                    <td><div class="row"><x-avatar :user="$p['user']" /> <span class="strong">{{ $p['user']->name }}</span></div></td>
                    <td class="right">{{ $p['done'] }}</td>
                    <td class="right strong text-accent">{{ $p['points'] }}</td>
                    <td class="right">{{ Format::percent($p['on_time']) }}</td>
                    <td class="right">{{ $p['open'] }}</td>
                    <td class="right {{ $p['overdue'] ? 'text-red strong' : '' }}">{{ $p['overdue'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-main mb">
    <div class="card">
        <div class="card-head"><h2><x-icon name="chart" /> Tarefas concluídas por mês</h2></div>
        <div class="card-body">@include('reports._columns', ['series' => $team['done_monthly'], 'months' => $months, 'color' => 'var(--green)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="alert" /> Em aberto por prioridade</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $team['open_by_priority'], 'color' => 'var(--orange)'])</div>
    </div>
</div>

<div class="grid grid-main">
    <div class="card">
        <div class="card-head"><h2><x-icon name="calendar" /> Compromissos por mês</h2></div>
        <div class="card-body">@include('reports._columns', ['series' => $team['events_monthly'], 'months' => $months, 'color' => 'var(--purple)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="calendar" /> Compromissos por tipo</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $team['events_by_type'], 'color' => 'var(--purple)'])</div>
    </div>
</div>
@endsection
