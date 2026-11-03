@extends('layouts.app', ['title' => 'Notas fiscais'])
@php use App\Models\FiscalInvoice; use App\Support\Format; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Cobrança e notas fiscais</div>
<div class="page-head">
    <div>
        <h1>Notas fiscais</h1>
        <div class="sub">NFS-e dos honorários emitidas pelo Asaas na prefeitura do escritório.</div>
    </div>
</div>

@include('billing._tabs')

@unless ($nfse)
    <div class="alert warning small"><x-icon name="alert" /> <div>A emissão de NFS-e não está ligada.@can('admin') <a class="link" href="{{ route('billing.settings') }}#nfse">Configurar a NFS-e</a>@else Peça a um administrador para configurar em Integrações › Cobrança e notas fiscais.@endcan</div></div>
@endunless

<div class="grid grid-4 mb">
    <div class="card kpi"><div class="kpi-icon green"><x-icon name="file-text" /></div><div><div class="value">{{ Format::money($summary['mes']) }}</div><div class="label">Faturado em notas neste mês</div></div></div>
    <div class="card kpi"><div class="kpi-icon"><x-icon name="check-check" /></div><div><div class="value">{{ $summary['quantidade'] }}</div><div class="label">Notas emitidas no mês</div></div></div>
    <div class="card kpi"><div class="kpi-icon purple"><x-icon name="clock" /></div><div><div class="value">{{ $summary['processando'] }}</div><div class="label">Em processamento na prefeitura</div></div></div>
    <div class="card kpi"><div class="kpi-icon red"><x-icon name="alert" /></div><div><div class="value">{{ $summary['semNota'] }}</div><div class="label">Honorários recebidos no mês sem nota</div></div></div>
</div>

<form class="toolbar" method="get">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Número, serviço ou cliente..."></div>
    <select name="situacao" data-autosubmit>
        <option value="">Todas as situações</option>
        @foreach (FiscalInvoice::STATUSES as $k => $s)<option value="{{ $k }}" @selected(request('situacao') === $k)>{{ $s['label'] }}</option>@endforeach
    </select>
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nota</th><th>Tomador</th><th>Serviço</th><th class="right">Valor</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @forelse ($invoices as $inv)
                <tr>
                    <td class="nowrap"><strong>{{ $inv->number ? 'Nº '.$inv->number : '#'.$inv->id }}</strong><div class="small faint">{{ ($inv->issued_at ?? $inv->created_at)->format('d/m/Y') }}</div></td>
                    <td class="small">@if ($inv->contact)<a href="{{ route('contacts.show', $inv->contact) }}">{{ $inv->contact->name }}</a>@else — @endif</td>
                    <td class="small">{{ \Illuminate\Support\Str::limit($inv->service_description, 90) }}@if ($inv->entry)<div class="faint"><a href="{{ route('finance.edit', $inv->entry) }}">{{ $inv->entry->description }}</a></div>@endif
                        @if ($inv->last_error)<div class="text-red">{{ $inv->last_error }}</div>@endif</td>
                    <td class="right nowrap strong">{{ Format::money($inv->amount) }}</td>
                    <td><span class="badge {{ $inv->status_badge }}">{{ $inv->status_label }}</span>@if ($inv->remote_status === 'PROCESSING_CANCELLATION')<div class="small faint">cancelando…</div>@endif</td>
                    <td>
                        <div class="actions">
                            @if ($inv->pdf_url)<a class="btn btn-xs" target="_blank" rel="noopener" href="{{ $inv->pdf_url }}">PDF</a>@endif
                            @if ($inv->xml_url)<a class="btn btn-xs btn-ghost" target="_blank" rel="noopener" href="{{ $inv->xml_url }}">XML</a>@endif
                            @if ($inv->external_id && $inv->status === 'processando')
                                <form method="post" action="{{ route('billing.invoices.refresh', $inv) }}">@csrf<button class="btn btn-xs btn-ghost" title="Atualizar situação"><x-icon name="refresh" class="sm" /></button></form>
                            @endif
                            @can('admin')
                                @if (in_array($inv->status, ['emitida', 'erro'], true) || ($inv->status === 'processando' && $inv->remote_status !== 'PROCESSING_CANCELLATION'))
                                    <form method="post" action="{{ route('billing.invoices.cancel', $inv) }}" data-confirm="Cancelar esta nota fiscal na prefeitura?">@csrf<button class="btn btn-xs btn-ghost" title="Cancelar nota"><x-icon name="x" class="sm" /></button></form>
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty icon="file-text">Nenhuma nota emitida. Emita pela cobrança, pelo lançamento no Financeiro ou ligue a emissão automática ao receber.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $invoices->links() }}
@endsection
