@extends('layouts.app', ['title' => 'Provedores de IA'])
@php use App\Support\Ai\Drivers; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('assistant.index') }}">Assistente IA</a> / Provedores</div>
<div class="page-head">
    <div>
        <h1>Provedores de IA</h1>
        <div class="sub">Conecte a IA contratada pelo escritório. Você pode cadastrar vários e escolher o padrão.</div>
    </div>
    <div class="dropdown">
        <button class="btn btn-primary" data-dropdown-toggle><x-icon name="plus" class="sm" /> Adicionar provedor <x-icon name="chevron-down" class="sm" /></button>
        <div class="dropdown-menu">
            @foreach (Drivers::ALL as $key => $d)
                <a href="{{ route('ai-providers.create', ['tipo' => $key]) }}" class="between">{{ $d['label'] }} <x-ai-pricing :pricing="$d['pricing']" :note="$d['pricing_note']" /></a>
            @endforeach
        </div>
    </div>
</div>

@if ($providers->isEmpty())
    <div class="card">
        <div class="card-body ai-setup">
            <div class="ai-hero-icon"><x-icon name="sparkles" class="lg" /></div>
            <h2>Nenhum provedor cadastrado</h2>
            <p class="muted">Recomendamos o <strong>Claude, da Anthropic</strong>: excelente em redação jurídica em português, análise de documentos longos e raciocínio. Crie uma chave em console.anthropic.com e cole aqui.</p>
            <a class="btn btn-primary" href="{{ route('ai-providers.create', ['tipo' => 'anthropic']) }}"><x-icon name="plus" class="sm" /> Configurar o Claude</a>
        </div>
    </div>
@else
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nome</th><th>Provedor</th><th>Modelo</th><th>Cobrança</th><th>Chave</th><th>Uso no mês</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @foreach ($providers as $p)
                @php $u = $usage->get($p->name); @endphp
                <tr>
                    <td>
                        <span class="title">{{ $p->name }}</span>
                        @if ($p->is_default)<span class="badge gold">Padrão</span>@endif
                    </td>
                    <td class="small">{{ $p->driver_label }}</td>
                    <td class="nowrap"><code>{{ $p->model }}</code></td>
                    <td><x-ai-pricing :pricing="$p->pricing" :note="Drivers::get($p->driver)['pricing_note']" /></td>
                    <td class="small muted"><code>{{ $p->masked_key }}</code></td>
                    <td class="small">
                        @if ($u)
                            {{ $u->replies }} resposta(s)<br><span class="faint">{{ number_format($u->input + $u->output, 0, ',', '.') }} tokens</span>
                        @else
                            <span class="faint">—</span>
                        @endif
                    </td>
                    <td>
                        @if (! $p->active)
                            <span class="badge">Inativo</span>
                        @elseif ($p->last_test_ok === true)
                            <span class="badge green" title="Testado {{ $p->last_tested_at?->diffForHumans() }}">Conectado</span>
                        @elseif ($p->last_test_ok === false)
                            <span class="badge red" title="Testado {{ $p->last_tested_at?->diffForHumans() }}">Falhou no teste</span>
                        @else
                            <span class="badge orange">Não testado</span>
                        @endif
                    </td>
                    <td><div class="actions">
                        <form method="post" action="{{ route('ai-providers.test', $p) }}">@csrf<button class="btn btn-xs" title="Enviar uma mensagem curta de teste"><x-icon name="plug" class="sm" /> Testar</button></form>
                        @if (! $p->is_default && $p->active)
                            <form method="post" action="{{ route('ai-providers.default', $p) }}">@csrf @method('patch')<button class="btn btn-xs btn-ghost" title="Tornar padrão"><x-icon name="star" class="sm" /></button></form>
                        @endif
                        <a class="btn btn-xs btn-ghost" href="{{ route('ai-providers.edit', $p) }}" title="Editar"><x-icon name="edit" class="sm" /></a>
                        <form method="post" action="{{ route('ai-providers.destroy', $p) }}" data-confirm="Excluir este provedor? As conversas continuam salvas.">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Excluir"><x-icon name="trash" class="sm" /></button></form>
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card mt">
    <div class="card-body small muted">
        <p><strong>Segurança e LGPD.</strong> As chaves ficam gravadas criptografadas no banco (com a APP_KEY do sistema) e nunca são exibidas de novo. Ao usar o assistente com um processo ou contato vinculado, os dados dele são enviados ao provedor escolhido; confira a política de privacidade e de retenção de dados do contrato. Para que nada saia do escritório, use um modelo local pelo Ollama.</p>
        <p><strong>Grátis ou comercial?</strong>
            <x-ai-pricing pricing="gratis" /> roda no próprio escritório sem custo de API (Ollama, LM Studio).
            <x-ai-pricing pricing="freemium" /> tem cota gratuita limitada e plano pago (Gemini, Groq, Mistral).
            <x-ai-pricing pricing="comercial" /> cobra por uso, em tokens (Claude, ChatGPT, Azure, DeepSeek). Passe o mouse sobre o selo para ver os detalhes.</p>
        <p style="margin: 0"><strong>Custos.</strong> O uso é cobrado diretamente pelo provedor na conta do escritório. A coluna "Uso no mês" ajuda a acompanhar o consumo de tokens.</p>
    </div>
</div>
@endsection
