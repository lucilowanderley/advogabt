@extends('layouts.app', ['title' => 'Publicações'])
@php use App\Models\CourtPublication; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Publicações</h1>
        <div class="sub">Intimações e publicações das OABs do escritório capturadas no DJEN (Diário de Justiça Eletrônico Nacional).</div>
    </div>
    <div class="page-actions">
        @can('admin')
            <a class="btn" href="{{ route('courts.settings') }}"><x-icon name="settings" class="sm" /> Configurar</a>
            @if ($settings->exists)
                <form method="post" action="{{ route('courts.run') }}">@csrf<button class="btn btn-primary"><x-icon name="refresh" class="sm" /> Buscar agora</button></form>
            @endif
        @endcan
    </div>
</div>

@if (! $settings->djenOn() || $lawyers->where('active', true)->isEmpty())
    <div class="alert warning small"><x-icon name="alert" /> <div>A captura de publicações ainda não está ligada ou não há OAB cadastrada.@can('admin') <a class="link" href="{{ route('courts.settings') }}">Cadastrar as OABs do escritório</a>@else Peça a um administrador para cadastrar as OABs em Integrações › Tribunais.@endcan</div></div>
@endif

<div class="tabs">
    @foreach (CourtPublication::STATUS as $k => $s)
        <a href="{{ route('publications.index', array_merge(request()->except('page'), ['situacao' => $k])) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $s['label'] }}s <span class="count">{{ $counts[$k] ?? 0 }}</span></a>
    @endforeach
    <a href="{{ route('publications.index', array_merge(request()->except('page'), ['situacao' => 'todas'])) }}" class="{{ $status === 'todas' ? 'active' : '' }}">Todas <span class="count">{{ $counts->sum() }}</span></a>
</div>

<form class="toolbar" method="get">
    <input type="hidden" name="situacao" value="{{ $status }}">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Número do processo, vara ou texto..."></div>
    @if ($lawyers->count() > 1)
        <select name="oab"><option value="">Todas as OABs</option>@foreach ($lawyers as $l)<option value="{{ $l->id }}" @selected((string) request('oab') === (string) $l->id)>{{ $l->name }} ({{ $l->label }})</option>@endforeach</select>
    @endif
    <select name="vinculo"><option value="">Com e sem processo</option><option value="sem" @selected(request('vinculo') === 'sem')>Sem processo cadastrado</option></select>
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>

<div class="card">
    <div class="card-body flush">
        @if ($publications->isEmpty())
            <x-empty icon="gavel">Nenhuma publicação {{ $status === 'nova' ? 'nova' : 'aqui' }}.</x-empty>
        @else
            <ul class="list">
                @foreach ($publications as $p)
                    <li style="align-items: flex-start">
                        <div class="date-box"><div class="d">{{ $p->available_at->format('d') }}</div><div class="m">{{ $p->available_at->translatedFormat('M') }}</div></div>
                        <div class="grow">
                            <a class="title" href="{{ route('publications.show', $p) }}">{{ $p->title }}</a>
                            <span class="badge">{{ $p->court ?: 'DJEN' }}</span>
                            @if ($status === 'todas')<span class="badge {{ CourtPublication::STATUS[$p->status]['badge'] }}">{{ CourtPublication::STATUS[$p->status]['label'] }}</span>@endif
                            <div class="small muted">
                                {{ $p->masked_number ?: 'Sem número' }}@if ($p->organ) · {{ $p->organ }}@endif
                                @if ($p->legalCase) · <a class="link" href="{{ route('cases.show', $p->legalCase) }}">{{ $p->legalCase->title }}</a>@else · <span class="text-red">processo não cadastrado</span>@endif
                            </div>
                            <div class="small faint">{{ $p->excerpt }}</div>
                        </div>
                        <div class="right small" style="min-width: 130px">
                            @if ($p->task)
                                <a href="{{ route('tasks.show', $p->task) }}"><span class="badge purple">Prazo {{ $p->task->due_at?->format('d/m') }}</span></a>
                            @else
                                <span class="faint">sem prazo</span>
                            @endif
                            @if ($p->lawyer)<div class="faint">{{ $p->lawyer->label }}</div>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
{{ $publications->links() }}
@endsection
