@extends('layouts.app', ['title' => 'Canais de WhatsApp'])
@php use App\Models\WhatsappChannel; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('whatsapp.index') }}">WhatsApp</a> / Canais e robô</div>
<div class="page-head">
    <div>
        <h1>Canais de WhatsApp</h1>
        <div class="sub">Número do escritório, robô de autoatendimento e avisos automáticos aos clientes.</div>
    </div>
    <div class="dropdown">
        <button class="btn btn-primary" data-dropdown-toggle><x-icon name="plus" class="sm" /> Conectar número <x-icon name="chevron-down" class="sm" /></button>
        <div class="dropdown-menu">
            @foreach (WhatsappChannel::DRIVERS as $key => $d)
                <a href="{{ route('whatsapp.channels.create', ['tipo' => $key]) }}" class="between">{{ $d['label'] }} <x-ai-pricing :pricing="$d['pricing']" :note="$d['note']" /></a>
            @endforeach
        </div>
    </div>
</div>

<div class="card">
    @if ($channels->isEmpty())
        <x-empty icon="whatsapp">Nenhum número conectado.</x-empty>
    @else
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nome</th><th>Conexão</th><th>Robô</th><th>Conversas</th><th>Última mensagem</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @foreach ($channels as $ch)
                <tr>
                    <td><a class="title" href="{{ route('whatsapp.channels.edit', $ch) }}">{{ $ch->name }}</a>@if ($ch->display_phone)<div class="small faint">{{ $ch->display_phone }}</div>@endif</td>
                    <td class="small">{{ $ch->driver_label }}</td>
                    <td class="small">{{ $ch->bot_enabled ? 'Ligado' : 'Desligado' }}@if ($ch->notify_movements)<div class="faint">avisa andamentos</div>@endif</td>
                    <td>{{ $ch->conversations_count }}</td>
                    <td class="small">{{ $ch->last_inbound_at?->diffForHumans() ?? 'Nenhuma ainda' }}</td>
                    <td>
                        @if (! $ch->active)<span class="badge">Inativo</span>
                        @elseif ($ch->last_test_ok === true)<span class="badge green">Conectado</span>
                        @elseif ($ch->last_test_ok === false)<span class="badge red">Falhou no teste</span>
                        @else<span class="badge orange">Não testado</span>@endif
                    </td>
                    <td><div class="actions">
                        <form method="post" action="{{ route('whatsapp.channels.test', $ch) }}">@csrf<button class="btn btn-xs"><x-icon name="plug" class="sm" /> Testar</button></form>
                        <a class="btn btn-xs btn-ghost" href="{{ route('whatsapp.channels.edit', $ch) }}" title="Editar"><x-icon name="edit" class="sm" /></a>
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

<div class="card mt">
    <div class="card-body small muted">
        <p><strong>Evolution API ou API oficial?</strong> A <strong>Evolution API</strong> é gratuita e conecta o número do escritório lendo um QR Code, como o WhatsApp Web; precisa ficar instalada num servidor (ou contratada de um provedor) e não é um produto da Meta, então use com moderação para evitar bloqueios. A <strong>API oficial da Meta</strong> exige conta Business verificada e cobra por conversa, mas é a opção mais estável. Fora da janela de 24h após a última mensagem do cliente, ela só aceita modelos aprovados.</p>
        <p style="margin: 0"><strong>LGPD.</strong> O robô só fala de processos com quem escreve de um número cadastrado no contato do cliente. Números desconhecidos são encaminhados à equipe para identificação. As respostas são geradas pelo provedor de IA escolhido no canal, que recebe os dados do processo daquele cliente.</p>
    </div>
</div>
@endsection
