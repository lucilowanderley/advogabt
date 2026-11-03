@extends('layouts.app', ['title' => 'Financeiro'])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Financeiro</h1>
        <div class="sub">Contas a receber e a pagar, honorários e repasses.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('finance.create', ['tipo' => 'despesa']) }}"><x-icon name="arrow-down" class="sm" /> Nova despesa</a>
        <a class="btn btn-primary" href="{{ route('finance.create', ['tipo' => 'receita']) }}"><x-icon name="arrow-up" class="sm" /> Nova receita</a>
    </div>
</div>

<div class="row mb">
    <a class="btn btn-sm" href="{{ route('finance.index', array_merge(request()->except('page'), ['mes' => $month->copy()->subMonth()->format('Y-m')])) }}"><x-icon name="chevron-left" class="sm" /></a>
    <h2 style="min-width: 170px; text-align: center; text-transform: capitalize">{{ $month->translatedFormat('F Y') }}</h2>
    <a class="btn btn-sm" href="{{ route('finance.index', array_merge(request()->except('page'), ['mes' => $month->copy()->addMonth()->format('Y-m')])) }}"><x-icon name="chevron-right" class="sm" /></a>
</div>

<div class="grid grid-4 mb">
    <div class="card kpi"><div class="kpi-icon green"><x-icon name="arrow-up" /></div><div><div class="value">{{ Format::money($summary['recebido']) }}</div><div class="label">Recebido de {{ Format::money($summary['receitas']) }}</div></div></div>
    <div class="card kpi"><div class="kpi-icon red"><x-icon name="arrow-down" /></div><div><div class="value">{{ Format::money($summary['pago']) }}</div><div class="label">Pago de {{ Format::money($summary['despesas']) }}</div></div></div>
    <div class="card kpi"><div class="kpi-icon"><x-icon name="wallet" /></div><div><div class="value {{ $summary['saldo'] < 0 ? 'text-red' : '' }}">{{ Format::money($summary['saldo']) }}</div><div class="label">Saldo realizado · previsto {{ Format::money($summary['previsto']) }}</div></div></div>
    <div class="card kpi"><div class="kpi-icon purple"><x-icon name="alert" /></div><div><div class="value">{{ Format::money($summary['atrasado']) }}</div><div class="label">Recebimentos atrasados (total)</div></div></div>
</div>

<form class="toolbar" method="get">
    <input type="hidden" name="mes" value="{{ $month->format('Y-m') }}">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Descrição..."></div>
    <select name="tipo" data-autosubmit>
        <option value="">Receitas e despesas</option>
        @foreach (Options::FINANCE_TYPES as $k => $l)<option value="{{ $k }}" @selected(request('tipo') === $k)>{{ $l }}s</option>@endforeach
    </select>
    <select name="situacao" data-autosubmit>
        <option value="">Todas as situações</option>
        <option value="aberto" @selected(request('situacao') === 'aberto')>Em aberto</option>
        <option value="atrasado" @selected(request('situacao') === 'atrasado')>Atrasados</option>
        <option value="pago" @selected(request('situacao') === 'pago')>Pagos / recebidos</option>
    </select>
    <select name="categoria" data-autosubmit>
        <option value="">Todas as categorias</option>
        @foreach (Options::FINANCE_CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('categoria') === $k)>{{ $l }}</option>@endforeach
    </select>
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>

<div class="grid grid-wide">
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th class="right">Valor</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                @forelse ($entries as $entry)
                    @php $s = $entry->situation; @endphp
                    <tr>
                        <td class="nowrap">{{ $entry->due_date->format('d/m/Y') }}</td>
                        <td>
                            <a class="title" href="{{ route('finance.edit', $entry) }}">{{ $entry->description }}</a>
                            <div class="small faint">
                                {{ collect([$entry->contact?->name, $entry->legalCase?->number ?: $entry->legalCase?->title, $entry->partner?->name])->filter()->implode(' · ') }}
                            </div>
                        </td>
                        <td class="small">{{ $entry->category ? Options::label(Options::FINANCE_CATEGORIES, $entry->category) : '—' }}</td>
                        <td class="right nowrap strong {{ $entry->type === 'receita' ? 'text-green' : 'text-red' }}">{{ $entry->type === 'receita' ? '+' : '−' }} {{ Format::money($entry->amount) }}</td>
                        <td>
                            <span class="badge {{ $s === 'pago' ? 'green' : ($s === 'atrasado' ? 'red' : 'orange') }}">
                                {{ $s === 'pago' ? ($entry->type === 'receita' ? 'Recebido' : 'Pago').' '.$entry->paid_at->format('d/m') : ($s === 'atrasado' ? 'Atrasado' : 'Em aberto') }}
                            </span>
                            @if ($entry->currentCharge)
                                <a class="badge {{ $entry->currentCharge->status_badge }}" href="{{ route('billing.show', $entry->currentCharge) }}" title="{{ $entry->currentCharge->provider_label }}: {{ $entry->currentCharge->status_label }}"><x-icon name="dollar" class="sm" /> {{ $entry->currentCharge->isOpen() ? 'Boleto/Pix' : $entry->currentCharge->status_label }}</a>
                            @endif
                        </td>
                        <td>
                            <div class="actions">
                                @if ($canCharge && $entry->type === 'receita' && ! $entry->paid_at && $entry->contact_id && ! $entry->currentCharge?->isOpen())
                                    <a class="btn btn-xs" href="{{ route('billing.create', ['lancamento' => $entry->id]) }}" title="Gerar boleto/Pix"><x-icon name="dollar" class="sm" /> <span class="hide-sm">Cobrar</span></a>
                                @endif
                                <form method="post" action="{{ route('finance.toggle', $entry) }}">
                                    @csrf @method('patch')
                                    <button class="btn btn-xs {{ $entry->paid_at ? 'btn-ghost' : '' }}" title="{{ $entry->paid_at ? 'Estornar baixa' : 'Dar baixa' }}">
                                        <x-icon :name="$entry->paid_at ? 'history' : 'check'" class="sm" /> <span class="hide-sm">{{ $entry->paid_at ? 'Estornar' : 'Baixar' }}</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty icon="wallet">Nenhum lançamento neste mês.</x-empty></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $entries->links() }}
    </div>

    <div class="card">
        <div class="card-head"><h3>Receitas por categoria</h3></div>
        <div class="card-body">
            @php $totalCat = max(1, $byCategory->sum('total')); @endphp
            @forelse ($byCategory as $row)
                <div class="mb">
                    <div class="row between small"><span>{{ $row->category ? Options::label(Options::FINANCE_CATEGORIES, $row->category) : 'Sem categoria' }}</span><span class="muted">{{ Format::money($row->total) }}</span></div>
                    <div class="progress" style="margin-top: 5px"><span style="width: {{ round($row->total / $totalCat * 100) }}%"></span></div>
                </div>
            @empty
                <div class="faint small">Sem receitas no mês.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
