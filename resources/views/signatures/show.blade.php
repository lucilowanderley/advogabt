@extends('layouts.app', ['title' => $sr->title.' · Assinatura digital'])
@php
    use App\Models\SignatureSigner;
    $canManage = auth()->user()->isAdmin() || $sr->user_id === auth()->id();
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('signatures.index') }}">Assinatura digital</a> / {{ $sr->title }}</div>
<div class="page-head">
    <div>
        <h1>{{ $sr->title }}</h1>
        <div class="row wrap" style="margin-top: 6px; gap: 6px">
            <span class="badge {{ $sr->status_badge }}">{{ $sr->status_label }}</span>
            <span class="badge">{{ $sr->provider_label }}</span>
            @if ($sr->icp_brasil)<span class="badge purple">ICP-Brasil</span>@endif
            @if ($provider?->isSandbox())<span class="badge red" title="Documentos do ambiente de testes não têm validade jurídica">ambiente de testes</span>@endif
        </div>
    </div>
    <div class="page-actions">
        @if (! $sr->isManual() && $sr->external_id && in_array($sr->status, ['enviado', 'assinado'], true))
            <form method="post" action="{{ route('signatures.refresh', $sr) }}">@csrf<button class="btn"><x-icon name="refresh" class="sm" /> Atualizar situação</button></form>
        @endif
        @if ($sr->status === 'erro')
            <form method="post" action="{{ route('signatures.resend', $sr) }}">@csrf<button class="btn btn-primary"><x-icon name="refresh" class="sm" /> Tentar enviar de novo</button></form>
        @endif
        @if ($canManage && in_array($sr->status, ['enviado', 'erro'], true))
            <form method="post" action="{{ route('signatures.cancel', $sr) }}" data-confirm="Cancelar este envio? Os signatários não poderão mais assinar.">@csrf<button class="btn btn-danger"><x-icon name="x" class="sm" /> Cancelar envio</button></form>
        @endif
        @if ($canManage && ($sr->status !== 'enviado' || $sr->isManual()))
            <form method="post" action="{{ route('signatures.destroy', $sr) }}" data-confirm="Excluir este documento do sistema? Os PDFs guardados aqui serão apagados (a cópia na nuvem e na plataforma continua).">@csrf @method('delete')<button class="btn btn-ghost" title="Excluir"><x-icon name="trash" class="sm" /></button></form>
        @endif
    </div>
</div>

@if ($sr->last_error)
    <div class="alert error small"><x-icon name="alert" /> <div>{{ $sr->last_error }}</div></div>
@endif

