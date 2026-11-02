@extends('layouts.app', ['title' => $conversation ? $conversation->display_name.' · WhatsApp' : 'WhatsApp'])
@php use App\Http\Controllers\WhatsappController; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>WhatsApp</h1>
        <div class="sub">Atendimento aos clientes: o robô responde com o Assistente IA e a equipe assume quando precisar.</div>
    </div>
    <div class="page-actions">
        @can('admin')<a class="btn" href="{{ route('whatsapp.channels.index') }}"><x-icon name="settings" class="sm" /> Canais e robô</a>@endcan
    </div>
</div>

@if (! $channels)
    <div class="card">
        <div class="card-body ai-setup">
            <div class="ai-hero-icon"><x-icon name="whatsapp" class="lg" /></div>
            <h2>Conecte o WhatsApp do escritório</h2>
            <p class="muted">Use a <strong>Evolution API</strong> (gratuita, conecta lendo um QR Code) ou a <strong>API oficial da Meta</strong>. Depois disso o robô passa a atender os clientes: informa o andamento dos processos, registra pedidos de agendamento, recebe documentos e chama a equipe.</p>
            @can('admin')
                <a class="btn btn-primary" href="{{ route('whatsapp.channels.create', ['tipo' => 'evolution']) }}"><x-icon name="plus" class="sm" /> Conectar com a Evolution API</a>
                <a class="btn" href="{{ route('whatsapp.channels.create', ['tipo' => 'meta']) }}">Usar a API oficial da Meta</a>
            @else
                <p class="small faint">Peça a um administrador para conectar o WhatsApp.</p>
            @endcan
        </div>
    </div>
@else
<div class="wa-layout {{ $conversation ? 'has-open' : '' }}">
    <div class="card wa-list">
        <form class="wa-search" method="get" action="{{ route('whatsapp.index') }}">
            <input type="hidden" name="filtro" value="{{ $filter }}">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar nome ou número">
        </form>
        <div class="wa-filters">
            @foreach (WhatsappController::FILTERS as $key => $label)
                <a href="{{ route('whatsapp.index', ['filtro' => $key]) }}" class="{{ $filter === $key ? 'active' : '' }}">{{ $label }}@if ($key === 'equipe' && $waiting) <span class="count">{{ $waiting }}</span>@endif</a>
            @endforeach
        </div>
        <ul class="wa-convs">
            @forelse ($conversations as $c)
                <li class="{{ $conversation?->id === $c->id ? 'active' : '' }}">
                    <a href="{{ route('whatsapp.show', array_merge(['conversation' => $c], request()->only('filtro', 'q'))) }}">
                        <div class="between row">
                            <strong class="ellipsis">{{ $c->display_name }}</strong>
                            <span class="small faint nowrap">{{ $c->last_message_at?->format(now()->isSameDay($c->last_message_at) ? 'H:i' : 'd/m') }}</span>
                        </div>
                        <div class="between row">
                            <span class="small muted ellipsis">{{ \Illuminate\Support\Str::limit($c->lastMessage?->body ?? ($c->lastMessage ? '['.$c->lastMessage->type.']' : ''), 60) }}</span>
                            <span class="row" style="gap: 4px">
                                @if ($c->mode === 'humano')<span class="badge orange" title="Atendimento pela equipe">equipe</span>@endif
                                @if ($c->unread)<span class="wa-unread">{{ $c->unread }}</span>@endif
                            </span>
                        </div>
                    </a>
                </li>
            @empty
                <li><x-empty icon="whatsapp">Nenhuma conversa ainda.</x-empty></li>
            @endforelse
        </ul>
        @if ($conversations->hasPages())<div class="card-body">{{ $conversations->links() }}</div>@endif
    </div>

    <div class="card wa-chat">
        @if ($conversation)
            <div class="card-head">
                <div style="min-width: 0">
                    <a class="btn btn-xs btn-ghost wa-back" href="{{ route('whatsapp.index', request()->only('filtro', 'q')) }}"><x-icon name="chevron-left" class="sm" /></a>
                    <h3 class="ellipsis" style="display: inline">{{ $conversation->display_name }}</h3>
                    <div class="small muted">
                        {{ $conversation->formatted_phone }}
                        @if ($conversation->contact) · <a href="{{ route('contacts.show', $conversation->contact) }}">ver cadastro</a>@else · <span class="text-orange">número não cadastrado</span>@endif
                        @if ($conversation->assignee) · com {{ $conversation->assignee->first_name }}@endif
                    </div>
                </div>
                <div class="row">
                    <form method="post" action="{{ route('whatsapp.mode', $conversation) }}">@csrf @method('patch')
                        @if ($conversation->mode === 'bot')
                            <input type="hidden" name="mode" value="humano"><button class="btn btn-sm" title="O robô para de responder nesta conversa"><x-icon name="user" class="sm" /> Assumir conversa</button>
                        @else
                            <input type="hidden" name="mode" value="bot"><button class="btn btn-sm" title="O robô volta a responder"><x-icon name="sparkles" class="sm" /> Devolver ao robô</button>
                        @endif
                    </form>
                    <a class="btn btn-sm btn-ghost" href="https://wa.me/{{ $conversation->phone }}" target="_blank" rel="noopener" title="Abrir no WhatsApp"><x-icon name="external" class="sm" /></a>
                </div>
            </div>

            @if (! $conversation->contact)
                <form class="wa-link" method="post" action="{{ route('whatsapp.contact', $conversation) }}">@csrf @method('patch')
                    <span class="small muted">Vincular a um contato:</span>
                    <select name="contact_id" required>
                        <option value="">Escolha...</option>
                        @foreach ($contacts as $ct)<option value="{{ $ct->id }}">{{ $ct->name }}</option>@endforeach
                    </select>
                    <label class="check small"><input type="checkbox" name="save_phone" value="1" checked> salvar o número no cadastro</label>
                    <button class="btn btn-xs">Vincular</button>
                    <a class="btn btn-xs btn-ghost" href="{{ route('contacts.create', ['nome' => $conversation->profile_name, 'whatsapp' => $conversation->formatted_phone]) }}">Novo contato</a>
                </form>
            @endif

            <div class="wa-messages" data-wa-messages data-poll="{{ route('whatsapp.poll', $conversation) }}" data-last="{{ $conversation->messages->last()?->id ?? 0 }}">
                @foreach ($conversation->messages as $m)
                    @include('whatsapp._message', ['m' => $m])
                @endforeach
            </div>

            <form class="wa-composer" method="post" action="{{ route('whatsapp.send', $conversation) }}">
                @csrf
                <textarea name="body" rows="2" required maxlength="4000" placeholder="Escreva como {{ auth()->user()->first_name }}... (Enter envia, Shift+Enter quebra linha)" data-wa-input></textarea>
                <button class="btn btn-primary"><x-icon name="arrow-up" class="sm" /> Enviar</button>
            </form>
            <div class="small faint wa-hint">
                @if ($conversation->mode === 'bot')
                    O robô está atendendo. Ao enviar uma mensagem você assume a conversa e ele para de responder.
                @else
                    Atendimento pela equipe. O robô volta sozinho após 24h sem mensagens ou pelo botão "Devolver ao robô".
                @endif
            </div>
        @else
            <x-empty icon="message">Escolha uma conversa à esquerda.</x-empty>
        @endif
    </div>
</div>
@endif
@endsection
