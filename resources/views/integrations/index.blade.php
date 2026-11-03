@extends('layouts.app', ['title' => 'Integrações'])
@php use App\Support\Integrations\Catalog; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Integrações</h1>
        <div class="sub">Conecte o ADVOGABT a automações (n8n, Make, Zapier), ao WhatsApp, aos tribunais e a outros serviços.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('integrations.docs') }}"><x-icon name="book" class="sm" /> Documentação da API</a>
    </div>
</div>

@include('integrations._tabs')

<div class="grid grid-3 mb">
    <div class="card kpi"><div class="kpi-icon"><x-icon name="key" /></div><div><div class="value">{{ $tokens }}</div><div class="label">Token(s) de API ativo(s)</div></div></div>
    <div class="card kpi"><div class="kpi-icon blue"><x-icon name="webhook" /></div><div><div class="value">{{ $webhooks }}</div><div class="label">Webhook(s) ativo(s)</div></div></div>
    <div class="card kpi">
        <div class="kpi-icon {{ ($deliveries->total ?? 0) > ($deliveries->ok ?? 0) ? 'red' : 'green' }}"><x-icon name="check-check" /></div>
        <div><div class="value">{{ (int) ($deliveries->ok ?? 0) }} / {{ (int) ($deliveries->total ?? 0) }}</div><div class="label">Avisos entregues nos últimos 7 dias</div></div>
    </div>
</div>

@foreach (['disponivel' => 'Disponíveis', 'proxima' => 'Próxima etapa', 'breve' => 'Em breve'] as $status => $heading)
    @php $group = array_filter($items, fn ($i) => $i['status'] === $status); @endphp
    @if ($group)
        <h2 class="section-title">{{ $heading }}</h2>
        <div class="integration-grid mb">
            @foreach ($group as $key => $item)
                @php
                    $st = Catalog::STATUS[$item['status']];
                    $link = isset($item['route']) && (auth()->user()->isAdmin() || ! empty($item['everyone'])) ? route($item['route']) : null;
                @endphp
                <div class="card integration-card {{ $item['status'] === 'breve' ? 'is-soon' : '' }}">
                    <div class="integration-head">
                        <div class="kpi-icon {{ $item['status'] === 'disponivel' ? 'green' : ($item['status'] === 'proxima' ? '' : 'purple') }}"><x-icon :name="$item['icon']" /></div>
                        <div>
                            <h3>{{ $item['title'] }}</h3>
                            <div class="small faint">{{ $item['group'] }}</div>
                        </div>
                        <span class="badge {{ $st['badge'] }}">{{ $st['label'] }}</span>
                    </div>
                    <p class="small muted">{{ $item['description'] }}</p>
                    <div class="row wrap integration-tags">
                        @foreach ($item['tags'] as $tag)<span class="tag">{{ $tag }}</span>@endforeach
                    </div>
                    @if ($link)
                        <a class="btn btn-sm" href="{{ $link }}">{{ $key === 'whatsapp' ? 'Abrir' : ($key === 'agenda' && ! auth()->user()->isAdmin() ? 'Sincronizar minha agenda' : 'Configurar') }} <x-icon name="chevron-right" class="sm" /></a>
                    @elseif ($item['status'] === 'disponivel')
                        <span class="small faint">Peça a um administrador para configurar.</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endforeach

<div class="card">
    <div class="card-body small muted">
        <p><strong>Como usar com o n8n, Make ou Zapier.</strong> Crie um token na aba <em>API REST</em> para que a automação consulte e cadastre dados, e um webhook na aba <em>Webhooks</em> apontando para a URL do gatilho ("Webhook trigger") do fluxo. A partir daí o ADVOGABT avisa o fluxo quando surge um processo, um prazo está para vencer ou um pagamento é recebido.</p>
        <p style="margin: 0"><strong>Segurança e LGPD.</strong> Os tokens ficam gravados apenas como hash, e cada aviso de webhook é assinado com um segredo (cabeçalho <code>X-Advogabt-Signature</code>). Envie dados de clientes apenas para serviços contratados pelo escritório.</p>
    </div>
</div>
@endsection
