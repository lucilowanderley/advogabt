@extends('layouts.app', ['title' => $template->name])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('templates.index') }}">Modelos</a> / {{ $template->name }}</div>
<div class="page-head">
    <div>
        <h1>{{ $template->name }}</h1>
        <div class="row" style="margin-top: 6px"><span class="badge gold">{{ Options::label(Options::TEMPLATE_CATEGORIES, $template->category) }}</span></div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('templates.edit', $template) }}"><x-icon name="edit" class="sm" /> Editar</a>
        <form method="post" action="{{ route('templates.destroy', $template) }}" data-confirm="Excluir este modelo?">@csrf @method('delete')<button class="btn btn-danger"><x-icon name="trash" class="sm" /></button></form>
    </div>
</div>

<div class="card mb">
    <div class="card-head"><h3><x-icon name="printer" /> Gerar documento</h3></div>
    <form class="card-body form-grid" method="get" action="{{ route('templates.generate', $template) }}" target="_blank">
        <x-field name="contact_id" label="Cliente" col="5">
            <x-select name="contact_id" :options="$contacts->pluck('name', 'id')" placeholder="— Selecione —" />
        </x-field>
        <x-field name="legal_case_id" label="Processo (opcional)" col="5">
            <select name="legal_case_id" id="f-legal_case_id">
                <option value="">— Nenhum —</option>
                @foreach ($cases as $c)<option value="{{ $c->id }}">{{ $c->number ? $c->number.' — ' : '' }}{{ $c->title }}</option>@endforeach
            </select>
        </x-field>
        <div class="field col-2"><label>&nbsp;</label><button class="btn btn-primary" style="width: 100%">Gerar</button></div>
    </form>
</div>

<div class="card">
    <div class="card-head"><h3>Pré-visualização do modelo</h3></div>
    <div class="card-body" style="background: var(--bg-soft)">
        <div class="doc-paper" style="padding: 40px 48px">{{ $template->content }}</div>
    </div>
</div>
@endsection
