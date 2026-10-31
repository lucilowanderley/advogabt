@extends('layouts.app', ['title' => 'Tarefas'])

@section('content')
@include('tasks._header')

@php
    $dots = ['todas' => 'var(--red)', 'hoje' => 'var(--faint)', 'proximos' => 'var(--blue)', 'fazendo' => 'var(--orange)', 'concluidas' => 'var(--green)'];
@endphp
<div class="board">
    @foreach ($columnTitles as $key => $label)
        <section class="column">
            <div class="column-head">
                <span class="dot-status" style="color: {{ $dots[$key] }}"></span> {{ $label }}
                <span class="count">{{ $columns[$key]->count() }}</span>
            </div>
            <div class="column-body" data-dropzone="{{ $key }}" data-field="column" data-reload>
                @forelse ($columns[$key] as $task)
                    @include('tasks._card', ['task' => $task])
                @empty
                    <div class="small faint" style="text-align: center; padding: 18px 0">Arraste tarefas para cá</div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
<p class="small faint mt">Dica: arraste os cartões entre as colunas. Soltar em <b>Hoje</b> ou <b>Próximos dias</b> ajusta o prazo; em <b>Concluídas</b> marca como feita e soma os pontos.</p>
@endsection
