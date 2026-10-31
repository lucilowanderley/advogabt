@extends('layouts.app', ['title' => $event->exists ? 'Editar compromisso' : 'Novo compromisso'])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('events.index') }}">Agenda</a> / {{ $event->exists ? 'Editar' : 'Novo' }}</div>
<div class="page-head">
    <h1>{{ $event->exists ? 'Editar compromisso' : 'Novo compromisso' }}</h1>
    @if ($event->exists)
        <form method="post" action="{{ route('events.destroy', $event) }}" data-confirm="Excluir este compromisso?">@csrf @method('delete')<button class="btn btn-danger"><x-icon name="trash" class="sm" /> Excluir</button></form>
    @endif
</div>

<form method="post" action="{{ $event->exists ? route('events.update', $event) : route('events.store') }}" class="card">
    @csrf
    @if ($event->exists) @method('put') @endif
    <div class="card-body form-grid">
        <x-field name="title" label="Título *" col="8">
            <input type="text" name="title" id="f-title" value="{{ old('title', $event->title) }}" required autofocus>
        </x-field>
        <x-field name="type" label="Tipo" col="4">
            <x-select name="type" :options="Options::EVENT_TYPES" :value="$event->type" />
        </x-field>
        <x-field name="date" label="Data *" col="4">
            <input type="date" name="date" id="f-date" value="{{ old('date', $event->starts_at?->format('Y-m-d')) }}" required>
        </x-field>
        <x-field name="start_time" label="Início" col="3">
            <input type="time" name="start_time" data-time-field value="{{ old('start_time', $event->starts_at?->format('H:i')) }}">
        </x-field>
        <x-field name="end_time" label="Término" col="3">
            <input type="time" name="end_time" data-time-field value="{{ old('end_time', $event->ends_at?->format('H:i')) }}">
        </x-field>
        <x-field name="all_day" col="2">
            <label>&nbsp;</label>
            <label class="check"><input type="checkbox" name="all_day" value="1" @checked(old('all_day', $event->all_day))> Dia inteiro</label>
        </x-field>
        <x-field name="location" label="Local / link" col="6">
            <input type="text" name="location" id="f-location" value="{{ old('location', $event->location) }}" placeholder="Fórum, sala virtual, endereço...">
        </x-field>
        <x-field name="user_id" label="Responsável" col="6">
            <x-select name="user_id" :options="$users->pluck('name', 'id')" :value="$event->user_id" placeholder="—" />
        </x-field>
        <x-field name="legal_case_id" label="Processo" col="6">
            <select name="legal_case_id" id="f-legal_case_id">
                <option value="">— Nenhum —</option>
                @foreach ($cases as $c)
                    <option value="{{ $c->id }}" @selected((string) old('legal_case_id', $event->legal_case_id) === (string) $c->id)>{{ $c->number ? $c->number.' — ' : '' }}{{ $c->title }}</option>
                @endforeach
            </select>
        </x-field>
        <x-field name="contact_id" label="Contato" col="6">
            <x-select name="contact_id" :options="$contacts->pluck('name', 'id')" :value="$event->contact_id" placeholder="— Nenhum —" />
        </x-field>
        <x-field name="notes" label="Observações" col="12">
            <textarea name="notes" id="f-notes">{{ old('notes', $event->notes) }}</textarea>
        </x-field>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ route('events.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
