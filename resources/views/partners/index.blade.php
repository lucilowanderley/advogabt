@extends('layouts.app', ['title' => 'Parceiros'])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Parceiros</h1>
        <div class="sub">Correspondentes, indicadores, peritos e escritórios parceiros.</div>
    </div>
    <a class="btn btn-primary" href="{{ route('partners.create') }}"><x-icon name="plus" class="sm" /> Novo parceiro</a>
</div>

<form class="toolbar" method="get">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Nome, OAB, cidade..."></div>
    <select name="tipo" data-autosubmit>
        <option value="">Todos os tipos</option>
        @foreach (Options::PARTNER_TYPES as $k => $l)<option value="{{ $k }}" @selected(request('tipo') === $k)>{{ $l }}</option>@endforeach
    </select>
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Parceiro</th><th>Tipo</th><th>Contato</th><th>Cidade</th><th class="right">Comissão</th><th class="right">Processos</th><th class="right">Repasses pagos</th></tr></thead>
            <tbody>
            @forelse ($partners as $p)
                <tr style="{{ $p->active ? '' : 'opacity: .55' }}">
                    <td><a class="title" href="{{ route('partners.show', $p) }}">{{ $p->name }}</a>@if ($p->oab)<div class="small faint">OAB {{ $p->oab }}</div>@endif</td>
                    <td><span class="badge">{{ Options::label(Options::PARTNER_TYPES, $p->type) }}</span></td>
                    <td class="small">{{ $p->phone }}@if ($p->email)<div class="faint">{{ $p->email }}</div>@endif</td>
                    <td>{{ $p->city ? $p->city.'/'.$p->state : '—' }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $p->commission_percent, 2, ',', '.'), '0'), ',') }}%</td>
                    <td class="right">{{ $p->legal_cases_count }}</td>
                    <td class="right">{{ Format::money($p->paid_total ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty icon="handshake">Nenhum parceiro cadastrado.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $partners->links() }}
</div>
@endsection
