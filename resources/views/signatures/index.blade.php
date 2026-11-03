@extends('layouts.app', ['title' => 'Assinatura digital'])
@php use App\Models\SignatureRequest; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Assinatura digital</div>
<div class="page-head">
    <div>
        <h1>Assinatura digital</h1>
        <div class="sub">Contratos, procurações e outros documentos enviados para assinatura eletrônica.</div>
    </div>
    <div class="page-actions">
        @can('admin')<a class="btn" href="{{ route('signatures.settings') }}"><x-icon name="settings" class="sm" /> Plataformas</a>@endcan
        <a class="btn btn-primary" href="{{ route('signatures.create') }}"><x-icon name="plus" class="sm" /> Enviar documento</a>
    </div>
</div>

@if ($providers->isEmpty())
    <div class="alert warning small"><x-icon name="alert" /> <div>Nenhuma plataforma (ClickSign ou D4Sign) configurada. Ainda dá para preparar o PDF e assiná-lo com o seu <strong>certificado digital ICP-Brasil</strong>.@can('admin') <a class="link" href="{{ route('signatures.settings') }}">Configurar plataformas</a>@else Peça a um administrador para configurar uma plataforma.@endcan</div></div>
@endif

<div class="tabs">
    <a href="{{ route('signatures.index', request()->except('page', 'situacao')) }}" class="{{ request('situacao') ? '' : 'active' }}">Todos <span class="count">{{ $counts->sum() }}</span></a>
    @foreach (SignatureRequest::STATUSES as $k => $s)
        @if (($counts[$k] ?? 0) > 0)
            <a href="{{ route('signatures.index', array_merge(request()->except('page'), ['situacao' => $k])) }}" class="{{ request('situacao') === $k ? 'active' : '' }}">{{ $s['label'] }} <span class="count">{{ $counts[$k] }}</span></a>
        @endif
    @endforeach
</div>

<form class="toolbar" method="get">
    @if (request('situacao'))<input type="hidden" name="situacao" value="{{ request('situacao') }}">@endif
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Documento, signatário ou e-mail..."></div>
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Documento</th><th>Cliente / processo</th><th>Signatários</th><th>Plataforma</th><th>Situação</th><th class="right">Enviado</th></tr></thead>
            <tbody>
            @forelse ($requests as $sr)
                @php $signed = $sr->signers->where('status', 'assinado')->count(); @endphp
                <tr>
                    <td><a class="title" href="{{ route('signatures.show', $sr) }}">{{ $sr->title }}</a>@if ($sr->user)<div class="small faint">por {{ $sr->user->name }}</div>@endif</td>
                    <td class="small">
                        @if ($sr->contact)<a href="{{ route('contacts.show', $sr->contact) }}">{{ $sr->contact->name }}</a>@else — @endif
                        @if ($sr->legalCase)<div class="faint"><a href="{{ route('cases.show', $sr->legalCase) }}">{{ $sr->legalCase->number ?: $sr->legalCase->title }}</a></div>@endif
                    </td>
                    <td class="small">{{ $sr->signers->pluck('name')->implode(', ') ?: '—' }}@if ($sr->signers->isNotEmpty())<div class="faint">{{ $signed }} de {{ $sr->signers->count() }} assinaram</div>@endif</td>
                    <td class="small">{{ $sr->provider_label }}@if ($sr->icp_brasil)<div><span class="badge purple">ICP-Brasil</span></div>@endif</td>
                    <td><span class="badge {{ $sr->status_badge }}">{{ $sr->status_label }}</span></td>
                    <td class="right small muted">{{ ($sr->sent_at ?? $sr->created_at)->format('d/m/Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty icon="pen">Nenhum documento enviado para assinatura. Gere um contrato ou procuração em <a class="link" href="{{ route('templates.index') }}">Modelos</a> e clique em "Enviar para assinatura".</x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $requests->links() }}
@endsection
