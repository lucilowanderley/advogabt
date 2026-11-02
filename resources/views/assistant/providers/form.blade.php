@extends('layouts.app', ['title' => $provider->exists ? 'Editar provedor de IA' : 'Novo provedor de IA'])
@php
    use App\Support\Ai\Drivers;
    $driver = old('driver', $provider->driver);
    $preset = Drivers::get($driver);
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('assistant.index') }}">Assistente IA</a> / <a href="{{ route('ai-providers.index') }}">Provedores</a> / {{ $provider->exists ? $provider->name : 'Novo' }}</div>
<div class="page-head"><h1>{{ $provider->exists ? 'Editar provedor de IA' : 'Novo provedor de IA' }}</h1></div>

<form method="post" action="{{ $provider->exists ? route('ai-providers.update', $provider) : route('ai-providers.store') }}" class="card" style="max-width: 900px" data-ai-provider-form>
    @csrf
    @if ($provider->exists) @method('put') @endif
    <div class="card-body form-grid">
        <x-field name="driver" label="Provedor *" col="6">
            <select name="driver" id="f-driver" data-ai-driver>
                @foreach (Drivers::ALL as $key => $d)
                    <option value="{{ $key }}" @selected($driver === $key)
                        data-base-url="{{ $d['base_url'] }}" data-models="{{ implode(',', $d['models']) }}"
                        data-default-model="{{ $d['default_model'] }}" data-needs-key="{{ $d['needs_key'] ? 1 : 0 }}"
                        data-hint="{{ $d['hint'] }}" data-pricing="{{ $d['pricing'] }}" data-pricing-note="{{ $d['pricing_note'] }}" data-keys-url="{{ $d['keys_url'] }}">{{ $d['label'] }}</option>
                @endforeach
            </select>
            <div class="row small" style="margin-top: 6px; gap: 6px" data-ai-pricing>
                <x-ai-pricing :pricing="$provider->exists ? $provider->pricing : $preset['pricing']" data-ai-pricing-badge />
                <span class="muted" data-ai-pricing-note>{{ $preset['pricing_note'] }}</span>
            </div>
        </x-field>
        <x-field name="name" label="Nome de exibição *" col="6" hint="Como aparece para a equipe, ex.: Claude do escritório.">
            <input type="text" name="name" id="f-name" value="{{ old('name', $provider->name) }}" required maxlength="100">
        </x-field>

        <div class="col-12 ai-preset-hint small" data-ai-hint>
            @if ($preset['hint'])<x-icon name="sparkles" class="sm" /> <span>{{ $preset['hint'] }}</span>@endif
        </div>

        <x-field name="model" label="Modelo *" col="6" hint="Escolha da lista ou digite exatamente o modelo contratado.">
            <div class="row" style="gap: 6px">
                <input type="text" name="model" id="f-model" list="ai-models" value="{{ old('model', $provider->model) }}" required maxlength="150" autocomplete="off">
                <button type="button" class="btn btn-sm nowrap" data-ai-load-models data-url="{{ route('ai-providers.models') }}" data-provider-id="{{ $provider->id }}" title="Consulta o provedor com a chave informada"><x-icon name="list" class="sm" /> Carregar modelos</button>
            </div>
            <select style="margin-top: 6px" data-ai-model-list hidden aria-label="Modelos disponíveis"></select>
            <div class="small" data-ai-models-status></div>
            <datalist id="ai-models" data-ai-models>
                @foreach ($preset['models'] as $m)<option value="{{ $m }}"></option>@endforeach
            </datalist>
        </x-field>
        <x-field name="api_key" label="Chave de API" col="6"
                 :hint="$provider->api_key ? 'Chave atual: '.$provider->masked_key.' — deixe em branco para manter.' : 'Fica criptografada no banco de dados.'">
            <input type="password" name="api_key" id="f-api_key" autocomplete="new-password" placeholder="{{ $provider->api_key ? '••••••••' : 'Cole a chave aqui' }}">
            <a class="small" data-ai-keys-link href="{{ $preset['keys_url'] ?: '#' }}" target="_blank" rel="noopener" @if (! $preset['keys_url']) hidden @endif>Onde obter a chave <x-icon name="external" class="sm" /></a>
        </x-field>

        <x-field name="base_url" label="URL base" col="12" :hint="'Deixe em branco para usar a padrão'.($preset['base_url'] ? ': '.$preset['base_url'] : '.').' Para servidor próprio, proxy ou gateway, informe a URL.'">
            <input type="url" name="base_url" id="f-base_url" value="{{ old('base_url', $provider->base_url) }}" placeholder="{{ $preset['base_url'] }}" data-ai-base-url>
        </x-field>

        <x-field name="max_tokens" label="Limite de tokens por resposta *" col="4" hint="4.096 ≈ 3 mil palavras. Peças longas: 8.000 a 16.000.">
            <input type="number" name="max_tokens" id="f-max_tokens" min="256" max="128000" step="1" value="{{ old('max_tokens', $provider->max_tokens) }}" required>
        </x-field>
        <x-field name="temperature" label="Temperatura" col="4" hint="Opcional (0 a 2). Ignorada no Claude.">
            <input type="number" name="temperature" id="f-temperature" min="0" max="2" step="0.1" value="{{ old('temperature', $provider->temperature) }}">
        </x-field>
        <x-field name="active" col="4">
            <label>&nbsp;</label>
            <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $provider->active))> Ativo</label>
            <label class="check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $provider->is_default))> Provedor padrão</label>
        </x-field>

        <x-field name="system_prompt" label="Instruções do escritório (opcional)" col="12" hint="Enviadas em todas as conversas. Ex.: estilo de redação, áreas de atuação, como assinar as peças.">
            <textarea name="system_prompt" id="f-system_prompt" rows="4" maxlength="5000">{{ old('system_prompt', $provider->system_prompt) }}</textarea>
        </x-field>

        <details class="col-12 small">
            <summary class="muted">Opções avançadas</summary>
            <div class="form-grid" style="margin-top: 10px">
                <x-field name="api_version" label="Versão da API (Azure OpenAI)" col="6"><input type="text" name="api_version" id="f-api_version" value="{{ old('api_version', $provider->option('api_version')) }}" placeholder="2024-10-21"></x-field>
                <x-field name="organization" label="Organização (OpenAI)" col="6"><input type="text" name="organization" id="f-organization" value="{{ old('organization', $provider->option('organization')) }}" placeholder="org-..."></x-field>
                @if ($provider->api_key)
                    <x-field name="clear_key" col="12"><label class="check"><input type="checkbox" name="clear_key" value="1"> Apagar a chave salva</label></x-field>
                @endif
            </div>
        </details>

        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ route('ai-providers.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
