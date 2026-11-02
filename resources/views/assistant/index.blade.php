@extends('layouts.app', ['title' => 'Assistente IA'])
@php
    use App\Support\Ai\Assistant;
    $isNew = ! $conversation->exists;
    $current = $conversation->provider && $conversation->provider->active ? $conversation->provider : $defaultProvider;
    $actions = Assistant::actionsFor($conversation);
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Assistente IA</h1>
        <div class="sub">Peças, resumos, prazos e mensagens com a inteligência artificial contratada pelo escritório.</div>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="{{ route('assistant.index') }}"><x-icon name="plus" class="sm" /> Nova conversa</a>
        @can('admin')<a class="btn" href="{{ route('ai-providers.index') }}"><x-icon name="settings" class="sm" /> Provedores de IA</a>@endcan
    </div>
</div>

@if ($providers->isEmpty())
    <div class="card">
        <div class="card-body ai-setup">
            <div class="ai-hero-icon"><x-icon name="sparkles" class="lg" /></div>
            <h2>Conecte uma inteligência artificial</h2>
            <p class="muted">O ADVOGABT funciona com o Claude (Anthropic), ChatGPT (OpenAI), Gemini, Mistral, modelos locais pelo Ollama e qualquer serviço compatível com a OpenAI. Cada escritório usa a sua própria chave e o modelo que contratou.</p>
            @can('admin')
                <a class="btn btn-primary" href="{{ route('ai-providers.create', ['tipo' => 'anthropic']) }}"><x-icon name="plus" class="sm" /> Configurar o Claude (recomendado)</a>
                <a class="btn" href="{{ route('ai-providers.create', ['tipo' => 'openai']) }}">Outro provedor</a>
            @else
                <p class="muted">Peça a um administrador para cadastrar o provedor em <strong>Assistente IA › Provedores</strong>.</p>
            @endcan
        </div>
    </div>
