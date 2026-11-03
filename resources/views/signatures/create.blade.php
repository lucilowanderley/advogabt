@extends('layouts.app', ['title' => 'Enviar para assinatura'])
@php
    use App\Models\SignatureProvider;
    $oldSigners = old('signers', $signers);
    $provider = old('provider', $defaultProvider);
    $source = old('source', $content !== '' ? 'texto' : 'arquivo');
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('signatures.index') }}">Assinatura digital</a> / Enviar documento</div>
<div class="page-head">
    <div>
        <h1>Enviar para assinatura</h1>
        <div class="sub">Gere o documento a partir de um Modelo (ou envie um PDF), informe quem assina e escolha a plataforma.</div>
    </div>
</div>

<form class="card mb" method="get" action="{{ route('signatures.create') }}">
    <div class="card-head"><h3><x-icon name="file-text" /> Preencher a partir de um Modelo</h3></div>
    <div class="card-body form-grid">
        <x-field name="modelo" label="Modelo" col="4">
            <select name="modelo" id="f-modelo"><option value="">— Nenhum (enviar PDF) —</option>@foreach ($templates as $t)<option value="{{ $t->id }}" @selected($template?->id === $t->id)>{{ $t->name }}</option>@endforeach</select>
        </x-field>
        <x-field name="contact_id" label="Cliente" col="3">
            <x-select name="contact_id" :options="$contacts->pluck('name', 'id')" :value="$contact?->id" placeholder="— Selecione —" />
        </x-field>
        <x-field name="legal_case_id" label="Processo (opcional)" col="3">
            <select name="legal_case_id" id="f-legal_case_id"><option value="">— Nenhum —</option>@foreach ($cases as $c)<option value="{{ $c->id }}" @selected($case?->id === $c->id)>{{ $c->number ? $c->number.' — ' : '' }}{{ $c->title }}</option>@endforeach</select>
        </x-field>
        <div class="field col-2"><label>&nbsp;</label><button class="btn" style="width: 100%"><x-icon name="refresh" class="sm" /> Preencher</button></div>
    </div>
</form>

