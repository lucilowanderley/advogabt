@extends('reports.layout')
@php use App\Support\Format; $k = $finance['kpis']; @endphp

@section('report')
<div class="grid grid-4 mb">
    @include('reports._kpi', ['icon' => 'arrow-up', 'color' => 'green', 'value' => Format::money($k['income']), 'label' => 'Receitas recebidas · honorários '.Format::money($k['fees'])])
    @include('reports._kpi', ['icon' => 'arrow-down', 'color' => 'red', 'value' => Format::money($k['expense']), 'label' => 'Despesas pagas'])
    @include('reports._kpi', ['icon' => 'wallet', 'value' => Format::money($k['result']), 'valueClass' => $k['result'] < 0 ? 'text-red' : '', 'label' => 'Resultado · margem '.Format::percent($k['margin'])])
    @include('reports._kpi', ['icon' => 'check-check', 'color' => 'blue', 'value' => Format::percent($k['collection_rate']), 'label' => 'Recebido do faturado ('.Format::money($k['billed']).' com vencimento no período)'])
    @include('reports._kpi', ['icon' => 'dollar', 'value' => Format::money($k['avg_ticket']), 'label' => 'Ticket médio por recebimento'])
    @include('reports._kpi', ['icon' => 'clock', 'color' => 'blue', 'value' => Format::money($k['receivable']), 'label' => 'A receber em aberto (total)'])
    @include('reports._kpi', ['icon' => 'clock', 'color' => 'red', 'value' => Format::money($k['payable']), 'label' => 'A pagar em aberto (total)'])
    @include('reports._kpi', ['icon' => 'alert', 'color' => 'purple', 'value' => Format::money($k['overdue']), 'label' => 'Inadimplência · '.$k['overdue_count'].' lançamento(s) vencido(s)'])
</div>

<div class="card mb">
    <div class="card-head">
        <h2><x-icon name="chart" /> Fluxo de caixa mensal (realizado)</h2>
        <div class="legend"><span><i style="background: var(--accent)"></i> Receitas</span><span><i style="background: var(--faint)"></i> Despesas</span></div>
    </div>
    <div class="card-body">@include('reports._cashflow', ['monthly' => $finance['monthly']])</div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Mês</th><th class="right">Receitas</th><th class="right">Despesas</th><th class="right">Saldo</th><th class="right">Acumulado</th></tr></thead>
            <tbody>
            @foreach ($finance['monthly'] as $m)
                <tr>
                    <td class="cap">{{ $m['label'] }}</td>
                    <td class="right text-green">{{ Format::money($m['receita']) }}</td>
                    <td class="right text-red">{{ Format::money($m['despesa']) }}</td>
                    <td class="right strong {{ $m['saldo'] < 0 ? 'text-red' : '' }}">{{ Format::money($m['saldo']) }}</td>
                    <td class="right {{ $m['acumulado'] < 0 ? 'text-red' : '' }}">{{ Format::money($m['acumulado']) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><th>Total</th><th class="right">{{ Format::money($k['income']) }}</th><th class="right">{{ Format::money($k['expense']) }}</th><th class="right">{{ Format::money($k['result']) }}</th><th></th></tr></tfoot>
        </table>
    </div>
</div>

<div class="grid grid-2 mb">
    <div class="card">
        <div class="card-head"><h2><x-icon name="arrow-up" /> Receitas por categoria</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $finance['income_by_category'], 'money' => true, 'color' => 'var(--green)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="arrow-down" /> Despesas por categoria</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $finance['expense_by_category'], 'money' => true, 'color' => 'var(--red)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="alert" /> Inadimplência por tempo de atraso</h2><a class="btn btn-sm btn-ghost no-print" href="{{ route('finance.index', ['situacao' => 'atrasado', 'tipo' => 'receita']) }}">Ver lançamentos <x-icon name="chevron-right" class="sm" /></a></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $k['overdue'] > 0 ? $finance['aging'] : [], 'money' => true, 'color' => 'var(--orange)'])</div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="wallet" /> Recebimentos por forma de pagamento</h2></div>
        <div class="card-body">@include('reports._hbars', ['rows' => $finance['by_method'], 'money' => true, 'color' => 'var(--blue)'])</div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2><x-icon name="users" /> Maiores clientes (receita recebida)</h2></div>
        <div class="card-body flush">
            @include('reports._ranking', ['rows' => $finance['top_clients'], 'route' => 'contacts.show'])
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2><x-icon name="scale" /> Processos que mais geraram receita</h2></div>
        <div class="card-body flush">
            @include('reports._ranking', ['rows' => $finance['top_cases'], 'route' => 'cases.show'])
        </div>
    </div>
</div>
@endsection
