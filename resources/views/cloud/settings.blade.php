@extends('layouts.app', ['title' => 'Arquivos na nuvem'])
@php
    use App\Models\CloudStorage;
    $driver = old('driver', $storage->driver);
    $isGoogle = $driver === 'google';
    $connected = $storage->isConnected();
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Arquivos na nuvem</h1>
        <div class="sub">Uma pasta para cada cliente e cada processo no Google Drive ou no OneDrive do escritório, acessível pela ficha no ADVOGABT.</div>
    </div>
    @if ($storage->exists && $connected)
        <div class="page-actions">
            @if ($storage->root_folder_url)<a class="btn" target="_blank" rel="noopener" href="{{ $storage->root_folder_url }}"><x-icon name="external" class="sm" /> Abrir pasta principal</a>@endif
            <form method="post" action="{{ route('cloud.test') }}">@csrf<button class="btn"><x-icon name="plug" class="sm" /> Testar conexão</button></form>
        </div>
    @endif
</div>

@include('integrations._tabs')

@if ($callbackOk)
    <div class="alert success"><x-icon name="check" /> {{ $storage->driver_label }} conectado{{ $storage->account_email ? ' como '.$storage->account_email : '' }}. A pasta principal "{{ $storage->root_name }}" está pronta.</div>
@endif
@if ($callbackError)
    <div class="alert error"><x-icon name="alert" /> {{ $callbackError }}</div>
@endif

<div class="grid grid-3 mb">
    <div class="card kpi">
        <div class="kpi-icon {{ $connected ? 'green' : 'red' }}"><x-icon name="cloud" /></div>
        <div>
            <div class="value" style="font-size: 18px">{{ $connected ? 'Conectado' : ($storage->exists ? 'Desconectado' : 'Não configurado') }}</div>
            <div class="label">{{ $connected ? $storage->driver_label.' · '.($storage->account_email ?: $storage->account_name) : 'Configure abaixo e clique em Conectar' }}</div>
        </div>
    </div>
    <div class="card kpi"><div class="kpi-icon blue"><x-icon name="briefcase" /></div><div><div class="value">{{ $folders }}</div><div class="label">Pasta(s) de clientes e processos vinculadas</div></div></div>
    <div class="card kpi">
        <div class="kpi-icon {{ $missing ? 'orange' : 'green' }}"><x-icon name="check-check" /></div>
        <div>
            <div class="value">{{ $missing }}</div>
            <div class="label">Cliente(s)/processo(s) ainda sem pasta</div>
            @if ($connected && $missing)
                <form method="post" action="{{ route('cloud.missing') }}" class="mt" style="margin-top: 6px">@csrf<button class="btn btn-xs"><x-icon name="refresh" class="sm" /> Criar pastas que faltam</button></form>
            @endif
        </div>
    </div>
</div>

@if ($storage->exists && $storage->last_error && ! $connected)
    <div class="alert error"><x-icon name="alert" /> Último erro: {{ $storage->last_error }}</div>
@endif

