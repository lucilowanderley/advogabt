@extends('layouts.app', ['title' => 'Funil de clientes'])
@php use App\Support\Options; @endphp

@section('content')
@include('contacts._tabs')

@php $dots = ['lead' => 'var(--faint)', 'contato' => 'var(--blue)', 'proposta' => 'var(--orange)', 'cliente' => 'var(--green)', 'perdido' => 'var(--red)']; @endphp
<div class="board">
    @foreach ($stages as $key => $stage)
        <section class="column">
            <div class="column-head">
                <span class="dot-status" style="color: {{ $dots[$key] }}"></span> {{ $stage['label'] }}
                <span class="count">{{ $stage['contacts']->count() }}</span>
                <a class="btn btn-xs btn-ghost" href="{{ route('contacts.create', ['etapa' => $key]) }}" title="Adicionar"><x-icon name="plus" class="sm" /></a>
            </div>
            <div class="column-body" data-dropzone="{{ $key }}" data-field="crm_stage">
                @foreach ($stage['contacts'] as $contact)
                    <div class="lead-card" data-draggable data-id="{{ $contact->id }}" data-move-url="{{ route('contacts.stage', $contact) }}">
                        <a class="n" href="{{ route('contacts.show', $contact) }}">{{ $contact->name }}</a>
                        <div class="small muted">{{ $contact->origin ? Options::label(Options::CONTACT_ORIGINS, $contact->origin) : 'Origem não informada' }}</div>
                        <div class="row small faint" style="margin-top: 6px">
                            @if ($contact->phone)<x-icon name="phone" class="sm" /> {{ $contact->phone }}@endif
                            @if ($contact->whatsapp_link)<a class="link" style="margin-left: auto" target="_blank" rel="noopener" href="{{ $contact->whatsapp_link }}"><x-icon name="whatsapp" class="sm" /></a>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
<p class="small faint mt">Arraste os cartões entre as etapas. Ao mover para <b>Cliente ativo</b>, o contato passa a ser classificado como cliente.</p>
@endsection
