@extends('layouts.app', ['title' => 'Cobranças'])
@php use App\Models\BillingCharge; use App\Support\Format; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Cobrança e notas fiscais</div>
<div class="page-head">
    <div>
        <h1>Cobranças</h1>
        <div class="sub">Boletos e Pix dos honorários. Quando o cliente paga, o lançamento é baixado sozinho no Financeiro.</div>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="{{ route('finance.index', ['tipo' => 'receita', 'situacao' => 'aberto']) }}"><x-icon name="plus" class="sm" /> Cobrar um lançamento</a>
    </div>
</div>

@include('billing._tabs')

@if ($providers->isEmpty())
    <div class="alert warning small"><x-icon name="alert" /> <div>Nenhuma conta de cobrança configurada.@can('admin') <a class="link" href="{{ route('billing.settings') }}">Conectar o Asaas ou o Banco Inter</a>@else Peça a um administrador para conectar o Asaas ou o Banco Inter em Integrações.@endcan</div></div>
@endif

<div class="grid grid-4 mb">
    <div class="card kpi"><div class="kpi-icon"><x-icon name="clock" /></div><div><div class="value">{{ Format::money($summary['aberto']) }}</div><div class="label">Aguardando pagamento</div></div></div>
    <div class="card kpi"><div class="kpi-icon red"><x-icon name="alert" /></div><div><div class="value">{{ Format::money($summary['vencido']) }}</div><div class="label">Vencidas sem pagamento</div></div></div>
    <div class="card kpi"><div class="kpi-icon green"><x-icon name="check-check" /></div><div><div class="value">{{ Format::money($summary['recebido']) }}</div><div class="label">Recebido neste mês</div></div></div>
    <div class="card kpi"><div class="kpi-icon purple"><x-icon name="file-text" /></div><div><div class="value">{{ $summary['notas'] }}</div><div class="label">NFS-e emitidas no mês</div></div></div>
</div>

<div class="grid grid-main">
<div>
    <form class="toolbar" method="get">
        <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Descrição ou cliente..."></div>
        <select name="situacao" data-autosubmit>
            <option value="">Todas as situações</option>
            @foreach (BillingCharge::STATUSES as $k => $s)<option value="{{ $k }}" @selected(request('situacao') === $k)>{{ $s['label'] }}</option>@endforeach
        </select>
        <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
    </form>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Cobrança</th><th>Cliente</th><th>Vencimento</th><th class="right">Valor</th><th>Situação</th></tr></thead>
                <tbody>
                @forelse ($charges as $c)
                    <tr>
                        <td><a class="title" href="{{ route('billing.show', $c) }}">{{ $c->description }}</a><div class="small faint">{{ $c->provider_label }} · {{ $c->method_label }}</div></td>
                        <td class="small">@if ($c->contact)<a href="{{ route('contacts.show', $c->contact) }}">{{ $c->contact->name }}</a>@else — @endif</td>
                        <td class="nowrap small">{{ $c->due_date->format('d/m/Y') }}</td>
                        <td class="right nowrap strong">{{ Format::money($c->paid_amount ?? $c->amount) }}</td>
                        <td><span class="badge {{ $c->status_badge }}">{{ $c->status_label }}</span>@if ($c->paid_at)<div class="small faint">{{ $c->paid_at->format('d/m/Y') }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="dollar">Nenhuma cobrança gerada. No <a class="link" href="{{ route('finance.index') }}">Financeiro</a>, clique em <strong>Cobrar</strong> em uma receita em aberto.</x-empty></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $charges->links() }}
</div>

<div class="stack">
    <div class="card">
        <div class="card-head"><h3><x-icon name="wallet" /> Receitas para cobrar</h3></div>
        <div class="card-body flush">
            @if ($toCharge->isEmpty())
                <x-empty icon="check">Nenhuma receita em aberto sem cobrança nos próximos 45 dias.</x-empty>
            @else
                <ul class="list">
                    @foreach ($toCharge as $e)
                        <li>
                            <div class="grow">
                                <strong>{{ $e->description }}</strong>
                                <div class="small muted">{{ $e->contact?->name }} · venc. {{ $e->due_date->format('d/m/Y') }} · {{ Format::money($e->amount) }}</div>
                            </div>
                            @if ($providers->isNotEmpty())<a class="btn btn-xs" href="{{ route('billing.create', ['lancamento' => $e->id]) }}">Cobrar</a>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><x-icon name="book" /> Como funciona</h3></div>
        <div class="card-body small muted">
            <p style="margin-top: 0"><strong>1.</strong> Lance os honorários no Financeiro (com o cliente) e clique em <em>Cobrar</em>. Parcelas viram uma cobrança cada.</p>
            <p><strong>2.</strong> O sistema gera o boleto com Pix no Asaas ou no Banco Inter. Mande o link, a linha digitável ou o Pix copia e cola pelo WhatsApp com um clique.</p>
            <p style="margin-bottom: 0"><strong>3.</strong> Quando o cliente paga, o lançamento é baixado sozinho, o webhook <code>pagamento.recebido</code> avisa as automações e, se ligado, a NFS-e é emitida.</p>
        </div>
    </div>
</div>
</div>
@endsection
