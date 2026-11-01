@extends('reports.layout')
@php use App\Support\Format; $months = $reports->months(); $f = $finance['kpis']; $c = $cases['kpis']; $cl = $clients['kpis']; $t = $team['kpis']; @endphp

@section('report')
<div class="grid grid-4 mb">
    @include('reports._kpi', ['icon' => 'arrow-up', 'color' => 'green', 'value' => Format::money($f['income']), 'label' => 'Receitas recebidas'])
    @include('reports._kpi', ['icon' => 'arrow-down', 'color' => 'red', 'value' => Format::money($f['expense']), 'label' => 'Despesas pagas'])
    @include('reports._kpi', ['icon' => 'wallet', 'value' => Format::money($f['result']), 'valueClass' => $f['result'] < 0 ? 'text-red' : '', 'label' => 'Resultado · margem '.Format::percent($f['margin'])])
    @include('reports._kpi', ['icon' => 'alert', 'color' => 'purple', 'value' => Format::money($f['overdue']), 'label' => 'Inadimplência ('.$f['overdue_count'].' lançamento(s))'])
    @include('reports._kpi', ['icon' => 'scale', 'value' => $c['active'], 'label' => 'Processos ativos · '.$c['opened'].' novo(s) no período'])
    @include('reports._kpi', ['icon' => 'users', 'color' => 'blue', 'value' => $cl['new'], 'label' => 'Novos clientes/leads · conversão '.Format::percent($cl['conversion'])])
    @include('reports._kpi', ['icon' => 'check-check', 'color' => 'green', 'value' => $t['done'], 'label' => 'Tarefas concluídas · '.Format::percent($t['on_time']).' no prazo'])
    @include('reports._kpi', ['icon' => 'gavel', 'color' => 'red', 'value' => $t['deadlines_overdue'], 'label' => 'Prazos vencidos · '.$t['deadlines_next'].' nos próximos 30 dias'])
</div>

<div class="grid grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-head">
                <h2><x-icon name="chart" /> Fluxo de caixa realizado</h2>
                <div class="legend"><span><i style="background: var(--accent)"></i> Receitas</span><span><i style="background: var(--faint)"></i> Despesas</span></div>
            </div>
            <div class="card-body">@include('reports._cashflow', ['monthly' => $finance['monthly']])</div>
        </div>
        <div class="grid grid-2">
            <div class="card">
                <div class="card-head"><h2><x-icon name="scale" /> Processos ativos por área</h2></div>
                <div class="card-body">@include('reports._hbars', ['rows' => array_slice($cases['by_area'], 0, 6)])</div>
            </div>
            <div class="card">
                <div class="card-head"><h2><x-icon name="arrow-up" /> Receitas por categoria</h2></div>
                <div class="card-body">@include('reports._hbars', ['rows' => array_slice($finance['income_by_category'], 0, 6), 'money' => true, 'color' => 'var(--green)'])</div>
            </div>
        </div>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2><x-icon name="funnel" /> Funil de clientes</h2></div>
            <div class="card-body">@include('reports._hbars', ['rows' => $clients['funnel'], 'color' => 'var(--blue)'])</div>
        </div>
        <div class="card">
            <div class="card-head"><h2><x-icon name="trophy" /> Pontos da equipe</h2><a class="btn btn-sm btn-ghost no-print" href="{{ route('reports.index', array_merge(request()->query(), ['aba' => 'equipe'])) }}">Detalhes <x-icon name="chevron-right" class="sm" /></a></div>
            <div class="card-body flush">
                @php $top = max(1, (int) $team['people']->max('points')); @endphp
                <ul class="list">
                    @foreach ($team['people']->take(6) as $p)
                        <li>
                            <x-avatar :user="$p['user']" />
                            <div class="grow">
                                <div class="row between"><span class="strong">{{ $p['user']->name }}</span><span class="small muted">{{ $p['points'] }} pts · {{ $p['done'] }} tarefa(s)</span></div>
                                <div class="progress" style="margin-top: 5px"><span style="width: {{ round($p['points'] / $top * 100) }}%"></span></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2><x-icon name="wallet" /> Posição atual</h2></div>
            <div class="card-body">
                <dl class="dl" style="grid-template-columns: 1fr auto">
                    <dt>A receber (em aberto)</dt><dd class="right strong">{{ Format::money($f['receivable']) }}</dd>
                    <dt>A pagar (em aberto)</dt><dd class="right">{{ Format::money($f['payable']) }}</dd>
                    <dt>Valor das causas ativas</dt><dd class="right">{{ Format::money($c['claim_value']) }}</dd>
                    <dt>Êxito estimado (ativos)</dt><dd class="right text-accent strong">{{ Format::money($c['expected_fees']) }}</dd>
                    <dt>Processos sem andamento</dt><dd class="right {{ $c['stale'] ? 'text-orange' : '' }}">{{ $c['stale'] }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
