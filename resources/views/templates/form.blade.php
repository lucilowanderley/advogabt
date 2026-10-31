@extends('layouts.app', ['title' => $template->exists ? 'Editar modelo' : 'Novo modelo'])
@php use App\Support\Options; use App\Models\DocumentTemplate; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('templates.index') }}">Modelos</a> / {{ $template->exists ? $template->name : 'Novo' }}</div>
<div class="page-head"><h1>{{ $template->exists ? 'Editar modelo' : 'Novo modelo' }}</h1></div>

<form method="post" action="{{ $template->exists ? route('templates.update', $template) : route('templates.store') }}">
    @csrf
    @if ($template->exists) @method('put') @endif
    <div class="grid grid-wide">
        <div class="card">
            <div class="card-body form-grid">
                <x-field name="name" label="Nome do modelo *" col="8">
                    <input type="text" name="name" id="f-name" value="{{ old('name', $template->name) }}" required autofocus>
                </x-field>
                <x-field name="category" label="Categoria" col="4">
                    <x-select name="category" :options="Options::TEMPLATE_CATEGORIES" :value="$template->category" />
                </x-field>
                <x-field name="content" label="Conteúdo *" col="12">
                    <textarea name="content" id="f-content" class="code" required>{{ old('content', $template->content) }}</textarea>
                </x-field>
                <div class="col-12 form-actions">
                    <a class="btn btn-ghost" href="{{ $template->exists ? route('templates.show', $template) : route('templates.index') }}">Cancelar</a>
                    <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h3>Variáveis</h3></div>
            <div class="card-body">
                <p class="small muted">Clique para inserir no texto. Elas são substituídas pelos dados reais ao gerar o documento.</p>
                @foreach (DocumentTemplate::VARIABLES as $var => $desc)
                    <button type="button" class="btn btn-xs" style="margin: 0 4px 6px 0" data-insert-var="{{ $var }}" title="{{ $desc }}">{{ $var }}</button>
                @endforeach
            </div>
        </div>
    </div>
</form>
@endsection