<form method="post" action="{{ route('signatures.store') }}" enctype="multipart/form-data" class="grid grid-main" data-signature-form>
    @csrf
    <input type="hidden" name="document_template_id" value="{{ old('document_template_id', $template?->id) }}">
    <input type="hidden" name="contact_id" value="{{ old('contact_id', $contact?->id) }}">
    <input type="hidden" name="legal_case_id" value="{{ old('legal_case_id', $case?->id) }}">

    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><x-icon name="file-text" /> Documento</h3></div>
            <div class="card-body form-grid">
                <x-field name="title" label="Título *" col="12" hint="Aparece para os signatários no e-mail e na plataforma.">
                    <input type="text" name="title" id="f-title" value="{{ old('title', $title) }}" required maxlength="200" placeholder="Contrato de honorários - Maria Souza">
                </x-field>
                <div class="field col-12">
                    <label class="check" style="display: inline-flex; margin-right: 18px"><input type="radio" name="source" value="texto" data-source-toggle @checked($source === 'texto')> <span>Texto do Modelo (vira PDF)</span></label>
                    <label class="check" style="display: inline-flex"><input type="radio" name="source" value="arquivo" data-source-toggle @checked($source === 'arquivo')> <span>Enviar um PDF pronto</span></label>
                </div>
                <x-field name="content" label="Texto do documento" col="12" data-source="texto" :hint="'Revise antes de enviar: o que estiver aqui é o que será assinado. Linhas em branco separam os parágrafos.'">
                    <textarea name="content" id="f-content" rows="22" class="doc-editor" spellcheck="true">{{ old('content', $content) }}</textarea>
                </x-field>
                <x-field name="file" label="Arquivo PDF (até 20 MB)" col="12" data-source="arquivo" hint="Ex.: o documento revisado e salvo em PDF pela tela de impressão do Modelo, ou um contrato feito no Word.">
                    <input type="file" name="file" id="f-file" accept="application/pdf,.pdf">
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3><x-icon name="users" /> Quem assina</h3>
                <button type="button" class="btn btn-sm" data-signer-add><x-icon name="plus" class="sm" /> Signatário</button>
            </div>
            <div class="card-body stack" data-signer-list data-next="{{ count($oldSigners) }}">
                @error('signers')<div class="error">{{ $message }}</div>@enderror
                @foreach (array_values($oldSigners) as $i => $s)
                    @include('signatures._signer', ['i' => $i, 's' => $s])
                @endforeach
            </div>
            <template data-signer-template>@include('signatures._signer', ['i' => '__i__', 's' => []])</template>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><x-icon name="pen" /> Como assinar</h3></div>
            <div class="card-body stack" style="gap: 10px">
                @error('provider')<div class="error">{{ $message }}</div>@enderror
                @foreach ($providers as $p)
                    <label class="check option-card">
                        <input type="radio" name="provider" value="{{ $p->driver }}" data-provider-choice @checked($provider === $p->driver)>
                        <span><strong>{{ $p->label }}</strong> <x-ai-pricing :pricing="SignatureProvider::DRIVERS[$p->driver]['pricing']" />@if ($p->isSandbox()) <span class="badge red">testes</span>@endif
                            <span class="small muted" style="display: block">Cada signatário recebe o link e assina pelo celular ou computador.</span></span>
                    </label>
                @endforeach
                <label class="check option-card">
                    <input type="radio" name="provider" value="manual" data-provider-choice @checked($provider === 'manual')>
                    <span><strong>Certificado digital ICP-Brasil</strong> <x-ai-pricing pricing="gratis" />
                        <span class="small muted" style="display: block">Sem plataforma: baixe o PDF, assine com o certificado A1/A3 e anexe a versão assinada.</span></span>
                </label>
                @if ($providers->isEmpty())
                    <div class="small faint">ClickSign e D4Sign aparecem aqui depois de configuradas @can('admin') em <a class="link" href="{{ route('signatures.settings') }}">Integrações › Assinatura digital</a>@else por um administrador @endcan.</div>
                @endif

                <div data-provider-only="clicksign d4sign" class="stack" style="gap: 10px">
                    <label class="check"><input type="checkbox" name="icp_brasil" value="1" @checked(old('icp_brasil'))> <span>Exigir <strong>certificado digital ICP-Brasil</strong> de todos os signatários</span></label>
                    <div class="hint" style="margin-top: -6px">Sem marcar, vale a assinatura eletrônica da plataforma (link + confirmação por e-mail, SMS ou WhatsApp), aceita para contratos e procurações.</div>
                </div>
                <x-field name="deadline_at" label="Prazo para assinar (ClickSign)" col="12" data-provider-only="clicksign">
                    <input type="date" name="deadline_at" id="f-deadline_at" value="{{ old('deadline_at') }}" min="{{ now()->addDay()->toDateString() }}">
                </x-field>
                <x-field name="message" label="Mensagem aos signatários (opcional)" col="12" data-provider-only="clicksign d4sign">
                    <textarea name="message" id="f-message" rows="3" maxlength="1000" placeholder="Olá! Segue o contrato para sua assinatura.">{{ old('message') }}</textarea>
                </x-field>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <button class="btn btn-primary" style="width: 100%"><x-icon name="check" class="sm" /> <span data-submit-label>Enviar para assinatura</span></button>
                <p class="small faint" style="margin-bottom: 0">O PDF fica guardado no sistema. Quando todos assinarem, o PDF assinado aparece na ficha do cliente/processo{{ \App\Models\CloudStorage::connected() ? ' e é copiado para a pasta na nuvem' : '' }}.</p>
            </div>
        </div>
    </div>
</form>
@endsection
