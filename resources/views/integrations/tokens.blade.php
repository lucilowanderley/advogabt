@extends('layouts.app', ['title' => 'API REST · Integrações'])
@php use App\Models\ApiToken; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / API REST</div>
<div class="page-head">
    <div>
        <h1>API REST</h1>
        <div class="sub">Tokens para n8n, Make, Zapier, o bot de WhatsApp ou sistemas próprios acessarem os dados do escritório.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('integrations.docs') }}"><x-icon name="book" class="sm" /> Documentação</a>
    </div>
</div>

@include('integrations._tabs')

@if ($new = session('new_token'))
    <div class="card mb new-secret">
        <div class="card-body">
            <h3 class="row"><x-icon name="key" /> Token "{{ $new['name'] }}" criado</h3>
            <p class="small muted">Copie e guarde em local seguro (por exemplo, nas credenciais do n8n). <strong>Ele não será exibido de novo.</strong></p>
            <div class="copy-field">
                <code data-copy-source>{{ $new['plain'] }}</code>
                <button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button>
            </div>
            <p class="small muted" style="margin: 10px 0 0">Teste: <code>curl -H "Authorization: Bearer {{ $new['plain'] }}" {{ url('/api/v1/status') }}</code></p>
        </div>
    </div>
@endif

<div class="grid grid-main">
    <div class="card">
        <div class="card-head"><h3>Tokens</h3><span class="small muted">Endereço da API: <code>{{ url('/api/v1') }}</code></span></div>
        @if ($tokens->isEmpty())
            <x-empty icon="key">Nenhum token criado ainda.</x-empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Nome</th><th>Token</th><th>Acesso</th><th>Último uso</th><th>Situação</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($tokens as $t)
                        <tr>
                            <td><span class="title">{{ $t->name }}</span><div class="small faint">por {{ $t->user?->name ?? '—' }} em {{ $t->created_at->format('d/m/Y') }}</div></td>
                            <td><code>{{ $t->prefix }}…</code></td>
                            <td><span class="badge {{ $t->canWrite() ? 'orange' : 'blue' }}">{{ $t->access_label }}</span></td>
                            <td class="small">{{ $t->last_used_at ? $t->last_used_at->diffForHumans() : 'Nunca' }}@if ($t->last_ip)<div class="faint">{{ $t->last_ip }}</div>@endif</td>
                            <td>
                                @if ($t->status === 'ativo')
                                    <span class="badge green">Ativo</span>
                                    @if ($t->expires_at)<div class="small faint">até {{ $t->expires_at->format('d/m/Y') }}</div>@endif
                                @elseif ($t->status === 'expirado')
                                    <span class="badge orange">Expirado</span>
                                @else
                                    <span class="badge red">Revogado</span>
                                @endif
                            </td>
                            <td><div class="actions">
                                @if (! $t->revoked_at)
                                    <form method="post" action="{{ route('integrations.tokens.destroy', $t) }}" data-confirm="Revogar este token? Os sistemas que o usam perdem o acesso imediatamente.">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Revogar"><x-icon name="trash" class="sm" /></button></form>
                                @endif
                            </div></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <form method="post" action="{{ route('integrations.tokens.store') }}" class="card">
        @csrf
        <div class="card-head"><h3>Novo token</h3></div>
        <div class="card-body form-grid">
            <x-field name="name" label="Nome *" hint="Para identificar quem usa, ex.: n8n do escritório, Bot WhatsApp.">
                <input type="text" name="name" id="f-name" value="{{ old('name') }}" required maxlength="100">
            </x-field>
            <x-field name="access" label="Acesso *">
                <select name="access" id="f-access">
                    @foreach (ApiToken::ACCESS as $k => $l)<option value="{{ $k }}" @selected(old('access', 'leitura') === $k)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="expires_at" label="Validade" hint="Opcional. Em branco, vale até ser revogado.">
                <input type="date" name="expires_at" id="f-expires_at" value="{{ old('expires_at') }}">
            </x-field>
            <div class="col-12"><button class="btn btn-primary"><x-icon name="plus" class="sm" /> Criar token</button></div>
        </div>
        <div class="card-body small muted" style="border-top: 1px solid var(--border-soft)">
            Use <strong>somente leitura</strong> para relatórios e consultas. <strong>Leitura e escrita</strong> permite cadastrar contatos, processos, andamentos, tarefas, compromissos e lançamentos, e dar baixa em pagamentos. O que for criado pela API fica em nome de quem criou o token.
        </div>
    </form>
</div>
@endsection