@else
<div class="ai-layout">
    <aside class="card ai-history">
        <div class="card-head"><h3><x-icon name="history" /> Conversas</h3></div>
        @if ($conversations->isEmpty())
            <x-empty icon="message">Nenhuma conversa ainda.</x-empty>
        @else
            <ul class="list ai-conv-list">
                @foreach ($conversations as $c)
                    <li class="{{ $conversation->exists && $c->is($conversation) ? 'active' : '' }}">
                        <a href="{{ route('assistant.show', $c) }}" class="grow">
                            <span class="title">{{ $c->title }}</span>
                            <span class="small faint">
                                @if ($c->legalCase)<x-icon name="scale" class="sm" /> {{ \Illuminate\Support\Str::limit($c->legalCase->title, 28) }} · @elseif ($c->contact)<x-icon name="user" class="sm" /> {{ \Illuminate\Support\Str::limit($c->contact->name, 28) }} · @endif
                                {{ $c->updated_at->diffForHumans() }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </aside>

    <section class="card ai-chat">
        <div class="card-head">
            <div class="ai-chat-title">
                <h3><x-icon name="sparkles" /> {{ $isNew ? 'Nova conversa' : $conversation->title }}</h3>
                <div class="row wrap small">
                    @if ($conversation->legalCase)
                        <a class="badge gold" href="{{ route('cases.show', $conversation->legalCase) }}"><x-icon name="scale" class="sm" /> {{ $conversation->legalCase->display_name }}</a>
                    @endif
                    @if ($conversation->contact)
                        <a class="badge blue" href="{{ route('contacts.show', $conversation->contact) }}"><x-icon name="user" class="sm" /> {{ $conversation->contact->name }}</a>
                    @endif
                </div>
            </div>
            <div class="row">
                @if ($isNew)
                    <select name="ai_provider_id" form="ai-composer" aria-label="Provedor de IA">
                        @foreach ($providers as $p)<option value="{{ $p->id }}" @selected($current?->is($p))>{{ $p->name }} · {{ $p->model }} · {{ $p->pricing_label }}</option>@endforeach
                    </select>
                @else
                    <form method="post" action="{{ route('assistant.update', $conversation) }}">
                        @csrf @method('patch')
                        <select name="ai_provider_id" data-autosubmit aria-label="Provedor de IA">
                            @foreach ($providers as $p)<option value="{{ $p->id }}" @selected($current?->is($p))>{{ $p->name }} · {{ $p->model }} · {{ $p->pricing_label }}</option>@endforeach
                        </select>
                    </form>
                    <form method="post" action="{{ route('assistant.destroy', $conversation) }}" data-confirm="Excluir esta conversa?">@csrf @method('delete')<button class="btn btn-ghost btn-sm" title="Excluir conversa"><x-icon name="trash" class="sm" /></button></form>
                @endif
            </div>
        </div>

        <div class="ai-messages" data-chat-log>
            @forelse ($messages as $message)
                @include('assistant._message')
            @empty
                <div class="ai-welcome" data-chat-welcome>
                    <div class="ai-hero-icon"><x-icon name="sparkles" class="lg" /></div>
                    <h2>Como posso ajudar, {{ auth()->user()->first_name }}?</h2>
                    <p class="muted small">
                        @if ($conversation->legalCase || $conversation->contact)
                            Os dados {{ $conversation->legalCase ? 'do processo (andamentos, prazos e compromissos)' : 'do contato' }} já estão no contexto da conversa.
                        @else
                            Dica: abra um processo ou contato e clique em <strong>Perguntar à IA</strong> para conversar com os dados dele, ou vincule abaixo.
                        @endif
                    </p>
                </div>
            @endforelse
            <div id="fim"></div>
        </div>

        <div class="ai-actions">
            @foreach ($actions as $key => $a)
                <button type="button" class="chip-btn" data-prompt="{{ $a['prompt'] }}"><x-icon :name="$a['icon']" class="sm" /> {{ $a['label'] }}</button>
            @endforeach
        </div>

        <form id="ai-composer" class="ai-composer" method="post" data-chat-form
              action="{{ $isNew ? route('assistant.store') : route('assistant.send', $conversation) }}">
            @csrf
            @if ($isNew)
                <div class="ai-links">
                    <select name="legal_case_id" aria-label="Vincular processo">
                        <option value="">Sem processo vinculado</option>
                        @foreach (\App\Models\LegalCase::orderBy('title')->get(['id', 'title', 'number']) as $lc)
                            <option value="{{ $lc->id }}" @selected($conversation->legal_case_id === $lc->id)>{{ \Illuminate\Support\Str::limit($lc->display_name, 70) }}</option>
                        @endforeach
                    </select>
                    <select name="contact_id" aria-label="Vincular contato">
                        <option value="">Sem contato vinculado</option>
                        @foreach (\App\Models\Contact::orderBy('name')->get(['id', 'name']) as $ct)
                            <option value="{{ $ct->id }}" @selected($conversation->contact_id === $ct->id)>{{ $ct->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="ai-input">
                <textarea name="message" rows="3" required maxlength="30000" placeholder="Escreva sua pergunta ou pedido... (Ctrl+Enter envia)" data-chat-input>{{ old('message') }}</textarea>
                <button class="btn btn-primary" data-chat-send><x-icon name="arrow-up" class="sm" /> Enviar</button>
            </div>
            @error('message')<div class="error small">{{ $message }}</div>@enderror
            <div class="faint small ai-disclaimer">
                <x-icon name="alert" class="sm" /> <span>@if ($current)<x-ai-pricing :pricing="$current->pricing" :note="\App\Support\Ai\Drivers::get($current->driver)['pricing_note']" /> @endif As respostas são geradas por IA ({{ $current?->name }}) e podem conter erros: revise antes de usar.
                Os dados do processo/contato vinculado são enviados ao provedor escolhido.</span>
            </div>
        </form>
    </section>
</div>
@endif
@endsection
