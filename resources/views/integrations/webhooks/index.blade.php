@extends('layouts.app', ['title' => 'Webhooks · Integrações'])

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Webhooks</div>
<div class="page-head">
    <div>
        <h1>Webhooks</h1>
        <div class="sub">O ADVOGABT avisa uma URL (n8n, Make, Zapier, seu sistema) sempre que algo acontece no escritório.</div>
    </div>
    <div class="page-actions">
        <form method="post" action="{{ route('integrations.routine') }}">@csrf<button class="btn" title="Envia agora os avisos de prazo vencendo e repete os envios que falharam"><x-icon name="refresh" class="sm" /> Verificar prazos agora</button></form>
        <a class="btn btn-primary" href="{{ route('integrations.webhooks.create') }}"><x-icon name="plus" class="sm" /> Novo webhook</a>
    </div>
</div>

@include('integrations._tabs')

@if ($webhooks->isEmpty())
    <div class="card">
        <div class="card-body ai-setup">
            <div class="ai-hero-icon"><x-icon name="webhook" class="lg" /></div>
            <h2>Nenhum webhook cadastrado</h2>
            <p class="muted">No n8n, crie um fluxo começando pelo nó <strong>Webhook</strong> (método POST), copie a "Production URL" e cadastre aqui. No Make use "Custom webhook" e no Zapier "Webhooks by Zapier · Catch Hook".</p>
            <a class="btn btn-primary" href="{{ route('integrations.webhooks.create') }}"><x-icon name="plus" class="sm" /> Cadastrar webhook</a>
        </div>
    </div>
@else
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nome</th><th>Destino</th><th>Eventos</th><th>Último envio</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @foreach ($webhooks as $w)
                <tr>
                    <td><a class="title" href="{{ route('integrations.webhooks.show', $w) }}">{{ $w->name }}</a>@if ($w->source === 'api')<div class="small faint">criado via API</div>@endif</td>
                    <td class="small"><code>{{ $w->host }}</code></td>
                    <td class="small">{{ implode(', ', array_slice($w->event_labels, 0, 3)) }}@if (count($w->event_labels) > 3) <span class="faint">+{{ count($w->event_labels) - 3 }}</span>@endif</td>
                    <td class="small">
                        @if ($w->last_delivery_at)
                            <span class="{{ $w->last_delivery_ok ? 'text-green' : 'text-red' }}">{{ $w->last_delivery_ok ? 'Entregue' : 'Falhou' }}</span> {{ $w->last_delivery_at->diffForHumans() }}
                            @if ($w->failed_count)<div class="faint">{{ $w->failed_count }} falha(s) em 7 dias</div>@endif
                        @else
                            <span class="faint">Nenhum envio ainda</span>
                        @endif
                    </td>
                    <td>@if ($w->active)<span class="badge green">Ativo</span>@else<span class="badge">Pausado</span>@endif</td>
                    <td><div class="actions">
                        <form method="post" action="{{ route('integrations.webhooks.test', $w) }}">@csrf<button class="btn btn-xs" title="Enviar um evento de teste"><x-icon name="plug" class="sm" /> Testar</button></form>
                        <a class="btn btn-xs btn-ghost" href="{{ route('integrations.webhooks.edit', $w) }}" title="Editar"><x-icon name="edit" class="sm" /></a>
                        <form method="post" action="{{ route('integrations.webhooks.destroy', $w) }}" data-confirm="Excluir este webhook e o histórico de envios?">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Excluir"><x-icon name="trash" class="sm" /></button></form>
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <div class="card-head"><h3>Últimos envios</h3></div>
    @include('integrations.webhooks._deliveries', ['deliveries' => $recent, 'showWebhook' => true])
</div>
@endif

<div class="card mt">
    <div class="card-body small muted">
        <p><strong>Como funciona.</strong> Cada aviso é um POST em JSON com os cabeçalhos <code>X-Advogabt-Event</code> e <code>X-Advogabt-Signature</code> (HMAC SHA-256 do corpo com o segredo do webhook). Se o destino não responder com sucesso, o envio é repetido até {{ \App\Models\WebhookDelivery::MAX_ATTEMPTS }} vezes, com espera crescente.</p>
        <p style="margin: 0"><strong>Prazos vencendo.</strong> O aviso de prazo é verificado a cada 15 minutos enquanto o sistema está em uso. Para que rode mesmo com o sistema fechado, agende <code>php artisan schedule:run</code> a cada minuto no Agendador de Tarefas do Windows (ou rode <code>php artisan schedule:work</code>). Veja a <a href="{{ route('integrations.docs') }}#webhooks">documentação</a>.</p>
    </div>
</div>
@endsection
