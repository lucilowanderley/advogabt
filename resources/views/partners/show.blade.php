@extends('layouts.app', ['title' => $partner->name])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('partners.index') }}">Parceiros</a> / {{ $partner->name }}</div>
<div class="page-head">
    <div>
        <h1>{{ $partner->name }}</h1>
        <div class="row wrap" style="margin-top: 6px">
            <span class="badge gold">{{ Options::label(Options::PARTNER_TYPES, $partner->type) }}</span>
            @unless ($partner->active)<span class="badge red">Inativo</span>@endunless
        </div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('finance.create', ['tipo' => 'despesa', 'parceiro' => $partner->id]) }}"><x-icon name="dollar" class="sm" /> Lançar repasse</a>
        <a class="btn" href="{{ route('partners.edit', $partner) }}"><x-icon name="edit" class="sm" /> Editar</a>
        <form method="post" action="{{ route('partners.destroy', $partner) }}" data-confirm="Excluir este parceiro?">@csrf @method('delete')<button class="btn btn-danger"><x-icon name="trash" class="sm" /></button></form>
    </div>
</div>

@php
    $paid = $partner->financialEntries->where('type', 'despesa')->whereNotNull('paid_at')->sum('amount');
    $open = $partner->financialEntries->where('type', 'despesa')->whereNull('paid_at')->sum('amount');
@endphp
<div class="grid grid-3 mb">
    <div class="card kpi"><div class="kpi-icon"><x-icon name="scale" /></div><div><div class="value">{{ $partner->legalCases->count() }}</div><div class="label">Processos vinculados</div></div></div>
    <div class="card kpi"><div class="kpi-icon green"><x-icon name="check" /></div><div><div class="value">{{ Format::money($paid) }}</div><div class="label">Repasses pagos</div></div></div>
    <div class="card kpi"><div class="kpi-icon red"><x-icon name="clock" /></div><div><div class="value">{{ Format::money($open) }}</div><div class="label">Repasses em aberto</div></div></div>
</div>

<div class="grid grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><x-icon name="scale" /> Processos</h3></div>
            <div class="card-body flush">
                @if ($partner->legalCases->isEmpty())
                    <x-empty icon="scale">Nenhum processo vinculado.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($partner->legalCases as $case)
                            <li><div class="grow"><a class="title" href="{{ route('cases.show', $case) }}">{{ $case->title }}</a><div class="small muted">{{ $case->client?->name }} · {{ $case->number ?: 'Sem número' }}</div></div><span class="badge">{{ Options::label(Options::CASE_STATUS, $case->status) }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h3><x-icon name="wallet" /> Lançamentos</h3></div>
            <div class="card-body flush">@include('finance._mini', ['entries' => $partner->financialEntries])</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <dl class="dl" style="grid-template-columns: 100px 1fr">
                <dt>OAB</dt><dd>{{ $partner->oab ?: '—' }}</dd>
                <dt>CPF/CNPJ</dt><dd>{{ $partner->document ?: '—' }}</dd>
                <dt>E-mail</dt><dd>{{ $partner->email ?: '—' }}</dd>
                <dt>Telefone</dt><dd>{{ $partner->phone ?: '—' }}</dd>
                <dt>Cidade</dt><dd>{{ $partner->city ? $partner->city.'/'.$partner->state : '—' }}</dd>
                <dt>Comissão</dt><dd>{{ rtrim(rtrim(number_format((float) $partner->commission_percent, 2, ',', '.'), '0'), ',') }}%</dd>
                <dt>PIX</dt><dd>{{ $partner->pix_key ?: '—' }}</dd>
            </dl>
            @if ($partner->notes)<hr><div class="pre small">{{ $partner->notes }}</div>@endif
        </div>
    </div>
</div>
@endsection
