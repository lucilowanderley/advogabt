@extends('layouts.app', ['title' => 'Relatórios / BI'])
@php use App\Support\Reports; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Relatórios / BI</h1>
        <div class="sub">Indicadores do escritório · {{ $reports->label() }}</div>
    </div>
    <div class="page-actions no-print">
        <a class="btn" href="{{ route('reports.export', request()->query()) }}"><x-icon name="download" class="sm" /> Exportar CSV</a>
        <button class="btn" type="button" onclick="window.print()"><x-icon name="printer" class="sm" /> Imprimir / PDF</button>
    </div>
</div>

<form class="toolbar no-print" method="get" action="{{ route('reports.index') }}" data-report-filters>
    <input type="hidden" name="aba" value="{{ $tab }}">
    <select name="periodo" data-period-select>
        @foreach (Reports::PERIODS as $k => $l)<option value="{{ $k }}" @selected($reports->period === $k)>{{ $l }}</option>@endforeach
    </select>
    <span class="row" data-custom-range style="{{ $reports->period === 'personalizado' ? '' : 'display: none' }}">
        <input type="date" name="de" value="{{ $reports->from->toDateString() }}" aria-label="Data inicial">
        <span class="muted small">até</span>
        <input type="date" name="ate" value="{{ $reports->to->toDateString() }}" aria-label="Data final">
    </span>
    <button class="btn"><x-icon name="filter" class="sm" /> Aplicar</button>
</form>

<div class="tabs no-print">
    @foreach (Reports::TABS as $key => $t)
        <a href="{{ route('reports.index', array_merge(request()->except('aba'), ['aba' => $key])) }}" class="{{ $tab === $key ? 'active' : '' }}"><x-icon :name="$t['icon']" class="sm" /> {{ $t['label'] }}</a>
    @endforeach
</div>
<h2 class="print-only mb">{{ Reports::TABS[$tab]['label'] }} · {{ $reports->label() }}</h2>

@yield('report')
@endsection