<div class="grid grid-main">
<form method="post" action="{{ route('cloud.save') }}" class="card">
    @csrf
    <div class="card-head">
        <h3><x-icon name="key" /> Conexão</h3>
        <div class="row">
            @foreach (CloudStorage::DRIVERS as $key => $d)
                <a class="btn btn-sm {{ $driver === $key ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('cloud.settings', ['tipo' => $key]) }}">{{ $d['label'] }}</a>
            @endforeach
        </div>
    </div>
    <input type="hidden" name="driver" value="{{ $driver }}">
    <div class="card-body form-grid">
        <div class="col-12 small muted" style="margin-bottom: 4px">{{ CloudStorage::DRIVERS[$driver]['note'] }}</div>
        <x-field name="client_id" :label="$isGoogle ? 'ID do cliente (Client ID) *' : 'ID do aplicativo (cliente) *'" col="6">
            <input type="text" name="client_id" id="f-client_id" value="{{ old('client_id', $storage->client_id) }}" required maxlength="255" autocomplete="off">
        </x-field>
        <x-field name="client_secret" :label="$isGoogle ? 'Chave secreta do cliente *' : 'Valor do segredo do cliente *'" col="6" :hint="$storage->client_secret ? 'Já cadastrada. Deixe em branco para manter.' : 'Fica gravada criptografada.'">
            <input type="password" name="client_secret" id="f-client_secret" autocomplete="new-password" placeholder="{{ $storage->client_secret ? '••••••••' : '' }}">
        </x-field>
        @if ($isGoogle)
            <x-field name="access" label="Acesso ao Drive" col="12">
                <select name="access" id="f-access">
                    @foreach (CloudStorage::ACCESS as $k => $label)<option value="{{ $k }}" @selected(old('access', $storage->access) === $k)>{{ $label }}</option>@endforeach
                </select>
            </x-field>
        @else
            <x-field name="tenant" label="Locatário (tenant)" col="12" hint="common = qualquer conta Microsoft; organizations = só contas do Microsoft 365; ou o ID do locatário do escritório.">
                <input type="text" name="tenant" id="f-tenant" value="{{ old('tenant', $storage->tenant ?: 'common') }}" maxlength="100">
            </x-field>
        @endif
        <x-field name="redirect_uri" label="URL de retorno (redirect URI) *" col="12" :hint="'Cadastre exatamente este endereço no app do '.($isGoogle ? 'Google' : 'Microsoft Entra').'. Sugestão para este endereço: '.$suggestedRedirect">
            <div class="copy-field">
                <input type="url" name="redirect_uri" id="f-redirect_uri" value="{{ old('redirect_uri', $storage->redirect_uri ?: $suggestedRedirect) }}" required maxlength="255" style="flex: 1">
                <button type="button" class="btn btn-sm" data-copy-input><x-icon name="copy" class="sm" /> Copiar</button>
            </div>
        </x-field>
    </div>

    <div class="card-head" style="border-top: 1px solid var(--border-soft)"><h3><x-icon name="briefcase" /> Organização das pastas</h3></div>
    <div class="card-body form-grid">
        <x-field name="root_name" label="Pasta principal *" col="6" hint="Criada na raiz do drive. Se já existir uma com este nome, ela é usada.">
            <input type="text" name="root_name" id="f-root_name" value="{{ old('root_name', $storage->root_name) }}" required maxlength="100">
        </x-field>
        <x-field name="root_link" label="Ou usar uma pasta existente" col="6" :hint="$isGoogle ? 'Cole o link da pasta (inclusive de um drive compartilhado).' : 'Cole o link da pasta (Compartilhar › Copiar link).'">
            <input type="text" name="root_link" id="f-root_link" value="{{ old('root_link') }}" maxlength="500" placeholder="https://..." @disabled(! $connected)>
        </x-field>
        <x-field name="case_layout" label="Onde ficam os processos" col="12">
            <select name="case_layout" id="f-case_layout">
                @foreach (CloudStorage::LAYOUTS as $k => $label)<option value="{{ $k }}" @selected(old('case_layout', $storage->case_layout) === $k)>{{ $label }}</option>@endforeach
            </select>
        </x-field>
        <x-field name="subfolders" label="Subpastas criadas em cada processo" col="6" hint="Uma por linha. Em branco, o processo começa com a pasta vazia.">
            <textarea name="subfolders" id="f-subfolders" rows="5" maxlength="1000">{{ old('subfolders', $storage->subfolders) }}</textarea>
        </x-field>
        <div class="field col-6">
            <label>Automático</label>
            <label class="check"><input type="checkbox" name="auto_create" value="1" @checked(old('auto_create', $storage->auto_create))> Criar a pasta ao cadastrar um cliente ou um processo</label>
            <label class="check mt" style="margin-top: 8px"><input type="checkbox" name="copy_whatsapp" value="1" @checked(old('copy_whatsapp', $storage->copy_whatsapp))> Copiar para a pasta do cliente (subpasta "WhatsApp") os arquivos que ele enviar pelo WhatsApp</label>
            <div class="hint">Processos e clientes antigos: use "Criar pastas que faltam" acima, ou abra a ficha e clique em "Criar pasta".</div>
        </div>
        <div class="col-12 form-actions">
            @if ($connected)
                <button type="submit" form="cloud-disconnect" class="btn btn-ghost text-red">Desconectar</button>
            @endif
            <span class="spacer"></span>
            <button class="btn {{ $storage->exists && ! $connected ? '' : 'btn-primary' }}"><x-icon name="check" class="sm" /> Salvar</button>
            @if ($storage->exists && ! $connected)
                <a class="btn btn-primary" href="{{ route('cloud.connect') }}"><x-icon name="cloud" class="sm" /> Conectar ao {{ $storage->driver_label }}</a>
            @elseif ($connected)
                <a class="btn" href="{{ route('cloud.connect') }}" title="Entrar de novo (ex.: trocar de conta)"><x-icon name="refresh" class="sm" /> Reconectar</a>
            @endif
        </div>
    </div>
