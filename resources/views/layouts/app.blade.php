<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    @include('layouts.head', ['title' => $title ?? null])
</head>
<body>
@php
    $waWaiting = \App\Models\WhatsappConversation::where('unread', '>', 0)->count();
    $nav = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'home', 'label' => 'Meu painel'],
        ['route' => 'events.index', 'match' => 'events.*', 'icon' => 'calendar', 'label' => 'Agenda'],
        ['route' => 'tasks.index', 'match' => 'tasks.*', 'icon' => 'check-square', 'label' => 'Tarefas'],
        ['route' => 'contacts.index', 'match' => 'contacts.*', 'icon' => 'users', 'label' => 'Contatos e CRM'],
        ['route' => 'cases.index', 'match' => 'cases.*', 'icon' => 'scale', 'label' => 'Processos'],
        ['route' => 'partners.index', 'match' => 'partners.*', 'icon' => 'handshake', 'label' => 'Parceiros'],
        ['route' => 'finance.index', 'match' => 'finance.*', 'icon' => 'wallet', 'label' => 'Financeiro'],
        ['route' => 'templates.index', 'match' => 'templates.*', 'icon' => 'file-text', 'label' => 'Modelos'],
        ['route' => 'reports.index', 'match' => 'reports.*', 'icon' => 'chart', 'label' => 'Relatórios / BI'],
        ['route' => 'assistant.index', 'match' => ['assistant.*', 'ai-providers.*'], 'icon' => 'sparkles', 'label' => 'Assistente IA'],
        ['route' => 'whatsapp.index', 'match' => 'whatsapp.*', 'icon' => 'whatsapp', 'label' => 'WhatsApp', 'badge' => $waWaiting],
        ['route' => 'integrations.index', 'match' => 'integrations.*', 'icon' => 'plug', 'label' => 'Integrações'],
    ];
    $me = auth()->user();
    $myOverdue = \App\Models\Task::forUser($me->id)->open()->where('due_at', '<', today())->count();
@endphp
<div class="app">
    <aside class="sidebar" id="sidebar">
        <a href="{{ route('dashboard') }}" class="brand">
            <x-logo />
            <div>
                <div class="brand-name">ADVOGA<span>BT</span></div>
                <div class="brand-tag">Gestão jurídica</div>
            </div>
        </a>
        <nav class="nav">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" class="{{ request()->routeIs($item['match']) ? 'active' : '' }}">
                    <x-icon :name="$item['icon']" /> {{ $item['label'] }}
                    @if (! empty($item['badge']))<span class="soon nav-badge" title="Conversas com mensagens não lidas">{{ $item['badge'] }}</span>@endif
                </a>
            @endforeach

            <div class="nav-label">Sistema</div>
            <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}"><x-icon name="user" /> Meu perfil</a>
            @can('admin')
                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}"><x-icon name="settings" /> Usuários</a>
            @endcan
        </nav>
        <div class="sidebar-foot">ADVOGABT · v1.0</div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-toggle" data-nav-toggle aria-label="Abrir menu"><x-icon name="menu" /></button>
            <form class="search-box" action="{{ route('search') }}" method="get">
                <x-icon name="search" class="sm" />
                <input type="search" name="q" value="{{ request()->routeIs('search') ? request('q') : '' }}" placeholder="Buscar clientes, processos, tarefas...">
            </form>
            <div class="spacer"></div>
            <button class="icon-btn" data-theme-toggle title="Alternar tema claro/escuro"><x-icon name="sun" /></button>
            <a class="icon-btn" href="{{ route('tasks.index', ['view' => 'lista']) }}" title="{{ $myOverdue }} tarefa(s) atrasada(s)">
                <x-icon name="bell" />
                @if ($myOverdue)<span class="dot">{{ $myOverdue }}</span>@endif
            </a>
            <div class="dropdown">
                <button class="btn btn-primary btn-pill" data-dropdown-toggle><x-icon name="plus" class="sm" /> <span class="hide-sm">Adicionar</span> <x-icon name="chevron-down" class="sm" /></button>
                <div class="dropdown-menu">
                    <a href="{{ route('tasks.create') }}"><x-icon name="check-square" /> Tarefa</a>
                    <a href="{{ route('events.create') }}"><x-icon name="calendar" /> Compromisso</a>
                    <a href="{{ route('contacts.create') }}"><x-icon name="user" /> Contato</a>
                    <a href="{{ route('cases.create') }}"><x-icon name="scale" /> Processo</a>
                    <a href="{{ route('finance.create', ['tipo' => 'receita']) }}"><x-icon name="arrow-up" /> Receita</a>
                    <a href="{{ route('finance.create', ['tipo' => 'despesa']) }}"><x-icon name="arrow-down" /> Despesa</a>
                </div>
            </div>
            <div class="dropdown">
                <button class="user-chip" data-dropdown-toggle>
                    <x-avatar :user="$me" />
                    <span class="hide-sm">Olá, {{ $me->first_name }}</span>
                    <x-icon name="chevron-down" class="sm" />
                </button>
                <div class="dropdown-menu">
                    <div class="small muted" style="padding: 6px 10px">{{ $me->email }}<br>{{ $me->role_label }}</div>
                    <div class="sep"></div>
                    <a href="{{ route('profile.edit') }}"><x-icon name="user" /> Meu perfil</a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"><x-icon name="logout" /> Sair</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="content">
            @if (session('success'))
                <div class="alert success" data-autohide><x-icon name="check" /> {{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert error"><x-icon name="alert" /> {{ session('error') }}</div>
            @endif
            @if ($errors->any() && ! isset($hideErrorSummary))
                <div class="alert error"><x-icon name="alert" /> Verifique os campos destacados no formulário.</div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
<script src="{{ asset('js/app.js') }}?v=6"></script>
</body>
</html>