<div class="grid grid-main">
<div class="stack">
    @if ($sr->isManual() && $sr->status === 'enviado')
        <div class="card">
            <div class="card-head"><h3><x-icon name="key" /> Assinar com certificado digital ICP-Brasil</h3></div>
            <div class="card-body">
                <ol class="small" style="margin-top: 0; padding-left: 18px">
                    <li><a class="link" href="{{ route('signatures.download', [$sr, 'original']) }}">Baixe o PDF</a>.</li>
                    <li>Assine com o certificado A1 ou A3 (token/cartão) em um assinador: <a class="link" target="_blank" rel="noopener" href="https://www.serpro.gov.br/links-fixos-superiores/assinador-digital">Assinador SERPRO</a>, Adobe Acrobat Reader (Ferramentas › Certificados) ou PJeOffice. Se o cliente também assina com certificado, envie o PDF a ele depois da sua assinatura.</li>
                    <li>Anexe abaixo o PDF assinado. O sistema confere as assinaturas digitais e se o certificado é ICP-Brasil.</li>
                </ol>
                <form method="post" action="{{ route('signatures.attach', $sr) }}" enctype="multipart/form-data" class="form-grid">
                    @csrf
                    <x-field name="file" label="PDF assinado" col="8"><input type="file" name="file" id="f-file" accept="application/pdf,.pdf" required></x-field>
                    <div class="field col-4"><label>&nbsp;</label><button class="btn btn-primary"><x-icon name="check" class="sm" /> Anexar</button></div>
                    <div class="col-12"><label class="check small"><input type="checkbox" name="sem_certificado" value="1"> <span>Documento assinado à mão e digitalizado (sem certificado digital)</span></label></div>
                </form>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h3><x-icon name="users" /> Signatários</h3>
            <span class="small muted">{{ $sr->signers->where('status', 'assinado')->count() }} de {{ $sr->signers->count() }} assinaram</span>
        </div>
        <div class="card-body flush">
            @if ($sr->signers->isEmpty())
                <x-empty icon="users">Nenhum signatário informado.</x-empty>
            @else
                <ul class="list">
                    @foreach ($sr->signers as $s)
                        @php $st = SignatureSigner::STATUSES[$s->status] ?? ['label' => $s->status, 'badge' => '']; @endphp
                        <li>
                            <div class="grow">
                                <strong>{{ $s->name }}</strong> <span class="small faint">{{ SignatureSigner::ROLES[$s->role] ?? $s->role }}</span>
                                <div class="small muted">{{ $s->email }}@if ($s->phone) · {{ $s->phone }}@endif · {{ SignatureSigner::AUTHS[$s->auth] ?? $s->auth }}</div>
                            </div>
                            @if ($s->signed_at)<span class="small faint">{{ $s->signed_at->format('d/m/Y H:i') }}</span>@endif
                            <span class="badge {{ $st['badge'] }}">{{ $st['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($sr->message)
        <div class="card">
            <div class="card-head"><h3><x-icon name="message" /> Mensagem e observações</h3></div>
            <div class="card-body small" style="white-space: pre-wrap">{{ $sr->message }}</div>
        </div>
    @endif
</div>

<div class="stack">
    <div class="card">
        <div class="card-head"><h3><x-icon name="download" /> Arquivos</h3></div>
        <div class="card-body stack" style="gap: 8px">
            @if ($sr->signed_path)
                <a class="btn btn-primary" href="{{ route('signatures.download', [$sr, 'assinado']) }}"><x-icon name="download" class="sm" /> PDF assinado</a>
            @endif
            @if ($sr->file_path)
                <a class="btn" href="{{ route('signatures.download', [$sr, 'original']) }}"><x-icon name="file-text" class="sm" /> PDF enviado (original)</a>
            @endif
            @if ($sr->cloud_file_url)
                <a class="btn btn-ghost" target="_blank" rel="noopener" href="{{ $sr->cloud_file_url }}"><x-icon name="cloud" class="sm" /> Abrir na nuvem</a>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>Informações</h3></div>
        <div class="card-body small">
            <dl class="dl" style="grid-template-columns: 110px 1fr">
                @if ($sr->contact)<dt>Cliente</dt><dd><a class="link" href="{{ route('contacts.show', $sr->contact) }}">{{ $sr->contact->name }}</a></dd>@endif
                @if ($sr->legalCase)<dt>Processo</dt><dd><a class="link" href="{{ route('cases.show', $sr->legalCase) }}">{{ $sr->legalCase->number ?: $sr->legalCase->title }}</a></dd>@endif
                @if ($sr->template)<dt>Modelo</dt><dd><a class="link" href="{{ route('templates.show', $sr->template) }}">{{ $sr->template->name }}</a></dd>@endif
                <dt>Enviado por</dt><dd>{{ $sr->user?->name ?? '—' }}</dd>
                <dt>Enviado em</dt><dd>{{ ($sr->sent_at ?? $sr->created_at)->format('d/m/Y H:i') }}</dd>
                @if ($sr->deadline_at)<dt>Prazo</dt><dd>{{ $sr->deadline_at->format('d/m/Y') }}</dd>@endif
                @if ($sr->completed_at)<dt>Concluído em</dt><dd>{{ $sr->completed_at->format('d/m/Y H:i') }}</dd>@endif
                @if ($sr->last_checked_at)<dt>Última consulta</dt><dd>{{ $sr->last_checked_at->diffForHumans() }}</dd>@endif
            </dl>
        </div>
    </div>

    @if (! $sr->isManual() && $sr->status === 'enviado')
        <div class="card">
            <div class="card-body small muted">
                @if ($localHost)
                    Como o sistema está em um endereço local, a {{ $sr->provider_label }} não consegue avisar quando alguém assina. A situação é consultada automaticamente a cada 30 minutos no primeiro dia (depois, a cada poucas horas); use <strong>Atualizar situação</strong> para ver na hora.
                @else
                    A {{ $sr->provider_label }} avisa o sistema quando alguém assina. Se demorar, use <strong>Atualizar situação</strong>.
                @endif
            </div>
        </div>
    @endif
</div>
</div>
@endsection
