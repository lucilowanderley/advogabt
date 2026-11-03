@extends('layouts.app', ['title' => $p->title.' · Publicações'])
@php use App\Models\CourtPublication; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('publications.index') }}">Publicações</a> / {{ $p->masked_number ?: $p->title }}</div>
<div class="page-head">
    <div>
        <h1>{{ $p->title }}</h1>
        <div class="sub">{{ $p->masked_number ?: 'Sem número de processo' }}@if ($p->court) · {{ $p->court }}@endif @if ($p->organ) · {{ $p->organ }}@endif</div>
        <div class="row wrap" style="margin-top: 8px">
            <span class="badge {{ CourtPublication::STATUS[$p->status]['badge'] }}">{{ CourtPublication::STATUS[$p->status]['label'] }}</span>
            @if ($p->class_name)<span class="badge">{{ $p->class_name }}</span>@endif
            @if ($p->handler)<span class="small faint">{{ $p->status === 'descartada' ? 'Descartada' : 'Tratada' }} por {{ $p->handler->name }} {{ $p->handled_at?->diffForHumans() }}</span>@endif
        </div>
    </div>
    <div class="page-actions">
        @if ($p->link)<a class="btn" target="_blank" rel="noopener" href="{{ $p->link }}"><x-icon name="external" class="sm" /> Documento no tribunal</a>@endif
        @if ($p->status === 'nova')
            <form method="post" action="{{ route('publications.status', $p) }}">@csrf<input type="hidden" name="status" value="tratada"><input type="hidden" name="voltar" value="lista"><button class="btn"><x-icon name="check" class="sm" /> Marcar como tratada</button></form>
            <form method="post" action="{{ route('publications.status', $p) }}" data-confirm="Descartar esta publicação (não é do escritório ou não exige providência)?">@csrf<input type="hidden" name="status" value="descartada"><input type="hidden" name="voltar" value="lista"><button class="btn btn-ghost"><x-icon name="x" class="sm" /> Descartar</button></form>
        @else
            <form method="post" action="{{ route('publications.status', $p) }}">@csrf<input type="hidden" name="status" value="nova"><button class="btn btn-ghost"><x-icon name="refresh" class="sm" /> Reabrir</button></form>
        @endif
    </div>
</div>

<div class="grid grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><x-icon name="file-text" /> Teor da publicação</h3></div>
            <div class="card-body"><div class="pre">{{ $plain ?: 'O DJEN não trouxe o texto. Abra o documento no tribunal.' }}</div></div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><x-icon name="calendar" /> Datas</h3></div>
            <div class="card-body">
                <dl class="dl" style="grid-template-columns: 130px 1fr">
                    <dt>Disponibilização</dt><dd>{{ $p->available_at->format('d/m/Y') }}</dd>
                    <dt>Publicação</dt><dd>{{ $p->published_at?->format('d/m/Y') ?? '—' }} <div class="small faint">1º dia útil seguinte</div></dd>
                    <dt>Prazo citado</dt><dd>{{ $p->deadline_days ? $p->deadline_days.' dia(s)' : 'não identificado no texto' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h3><x-icon name="scale" /> Processo</h3></div>
            <div class="card-body">
                @if ($p->legalCase)
                    <a class="title link" href="{{ route('cases.show', $p->legalCase) }}">{{ $p->legalCase->title }}</a>
                    <div class="small muted">{{ $p->legalCase->number }}@if ($p->legalCase->responsible) · {{ $p->legalCase->responsible->name }}@endif</div>
                @else
                    <div class="small muted" style="margin-bottom: 10px">O número {{ $p->masked_number ?: '' }} não está cadastrado. Vincule a um processo existente ou <a class="link" href="{{ route('cases.create') }}">cadastre o processo</a> com esse número (a publicação é vinculada sozinha na próxima busca).</div>
                    <form method="post" action="{{ route('publications.link', $p) }}" class="row">
                        @csrf
                        <select name="legal_case_id" required style="flex: 1"><option value="">Escolha o processo…</option>@foreach ($cases as $c)<option value="{{ $c->id }}">{{ $c->number ? $c->number.' — ' : '' }}{{ $c->title }}</option>@endforeach</select>
                        <button class="btn btn-sm">Vincular</button>
                    </form>
                @endif
                @if ($p->parties)
                    <hr><div class="small faint">Destinatários</div>
                    @foreach ($p->parties as $party)<div class="small">{{ $party['nome'] ?? '—' }} @if (! empty($party['polo']))<span class="faint">({{ $party['polo'] === 'A' ? 'polo ativo' : ($party['polo'] === 'P' ? 'polo passivo' : $party['polo']) }})</span>@endif</div>@endforeach
                @endif
                @if ($p->lawyers)
                    <div class="small faint" style="margin-top: 8px">Advogados intimados</div>
                    @foreach ($p->lawyers as $adv)<div class="small">{{ $adv['nome'] ?? '—' }} @if (! empty($adv['oab']))<span class="faint">OAB {{ $adv['oab'] }}</span>@endif</div>@endforeach
                @endif
            </div>
        </div>

        <div class="card" id="prazo">
            <div class="card-head"><h3><x-icon name="check-square" /> Prazo</h3></div>
            @if ($p->task)
                <div class="card-body">
                    <a class="title link" href="{{ route('tasks.show', $p->task) }}">{{ $p->task->title }}</a>
                    <div class="small muted">Vence em {{ $p->task->due_at?->format('d/m/Y') }} · {{ $p->task->users->pluck('name')->implode(', ') ?: 'sem responsável' }}</div>
                </div>
            @else
                <form method="post" action="{{ route('publications.deadline', $p) }}" class="card-body form-grid">
                    @csrf
                    <x-field name="title" label="Título" col="12"><input type="text" name="title" id="f-title" maxlength="250" value="{{ old('title', 'Prazo: '.($p->kind ?: 'Publicação').' — '.($p->legalCase?->title ?? $p->masked_number ?? 'DJEN')) }}"></x-field>
                    <x-field name="days" label="Dias úteis" col="4"><input type="number" name="days" id="f-days" min="1" max="365" value="{{ old('days', $days) }}" required data-suggest="{{ route('publications.suggest', $p) }}"></x-field>
                    <x-field name="due_date" label="Vencimento" col="8"><input type="date" name="due_date" id="f-due_date" value="{{ old('due_date', $suggested->format('Y-m-d')) }}" required></x-field>
                    <div class="col-12 small faint" style="margin-top: -6px">Sugestão: {{ $suggested->format('d/m/Y') }}. Confira feriados locais, prazo em dobro e suspensões.</div>
                    <x-field name="users" label="Responsáveis" col="12">
                        @php $default = array_filter([$p->legalCase?->responsible_id ?? $p->lawyer?->user_id]); @endphp
                        <select name="users[]" id="f-users" multiple size="4">@foreach ($users as $u)<option value="{{ $u->id }}" @selected(in_array($u->id, old('users', $default)))>{{ $u->name }}</option>@endforeach</select>
                    </x-field>
                    <div class="col-12 form-actions" style="padding-top: 0"><span class="spacer"></span><button class="btn btn-primary"><x-icon name="plus" class="sm" /> Criar prazo</button></div>
                </form>
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    var days = document.querySelector('[data-suggest]');
    var due = document.getElementById('f-due_date');
    if (!days || !due) return;
    days.addEventListener('change', function () {
        fetch(days.dataset.suggest + '?dias=' + encodeURIComponent(days.value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.due) due.value = j.due; });
    });
})();
</script>
@endsection
