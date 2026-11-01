@extends('reports.layout')
@php use App\Support\Format; $k = $clients['kpis']; $months = $reports->months(); @endphp

@section('report')
<div class="grid grid-4 mb">
    @include('reports._kpi', ['icon' => 'users', 'value' => $k['clients'], 'label' => 'Clientes ativos · '.$k['contacts'].' contatos no total'])
    @include('reports._kpi', ['icon' => 'plus', 'color' => 'blue', 'value' => $k['new'], 'label' => 'Novos clientes/leads no período · '.$k['new_won'].' fechado(s)'])
    @include('reports._kpi', ['icon' => 'funnel', 'color' => 'purple', 'value' => $k['pipeline'], 'label' => 'Em negociação (lead, contato, proposta)'])
    @include('reports._kpi', ['icon' => 'trophy', 'color' => 'green', 'value' => Format::percent($k['conversion']), 'label' => 'Taxa de conversão (fechados ÷ fechados + perdidos)'])
</div>

<div class="grid grid-main mb">
    <div class="card">
        <div class="card-head"><h2><x-icon name="chart" /> Novos clientes e leads por mês</h2></div>
        <div class="card-body">@include('reports._columns', ['series' => $clients['new_monthly'], 'months' => $months, 'color' => 'var(--blue)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="funnel" /> Funil (situação atual)</h2><a class="btn btn-sm btn-ghost no-print" href="{{ route('contacts.index', ['view' => 'funil']) }}">Abrir funil <x-icon name="chevron-right" class="sm" /></a></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $clients['funnel'], 'color' => 'var(--blue)'])</div>
    </div>
</div>

<div class="card mb">
    <div class="card-head"><h2><x-icon name="star" /> Conversão por origem</h2></div>
    @if (empty($clients['origins']))
        <x-empty icon="chart">Sem clientes cadastrados.</x-empty>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Origem</th><th class="right">Total</th><th class="right">Fechados</th><th class="right">Perdidos</th><th class="right">Em negociação</th><th class="right">Conversão</th></tr></thead>
                <tbody>
                @foreach ($clients['origins'] as $o)
                    <tr>
                        <td class="strong">{{ $o['label'] }}</td>
                        <td class="right">{{ $o['total'] }}</td>
                        <td class="right text-green">{{ $o['won'] }}</td>
                        <td class="right text-red">{{ $o['lost'] }}</td>
                        <td class="right">{{ $o['open'] }}</td>
                        <td class="right strong">{{ Format::percent($o['rate']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="grid grid-2 mb">
    <div class="card">
        <div class="card-head"><h2><x-icon name="map-pin" /> Clientes por cidade</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $clients['by_city'], 'color' => 'var(--green)'])</div>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2><x-icon name="user" /> Pessoa física × jurídica</h2></div>
            <div class="card-body">@include('reports._hbars', ['rows' => $clients['by_person_type'], 'color' => 'var(--purple)'])</div>
        </div>
        <div class="card">
            <div class="card-head"><h2><x-icon name="list" /> Contatos por categoria</h2></div>
            <div class="card-body">@include('reports._hbars', ['rows' => $clients['by_category'], 'color' => 'var(--faint)'])</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2><x-icon name="trophy" /> Principais clientes</h2></div>
    @if ($clients['top_clients']->isEmpty())
        <x-empty icon="users">Nenhum cliente com processos ou receitas.</x-empty>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Cliente</th><th class="right">Processos</th><th class="right">Ativos</th><th class="right">Receita recebida (total)</th></tr></thead>
                <tbody>
                @foreach ($clients['top_clients'] as $c)
                    <tr>
                        <td><a class="title" href="{{ route('contacts.show', $c) }}">{{ $c->name }}</a></td>
                        <td class="right">{{ $c->legal_cases_count }}</td>
                        <td class="right">{{ $c->active_cases_count }}</td>
                        <td class="right strong">{{ Format::money($c->revenue_total) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
