@extends('layouts.app', ['title' => $channel->exists ? 'Editar canal de WhatsApp' : 'Conectar WhatsApp'])
@php
    use App\Models\WhatsappChannel;
    $driver = old('driver', $channel->driver);
    $isMeta = $driver === 'meta';
    $team = old('team_user_ids', $channel->team_user_ids ?? []);
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('whatsapp.index') }}">WhatsApp</a> / <a href="{{ route('whatsapp.channels.index') }}">Canais</a> / {{ $channel->exists ? $channel->name : 'Novo' }}</div>
<div class="page-head">
    <div>
        <h1>{{ $channel->exists ? $channel->name : 'Conectar WhatsApp' }}</h1>
        <div class="sub">{{ WhatsappChannel::DRIVERS[$driver]['label'] }} · {{ WhatsappChannel::DRIVERS[$driver]['note'] }}</div>
    </div>
    @if ($channel->exists)
        <form method="post" action="{{ route('whatsapp.channels.test', $channel) }}" class="row">
            @csrf
            <input type="text" name="phone" placeholder="Celular para teste (opcional)" style="width: 220px">
            <button class="btn"><x-icon name="plug" class="sm" /> Testar conexão</button>
        </form>
    @endif
</div>

@if ($channel->exists)
    <div class="card mb new-secret">
        <div class="card-body">
            <h3 class="row"><x-icon name="webhook" /> URL de recebimento das mensagens</h3>
            <p class="small muted">{{ $isMeta ? 'No painel do app na Meta (WhatsApp › Configuração › Webhook), use esta URL e o token de verificação abaixo, e assine o campo "messages".' : 'No painel da Evolution API, em Webhook da instância, cole esta URL, ligue o evento MESSAGES_UPSERT e, se puder, a opção "Webhook Base64" (envia os arquivos junto).' }}</p>
            <div class="copy-field"><code data-copy-source>{{ $channel->webhook_url }}</code><button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button></div>
            @if ($isMeta)
                <div class="copy-field mt"><span class="small muted">Token de verificação:</span><code data-copy-source>{{ $channel->verify_token }}</code><button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button></div>
            @endif
            <p class="small faint" style="margin: 10px 0 0">O endereço precisa ser acessível pela internet. No Laragon (computador local), publique o sistema ou use um túnel como Cloudflare Tunnel ou ngrok.</p>
        </div>
    </div>
@endif

