@extends('layouts.app', ['title' => 'Busca'])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="page-head"><div><h1>Resultados da busca</h1><div class="sub">{{ $term !== '' ? '“'.$term.'”' : 'Digite ao menos 2 caracteres na barra de busca.' }}</div></div></div>

<div class="grid grid-3">
    <div class="card">
        <div class="card-head"><h3><x-icon name="users" /> Contatos ({{ $contacts->count() }})</h3></div>
        <div class="card-body flush">
            @forelse ($contacts as $c)
                <ul class="list"><li><div class="grow"><a class="title" href="{{ route('contacts.show', $c) }}">{{ $c->name }}</a><div class="small muted">{{ Options::label(Options::CONTACT_CATEGORIES, $c->category) }} · {{ $c->document ?: $c->email }}</div></div></li></ul>
            @empty
                <x-empty icon="users">Nada encontrado.</x-empty>
            @endforelse
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><x-icon name="scale" /> Processos ({{ $cases->count() }})</h3></div>
        <div class="card-body flush">
            @forelse ($cases as $c)
                <ul class="list"><li><div class="grow"><a class="title" href="{{ route('cases.show', $c) }}">{{ $c->title }}</a><div class="small muted">{{ $c->number ?: 'Sem número' }} · {{ $c->client?->name }}</div></div></li></ul>
            @empty
                <x-empty icon="scale">Nada encontrado.</x-empty>
            @endforelse
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><x-icon name="check-square" /> Tarefas ({{ $tasks->count() }})</h3></div>
        <div class="card-body flush">
            @forelse ($tasks as $t)
                <ul class="list"><li><div class="grow"><a class="title" href="{{ route('tasks.show', $t) }}">{{ $t->title }}</a><div class="small muted">{{ Options::label(Options::TASK_STATUS, $t->status) }} · {{ Format::relativeDay($t->due_at) }}</div></div></li></ul>
            @empty
                <x-empty icon="check-square">Nada encontrado.</x-empty>
            @endforelse
        </div>
    </div>
</div>
@endsection
