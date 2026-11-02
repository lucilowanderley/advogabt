@extends('layouts.app', ['title' => $webhook->exists ? 'Editar webhook' : 'Novo webhook'])
@php
    use App\Support\Integrations\WebhookEvents;
    $selected = old('events', $webhook->events ?? []);
    $all = (bool) old('all_events', in_array('*', $webhook->events ?? [], true));
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / <a href="{{ route('integrations.webhooks.index') }}">Webhooks</a> / {{ $webhook->exists ? $webhook->name : 'Novo' }}</div>
<div class="page-head"><h1>{{ $webhook->exists ? 'Editar webhook' : 'Novo webhook' }}</h1></div>

<form method="post" action="{{ $webhook->exists ? route('integrations.webhooks.update', $webhook) : route('integrations.webhooks.store') }}" class="card" style="max-width: 900px">
    @csrf
    @if ($webhook->exists) @method('put') @endif
    <div class="card-body form-grid">
        <x-field name="name" label="Nome *" col="4" hint="Ex.: n8n · Avisar cliente no WhatsApp">
            <input type="text" name="name" id="f-name" value="{{ old('name', $webhook->name) }}" required maxlength="100">
        </x-field>
        <x-field name="url" label="URL de destino *" col="8" hint="Endereço que recebe os avisos (POST em JSON). No n8n, a Production URL do nó Webhook.">
            <input type="url" name="url" id="f-url" value="{{ old('url', $webhook->url) }}" required maxlength="500" placeholder="https://seu-n8n.com.br/webhook/advogabt">
        </x-field>

        <div class="field col-12">
            <label>Eventos *</label>
            <label class="check"><input type="checkbox" name="all_events" value="1" @checked($all) data-all-events> <strong>Todos os eventos</strong> <span class="small muted">(inclusive os que forem criados no futuro)</span></label>
            <div class="event-groups" data-event-list @if ($all) hidden @endif>
                @foreach (WebhookEvents::grouped() as $group => $events)
                    <fieldset>
                        <legend>{{ $group }}</legend>
                        @foreach ($events as $key => $e)
                            <label class="check event-option">
                                <input type="checkbox" name="events[]" value="{{ $key }}" @checked(in_array($key, $selected, true))>
                                <span><strong>{{ $e['label'] }}</strong> <code class="faint">{{ $key }}</code><br><span class="small muted">{{ $e['description'] }}</span></span>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
            @error('events')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="field col-12">
            <label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $webhook->active))> Ativo (desmarque para pausar os envios)</label>
        </div>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ $webhook->exists ? route('integrations.webhooks.show', $webhook) : route('integrations.webhooks.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
