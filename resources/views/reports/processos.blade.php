@extends('reports.layout')
@php use App\Support\Format; use App\Support\Reports; $k = $cases['kpis']; $months = $reports->months(); @endphp

@section('report')
<div class="grid grid-4 mb">
    @include('reports._kpi', ['icon' => 'scale', 'value' => $k['active'], 'label' => 'Processos ativos de '.$k['total'].' cadastrados'])
    @include('reports._kpi', ['icon' => 'plus', 'color' => 'blue', 'value' => $k['opened'], 'label' => 'Novos processos no período'])
    @include('reports._kpi', ['icon' => 'dollar', 'color' => 'green', 'value' => Format::money($k['expected_fees']), 'label' => 'Êxito estimado · causas de '.Format::money($k['claim_value'])])
    @include('reports._kpi', ['icon' => 'history', 'color' => 'red', 'value' => $k['stale'], 'label' => 'Ativos sem andamento há '.Reports::STALE_DAYS.'+ dias · '.$k['movements'].' andamento(s) no período'])
</div>

<div class="card mb">
    <div class="card-head"><h2><x-icon name="chart" /> Novos processos por mês</h2></div>
    <div class="card-body">@include('reports._columns', ['series' => $cases['opened_monthly'], 'months' => $months])</div>
</div>

<div class="grid grid-2 mb">
    <div class="card">
        <div class="card-head"><h2><x-icon name="briefcase" /> Ativos por área</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $cases['by_area']])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="gavel" /> Ativos por fase</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $cases['by_phase'], 'color' => 'var(--purple)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="user" /> Ativos por responsável</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $cases['by_responsible'], 'color' => 'var(--blue)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="map-pin" /> Ativos por comarca / tribunal</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $cases['by_district'], 'color' => 'var(--green)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="list" /> Todos os processos por situação</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $cases['by_status'], 'color' => 'var(--faint)'])</div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2><x-icon name="history" /> Processos ativos sem andamento há mais de {{ Reports::STALE_DAYS }} dias</h2></div>
    @if ($cases['stale']->isEmpty())
        <x-empty icon="check-check">Todos os processos ativos tiveram andamento recente.</x-empty>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Processo</th><th>Cliente</th><th>Responsável</th><th>Último andamento</th></tr></thead>
                <tbody>
                @foreach ($cases['stale'] as $p)
                    <tr>
                        <td><a class="title" href="{{ route('cases.show', $p) }}">{{ $p->display_name }}</a></td>
                        <td>{{ $p->client?->name ?? '—' }}</td>
                        <td>{{ $p->responsible?->name ?? '—' }}</td>
                        <td class="nowrap">@if ($p->last_movement){{ $p->last_movement->format('d/m/Y') }} <span class="faint small">({{ (int) $p->last_movement->diffInDays(today()) }} dias)</span>@else<span class="badge orange">Nenhum</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