</form>
@if ($connected)
    <form id="cloud-disconnect" method="post" action="{{ route('cloud.disconnect') }}" data-confirm="Desconectar a nuvem? As pastas e os arquivos continuam lá, mas a equipe deixa de vê-los no ADVOGABT.">@csrf @method('delete')</form>
@endif

<div class="card">
    <div class="card-head"><h3><x-icon name="book" /> Como criar o app {{ $isGoogle ? 'no Google' : 'na Microsoft' }}</h3></div>
    <div class="card-body small muted">
        @if ($isGoogle)
            <ol style="padding-left: 18px; margin: 0; display: grid; gap: 8px">
                <li>No <a class="link" target="_blank" rel="noopener" href="https://console.cloud.google.com/">Google Cloud Console</a>, crie um projeto (ex.: "ADVOGABT") e ative a <strong>Google Drive API</strong> em APIs e serviços › Biblioteca.</li>
                <li>Em <strong>Tela de consentimento OAuth</strong>: se o escritório usa Google Workspace, escolha o tipo <strong>Interno</strong>. Com Gmail pessoal, escolha Externo e adicione o seu e-mail em "Usuários de teste".</li>
                <li>Em <a class="link" target="_blank" rel="noopener" href="{{ CloudStorage::DRIVERS['google']['console'] }}">Credenciais</a> › Criar credenciais › <strong>ID do cliente OAuth</strong> › Aplicativo da Web.</li>
                <li>Em "URIs de redirecionamento autorizados", cole a URL de retorno desta tela. Copie o ID do cliente e a chave secreta para cá, salve e clique em <strong>Conectar</strong>.</li>
            </ol>
            <p class="faint" style="margin: 10px 0 0">Atenção: com app Externo em modo "Teste", o Google derruba a autorização a cada 7 dias. Use o tipo Interno (Workspace) ou publique o app para não precisar reconectar.</p>
        @else
            <ol style="padding-left: 18px; margin: 0; display: grid; gap: 8px">
                <li>No <a class="link" target="_blank" rel="noopener" href="{{ CloudStorage::DRIVERS['onedrive']['console'] }}">Microsoft Entra › Registros de aplicativo</a>, clique em <strong>Novo registro</strong> (ex.: "ADVOGABT").</li>
                <li>Tipos de conta: "Contas em qualquer diretório organizacional e contas Microsoft pessoais" (ou só o seu locatário, se usar o ID dele acima).</li>
                <li>URI de redirecionamento: plataforma <strong>Web</strong>, com a URL de retorno desta tela.</li>
                <li>Em Certificados e segredos › <strong>Novo segredo do cliente</strong>. Copie o <em>Valor</em> (não o ID do segredo) e o "ID do aplicativo (cliente)" da Visão geral para cá, salve e clique em <strong>Conectar</strong>.</li>
                <li>Permissões usadas (concedidas no login): Files.ReadWrite.All, User.Read e offline_access.</li>
            </ol>
        @endif
        <p style="margin: 10px 0 0"><strong>No Laragon:</strong> Google e Microsoft só aceitam endereço <code>http</code> em "localhost". Por isso a URL sugerida passa por <code>http://localhost/{{ basename(base_path()) }}/public</code>; depois do login você volta para o endereço normal do sistema.</p>
    </div>
</div>
</div>
@endsection
