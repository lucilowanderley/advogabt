@extends('layouts.app', ['title' => $task->exists ? 'Editar tarefa' : 'Nova tarefa'])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('tasks.index') }}">Tarefas</a> / {{ $task->exists ? 'Editar' : 'Nova' }}</div>
<div class="page-head"><h1>{{ $task->exists ? 'Editar tarefa' : 'Nova tarefa' }}</h1></div>

<form method="post" action="{{ $task->exists ? route('tasks.update', $task) : route('tasks.store') }}" class="card">
    @csrf
    @if ($task->exists) @method('put') @endif
    <input type="hidden" name="redirect" value="{{ old('redirect', url()->previous() !== url()->current() ? url()->previous() : '') }}">
    <div class="card-body form-grid">
        <x-field name="title" label="Título *" col="8">
            <input type="text" name="title" id="f-title" value="{{ old('title', $task->title) }}" required autofocus>
        </x-field>
        <x-field name="group" label="Grupo / equipe" col="4">
            <input type="text" name="group" id="f-group" value="{{ old('group', $task->group) }}" list="groups" placeholder="Ex.: Equipe Trabalhista">
            <datalist id="groups">@foreach ($groups as $g)<option value="{{ $g }}">@endforeach</datalist>
        </x-field>
        <x-field name="description" label="Descrição" col="12">
            <textarea name="description" id="f-description" rows="4">{{ old('description', $task->description) }}</textarea>
        </x-field>

        <x-field name="due_date" label="Prazo" col="3">
            <input type="date" name="due_date" id="f-due_date" value="{{ old('due_date', $task->due_at?->format('Y-m-d')) }}">
        </x-field>
        <x-field name="due_time" label="Horário" col="2">
            <input type="time" name="due_time" id="f-due_time" value="{{ old('due_time', $task->due_at && $task->due_at->format('H:i') !== '00:00' ? $task->due_at->format('H:i') : '') }}">
        </x-field>
        <x-field name="status" label="Situação" col="2">
            <x-select name="status" :options="Options::TASK_STATUS" :value="$task->status" />
        </x-field>
        <x-field name="priority" label="Prioridade" col="2">
            <x-select name="priority" :options="Options::TASK_PRIORITIES" :value="$task->priority" />
        </x-field>
        <x-field name="points" label="Pontos" col="3" hint="Somados ao concluir a tarefa.">
            <input type="number" name="points" id="f-points" min="0" max="1000" value="{{ old('points', $task->points) }}">
        </x-field>

        <x-field name="is_deadline" col="12">
            <label class="check"><input type="checkbox" name="is_deadline" value="1" @checked(old('is_deadline', $task->is_deadline))> É um prazo processual (aparece em destaque no painel)</label>
        </x-field>

        <x-field name="legal_case_id" label="Processo" col="6">
            <select name="legal_case_id" id="f-legal_case_id">
                <option value="">— Nenhum —</option>
                @foreach ($cases as $c)
                    <option value="{{ $c->id }}" @selected((string) old('legal_case_id', $task->legal_case_id) === (string) $c->id)>{{ $c->number ? $c->number.' — ' : '' }}{{ $c->title }}</option>
                @endforeach
            </select>
        </x-field>
        <x-field name="contact_id" label="Cliente / contato" col="6">
            <x-select name="contact_id" :options="$contacts->pluck('name', 'id')" :value="$task->contact_id" placeholder="— Nenhum —" />
        </x-field>

        <x-field name="users" label="Responsáveis" col="12">
            @php $sel = collect(old('users', $selectedUsers))->map(fn ($v) => (int) $v)->all(); @endphp
            <div class="checks">
                @foreach ($users as $u)
                    <label class="check"><input type="checkbox" name="users[]" value="{{ $u->id }}" @checked(in_array($u->id, $sel))> <x-avatar :user="$u" /> {{ $u->name }}</label>
                @endforeach
            </div>
        </x-field>

        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ url()->previous() }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
