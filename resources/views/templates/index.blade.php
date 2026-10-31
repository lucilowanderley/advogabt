@extends('layouts.app', ['title' => 'Modelos de documentos'])
@php use App\Support\Options; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Modelos de documentos</h1>
        <div class="sub">Procurações, contratos e petições preenchidos automaticamente com os dados do cliente e do processo.</div>
    </div>
    <a class="btn btn-primary" href="{{ route('templates.create') }}"><x-icon name="plus" class="sm" /> Novo modelo</a>
</div>

<form class="toolbar" method="get">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar modelo..."></div>
    <select name="categoria" data-autosubmit>
        <option value="">Todas as categorias</option>
        @foreach (Options::TEMPLATE_CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('categoria') === $k)>{{ $l }}</option>@endforeach
    </select>
</form>

@if ($templates->isEmpty())
    <div class="card"><x-empty icon="file-text">Nenhum modelo cadastrado.</x-empty></div>
@else
    <div class="grid grid-3">
        @foreach ($templates as $t)
            <a class="card" href="{{ route('templates.show', $t) }}" style="display: block">
                <div class="card-body">
                    <div class="row between">
                        <span class="kpi-icon" style="width: 38px; height: 38px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: var(--accent-soft); color: var(--accent)"><x-icon name="file-text" /></span>
                        <span class="badge">{{ Options::label(Options::TEMPLATE_CATEGORIES, $t->category) }}</span>
                    </div>
                    <h3 style="margin-top: 12px">{{ $t->name }}</h3>
                    <p class="small muted" style="margin-top: 6px">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $t->content), 110) }}</p>
                    <div class="small faint">Atualizado {{ $t->updated_at->diffForHumans() }}</div>
                </div>
            </a>
        @endforeach
    </div>
@endif
@endsection