<form method="post" action="{{ $channel->exists ? route('whatsapp.channels.update', $channel) : route('whatsapp.channels.store') }}" class="card" style="max-width: 980px">
    @csrf
    @if ($channel->exists) @method('put') @endif
    <input type="hidden" name="driver" value="{{ $driver }}">
    <div class="card-head"><h3>Conexão</h3></div>
    <div class="card-body form-grid">
        <x-field name="name" label="Nome *" col="6"><input type="text" name="name" id="f-name" value="{{ old('name', $channel->name) }}" required maxlength="100"></x-field>
        <x-field name="display_phone" label="Número do escritório" col="6" hint="Só para exibição, ex.: (11) 99999-0000"><input type="text" name="display_phone" id="f-display_phone" value="{{ old('display_phone', $channel->display_phone) }}" maxlength="30"></x-field>
        @if ($isMeta)
            <x-field name="phone_number_id" label="Phone Number ID *" col="6" hint="Em WhatsApp › Configuração da API, no app da Meta."><input type="text" name="phone_number_id" id="f-phone_number_id" value="{{ old('phone_number_id', $channel->phone_number_id) }}" maxlength="50"></x-field>
            <x-field name="api_key" label="Token de acesso permanente" col="6" :hint="$channel->api_key ? 'Já cadastrado. Deixe em branco para manter.' : 'Token de usuário do sistema com permissão whatsapp_business_messaging.'"><input type="password" name="api_key" id="f-api_key" autocomplete="new-password" placeholder="{{ $channel->api_key ? '••••••••' : '' }}"></x-field>
            <x-field name="app_secret" label="Chave secreta do app" col="6" :hint="$channel->app_secret ? 'Já cadastrada. Deixe em branco para manter.' : 'Recomendado: confere que os avisos vieram da Meta.'"><input type="password" name="app_secret" id="f-app_secret" autocomplete="new-password" placeholder="{{ $channel->app_secret ? '••••••••' : '' }}"></x-field>
            <x-field name="base_url" label="URL da Graph API" col="6" hint="Deixe em branco para usar https://graph.facebook.com"><input type="url" name="base_url" id="f-base_url" value="{{ old('base_url', $channel->base_url) }}"></x-field>
        @else
            <x-field name="base_url" label="URL do servidor Evolution *" col="6" hint="Ex.: https://evolution.seuescritorio.com.br"><input type="url" name="base_url" id="f-base_url" value="{{ old('base_url', $channel->base_url) }}" required></x-field>
            <x-field name="instance" label="Nome da instância *" col="3"><input type="text" name="instance" id="f-instance" value="{{ old('instance', $channel->instance) }}" required maxlength="100"></x-field>
            <x-field name="api_key" label="Chave (apikey)" col="3" :hint="$channel->api_key ? 'Já cadastrada. Em branco mantém.' : 'Chave global ou da instância.'"><input type="password" name="api_key" id="f-api_key" autocomplete="new-password" placeholder="{{ $channel->api_key ? '••••••••' : '' }}"></x-field>
        @endif
        <div class="col-12"><label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $channel->active))> Canal ativo (recebe e envia mensagens)</label></div>
    </div>

    <div class="card-head" style="border-top: 1px solid var(--border-soft)"><h3><x-icon name="sparkles" /> Robô de autoatendimento</h3></div>
    <div class="card-body form-grid">
        <div class="col-12"><label class="check"><input type="checkbox" name="bot_enabled" value="1" @checked(old('bot_enabled', $channel->bot_enabled))> Robô responde automaticamente (desligado, as mensagens só ficam na caixa de entrada para a equipe)</label></div>
        <x-field name="ai_provider_id" label="IA usada nas respostas" col="6" hint="Em branco usa o provedor padrão do Assistente IA. Sem IA, o robô funciona só com o menu.">
            <select name="ai_provider_id" id="f-ai_provider_id">
                <option value="">Provedor padrão do escritório</option>
                @foreach ($providers as $p)<option value="{{ $p->id }}" @selected((int) old('ai_provider_id', $channel->ai_provider_id) === $p->id)>{{ $p->name }} ({{ $p->model }})</option>@endforeach
            </select>
        </x-field>
        <div class="field col-6">
            <label>Quem recebe as tarefas de atendimento</label>
            <div class="wa-team">
                @foreach ($users as $u)
                    <label class="check small"><input type="checkbox" name="team_user_ids[]" value="{{ $u->id }}" @checked(in_array($u->id, array_map('intval', $team), true))> {{ $u->name }}</label>
                @endforeach
            </div>
            <div class="hint">Nenhum marcado: os administradores.</div>
        </div>
        <x-field name="greeting" label="Mensagem de boas-vindas (menu)" col="6" hint="Mantenha as opções 1 a 4, que o robô entende.">
            <textarea name="greeting" id="f-greeting" rows="9" maxlength="2000" placeholder="{{ WhatsappChannel::DEFAULT_GREETING }}">{{ old('greeting', $channel->greeting) }}</textarea>
        </x-field>
        <x-field name="instructions" label="Orientações para a IA" col="6" hint="Ex.: horário de atendimento, endereço, áreas em que o escritório atua, como falar dos honorários.">
            <textarea name="instructions" id="f-instructions" rows="9" maxlength="4000">{{ old('instructions', $channel->instructions) }}</textarea>
        </x-field>
        <div class="field col-12"><label class="check"><input type="checkbox" name="notify_movements" value="1" @checked(old('notify_movements', $channel->notify_movements))> Avisar o cliente pelo WhatsApp quando um andamento for registrado no processo dele</label>
            <div class="hint">A mensagem é escrita pela IA em linguagem simples. {{ $isMeta ? 'Na API oficial, só chega se o cliente falou com o escritório nas últimas 24h.' : '' }}</div>
        </div>
        <div class="col-12 form-actions">
            @if ($channel->exists)
                <button type="submit" form="delete-channel" class="btn btn-ghost text-red">Excluir canal</button>
            @endif
            <span class="spacer"></span>
            <a class="btn btn-ghost" href="{{ route('whatsapp.channels.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@if ($channel->exists)
    <form id="delete-channel" method="post" action="{{ route('whatsapp.channels.destroy', $channel) }}" data-confirm="Excluir este canal e todas as conversas dele?">@csrf @method('delete')</form>
@endif
@endsection
