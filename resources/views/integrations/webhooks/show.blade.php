@extends('layouts.app', ['title' => $webhook->name.' · Webhooks'])

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / <a href="{{ route('integrations.webhooks.index') }}">Webhooks</a> / {{ $webhook->name }}</div>
<div class="page-head">
    <div>
        <h1>{{ $webhook->name }} @if ($webhook->active)<span class="badge green">Ativo</span>@else<span class="badge">Pausado</span>@endif</h1>
        <div class="sub"><code>{{ $webhook->url }}</code></div>
    </div>
    <div class="page-actions">
        <form method="post" action="{{ route('integrations.webhooks.test', $webhook) }}">@csrf<button class="btn"><x-icon name="plug" class="sm" /> Enviar teste</button></form>
        <a class="btn" href="{{ route('integrations.webhooks.edit', $webhook) }}"><x-icon name="edit" class="sm" /> Editar</a>
    </div>
</div>

@include('integrations._tabs')

<div class="grid grid-main">
    <div class="card">
        <div class="card-head"><h3>Envios</h3><span class="small muted">{{ $deliveries->total() }} registro(s)</span></div>
        @include('integrations.webhooks._deliveries', ['deliveries' => $deliveries])
        @if ($deliveries->hasPages())<div class="card-body">{{ $deliveries->links() }}</div>@endif
    </div>

    <div>
        <div class="card mb">
            <div class="card-head"><h3>Eventos</h3></div>
            <div class="card-body">
                <div class="row wrap">@foreach ($webhook->event_labels as $label)<span class="tag">{{ $label }}</span>@endforeach</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h3>Segredo de assinatura</h3></div>
            <div class="card-body small">
                <p class="muted">Use para confirmar que o aviso veio mesmo do ADVOGABT: calcule o HMAC SHA-256 do corpo recebido com este segredo e compare com o cabeçalho <code>X-Advogabt-Signature</code>.</p>
                <details>
                    <summary class="btn btn-xs">Mostrar segredo</summary>
                    <div class="copy-field mt">
                        <code data-copy-source>{{ $webhook->secret }}</code>
                        <button type="button" class="btn btn-xs" data-copy><x-icon name="copy" class="sm" /> Copiar</button>
                    </div>
                </details>
                <form method="post" action="{{ route('integrations.webhooks.secret', $webhook) }}" class="mt" data-confirm="Gerar um novo segredo? O sistema de destino precisará ser atualizado.">@csrf<button class="btn btn-xs btn-ghost"><x-icon name="refresh" class="sm" /> Gerar novo segredo</button></form>
            </div>
        </div>
    </div>
</div>
@endsection
