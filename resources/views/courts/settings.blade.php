@extends('layouts.app', ['title' => 'Tribunais · Integrações'])
@php use App\Models\CourtLawyer; use App\Support\Courts\Courts; use App\Support\Courts\DatajudClient; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Tribunais</div>
<div class="page-head">
    <div>
        <h1>Tribunais</h1>
        <div class="sub">Andamentos dos processos pelo Datajud (CNJ) e intimações e publicações das OABs do escritório pelo DJEN, alimentando os prazos.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('publications.index') }}"><x-icon name="gavel" class="sm" /> Publicações @if ($fresh)<span class="badge gold">{{ $fresh }}</span>@endif</a>
        @if ($settings->exists)
            <form method="post" action="{{ route('courts.run') }}">@csrf<button class="btn btn-primary"><x-icon name="refresh" class="sm" /> Buscar agora</button></form>
        @endif
    </div>
</div>

@include('integrations._tabs')

@if (! $settings->exists)
    <div class="alert warning small"><x-icon name="alert" /> <div>A integração ainda está desligada. Confira as opções abaixo, cadastre as OABs do escritório e clique em <strong>Salvar</strong> para começar. As duas fontes são <strong>gratuitas</strong> e públicas do CNJ: não precisa de contrato nem de certificado digital.</div></div>
@endif

<div class="grid grid-3 mb">
    <div class="card kpi">
        <div class="kpi-icon {{ $settings->datajudOn() ? 'green' : 'red' }}"><x-icon name="scale" /></div>
        <div><div class="value">{{ $monitored }}</div><div class="label">Processo(s) acompanhado(s) no Datajud @if ($checkedToday)· {{ $checkedToday }} consultado(s) hoje @endif</div></div>
    </div>
    <div class="card kpi">
        <div class="kpi-icon {{ $settings->djenOn() && $lawyers->where('active', true)->isNotEmpty() ? 'green' : 'red' }}"><x-icon name="user" /></div>
        <div><div class="value">{{ $lawyers->where('active', true)->count() }}</div><div class="label">OAB(s) acompanhada(s) no DJEN</div></div>
    </div>
    <div class="card kpi"><div class="kpi-icon"><x-icon name="bell" /></div><div><div class="value">{{ $fresh }}</div><div class="label">Publicação(ões) nova(s) para tratar</div></div></div>
</div>

<div class="grid grid-main">
<div class="stack">
    <form method="post" action="{{ route('courts.save') }}" class="card" autocomplete="off">
        @csrf
        <div class="card-head"><h3><x-icon name="scale" /> Datajud (CNJ) · andamentos</h3> <x-ai-pricing pricing="gratis" /></div>
        <div class="card-body form-grid">
            <div class="col-12 small muted">A API Pública do Datajud reúne os processos de todos os tribunais do país (menos o STF). O sistema consulta cada processo ativo com número CNJ e registra os movimentos novos como andamentos, com a marca <span class="badge blue">Datajud</span>. Os dados do CNJ costumam chegar com alguns dias de atraso em relação ao sistema do tribunal.</div>
            <div class="field col-12">
                <label class="check"><input type="checkbox" name="datajud_enabled" value="1" @checked(old('datajud_enabled', $settings->datajud_enabled))> <span>Consultar os andamentos dos processos ativos</span></label>
            </div>
            <x-field name="datajud_hours" label="Consultar cada processo a cada" col="6" hint="Em horas. O padrão (24) é suficiente: a base do CNJ é atualizada uma vez por dia.">
                <input type="number" name="datajud_hours" id="f-datajud_hours" min="1" max="720" value="{{ old('datajud_hours', $settings->datajud_hours) }}" required>
            </x-field>
            <div class="field col-6">
                <label>&nbsp;</label>
                <label class="check"><input type="checkbox" name="datajud_notify" value="1" @checked(old('datajud_notify', $settings->datajud_notify))> <span>Avisar dos andamentos novos</span></label>
                <div class="hint">Dispara o webhook <code>andamento.criado</code> e o aviso ao cliente pelo WhatsApp (se ligado no canal). A primeira importação de cada processo nunca avisa.</div>
            </div>
            <x-field name="datajud_api_key" label="Chave da API (opcional)" col="12">
                <input type="password" name="datajud_api_key" id="f-datajud_api_key" maxlength="300" autocomplete="new-password" placeholder="{{ $settings->datajud_api_key ? '•••••••• (chave própria cadastrada)' : 'Em branco: usa a chave pública do CNJ' }}">
                <div class="hint">O CNJ divulga uma chave pública, que o sistema já usa. Se um dia o Datajud recusar a chave (HTTP 401/403), copie a nova em <a class="link" target="_blank" rel="noopener" href="{{ DatajudClient::KEY_HELP }}">datajud-wiki.cnj.jus.br <x-icon name="external" class="sm" /></a> e cole aqui.
                    @if ($settings->datajud_api_key)<label class="check" style="margin-top: 6px"><input type="checkbox" name="reset_key" value="1"> <span>Voltar a usar a chave pública</span></label>@endif
                </div>
            </x-field>
            @if ($withErrors || $invalidNumbers)
                <div class="col-12 small muted">
                    @if ($invalidNumbers)<div><x-icon name="alert" class="sm" /> {{ $invalidNumbers }} processo(s) com número fora do padrão CNJ (não dá para consultar). <a class="link" href="{{ route('cases.index') }}">Ver processos</a></div>@endif
                    @if ($withErrors)<div><x-icon name="alert" class="sm" /> {{ $withErrors }} processo(s) com aviso na última consulta (não encontrado, sigiloso ou erro). O aviso aparece na ficha do processo.</div>@endif
                </div>
            @endif
        </div>

        <div class="card-head" style="border-top: 1px solid var(--border)"><h3><x-icon name="gavel" /> DJEN · intimações e publicações</h3> <x-ai-pricing pricing="gratis" /></div>
        <div class="card-body form-grid">
            <div class="col-12 small muted">O Diário de Justiça Eletrônico Nacional (Comunica PJe) publica as intimações de todos os tribunais. O sistema busca pelas OABs cadastradas ao lado, a cada {{ Courts::DJEN_EVERY_HOURS }} horas, e coloca o que encontrar em <a class="link" href="{{ route('publications.index') }}">Publicações</a>, já vinculado ao processo quando o número estiver cadastrado.</div>
            <div class="field col-12">
                <label class="check"><input type="checkbox" name="djen_enabled" value="1" @checked(old('djen_enabled', $settings->djen_enabled))> <span>Capturar as publicações das OABs do escritório</span></label>
            </div>
            <div class="field col-12">
                <label class="check"><input type="checkbox" name="auto_deadline" value="1" @checked(old('auto_deadline', $settings->auto_deadline))> <span>Criar o prazo automaticamente quando a publicação for de um processo cadastrado</span></label>
                <div class="hint">O prazo vai para o responsável pelo processo, com prioridade alta e o vencimento sugerido. Sem esta opção, a equipe cria o prazo pela tela da publicação.</div>
            </div>
            <x-field name="deadline_days" label="Prazo padrão (dias úteis)" col="4" hint="Usado quando o texto não cita o prazo. Ex.: 15 (CPC, art. 1.003, § 5º), 5 para embargos de declaração.">
                <input type="number" name="deadline_days" id="f-deadline_days" min="1" max="365" value="{{ old('deadline_days', $settings->deadline_days) }}" required>
            </x-field>
            <x-field name="holidays" label="Feriados locais e suspensões" col="8" hint="Um por linha: dd/mm para todo ano (ex.: 25/01 aniversário da cidade) ou dd/mm/aaaa (ex.: 03/03/2025 Carnaval, suspensões do tribunal). Já são contados: fins de semana, feriados nacionais, Sexta-feira Santa e o recesso de 20/12 a 20/01.">
                <textarea name="holidays" id="f-holidays" rows="4" placeholder="25/01&#10;03/03/2025&#10;04/03/2025">{{ old('holidays', $settings->holidays) }}</textarea>
            </x-field>
            <div class="col-12 form-actions">
                <span class="small faint">@if ($settings->last_run_at)Última busca {{ $settings->last_run_at->diffForHumans() }}.@endif</span>
                <span class="spacer"></span>
                <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
            </div>
        </div>
    </form>

    <form method="post" action="{{ route('courts.datajud.test') }}" class="card">
        @csrf
        <div class="card-head"><h3><x-icon name="plug" /> Testar o Datajud</h3></div>
        <div class="card-body form-grid">
            <x-field name="number" label="Número de um processo (padrão CNJ)" col="8">
                <input type="text" name="number" id="f-number" value="{{ old('number') }}" placeholder="0000000-00.0000.0.00.0000" required>
            </x-field>
            <div class="field col-4"><label>&nbsp;</label><button class="btn"><x-icon name="search" class="sm" /> Consultar</button></div>
        </div>
    </form>
</div>

<div class="stack">
    <div class="card">
        <div class="card-head">
            <h3><x-icon name="user" /> OABs acompanhadas</h3>
            <form method="post" action="{{ route('courts.lawyers.import') }}">@csrf<button class="btn btn-sm btn-ghost" title="Usa o campo OAB do cadastro de usuários"><x-icon name="users" class="sm" /> Importar dos usuários</button></form>
        </div>
        <div class="card-body flush">
            @if ($lawyers->isEmpty())
                <x-empty icon="user">Nenhuma OAB cadastrada.</x-empty>
            @else
                <ul class="list">
                    @foreach ($lawyers as $l)
                        <li>
                            <div class="grow">
                                <div class="title">{{ $l->name }} @if (! $l->active)<span class="badge">pausada</span>@endif</div>
                                <div class="small muted">{{ $l->label }}@if ($l->user) · {{ $l->user->name }}@endif @if ($l->fresh_count) · <a class="link" href="{{ route('publications.index', ['oab' => $l->id]) }}">{{ $l->fresh_count }} nova(s)</a>@endif</div>
                                <div class="small faint">@if ($l->last_checked_at)Consultada {{ $l->last_checked_at->diffForHumans() }}@else Ainda não consultada @endif</div>
                                @if ($l->last_error)<div class="small text-red">{{ $l->last_error }}</div>@endif
                            </div>
                            <form method="post" action="{{ route('courts.lawyers.toggle', $l) }}">@csrf<button class="btn btn-xs btn-ghost" title="{{ $l->active ? 'Pausar' : 'Retomar' }}"><x-icon name="{{ $l->active ? 'clock' : 'refresh' }}" class="sm" /></button></form>
                            <form method="post" action="{{ route('courts.lawyers.destroy', $l) }}" data-confirm="Remover {{ $l->label }}? As publicações já capturadas continuam.">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Remover"><x-icon name="trash" class="sm" /></button></form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <form method="post" action="{{ route('courts.lawyers.store') }}" class="card-body form-grid" style="border-top: 1px solid var(--border)">
            @csrf
            <x-field name="name" label="Advogado(a)" col="12"><input type="text" name="name" id="f-name" value="{{ old('name') }}" maxlength="150" required></x-field>
            <x-field name="oab_number" label="Nº da OAB" col="6"><input type="text" name="oab_number" id="f-oab_number" value="{{ old('oab_number') }}" placeholder="123456" required></x-field>
            <x-field name="oab_uf" label="UF" col="6">
                <select name="oab_uf" id="f-oab_uf" required>@foreach (CourtLawyer::UFS as $uf)<option value="{{ $uf }}" @selected(old('oab_uf') === $uf)>{{ $uf }}</option>@endforeach</select>
            </x-field>
            <x-field name="user_id" label="Usuário (recebe os prazos sem responsável)" col="12">
                <select name="user_id" id="f-user_id"><option value="">—</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected((string) old('user_id') === (string) $u->id)>{{ $u->name }}</option>@endforeach</select>
            </x-field>
            <div class="col-12 form-actions" style="padding-top: 0"><button class="btn btn-sm"><x-icon name="plus" class="sm" /> Adicionar OAB</button></div>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h3><x-icon name="book" /> Como o prazo é calculado</h3></div>
        <div class="card-body small muted">
            <p style="margin-top: 0"><strong>1.</strong> A publicação considera-se feita no <strong>1º dia útil seguinte</strong> à disponibilização no DJEN (Lei 11.419/2006, art. 4º, § 3º).</p>
            <p><strong>2.</strong> O prazo começa no 1º dia útil seguinte à publicação e conta só <strong>dias úteis</strong> (CPC, art. 219), pulando feriados nacionais, o recesso de 20/12 a 20/01 (CPC, art. 220) e os feriados informados aqui.</p>
            <p><strong>3.</strong> Se o texto disser "no prazo de N dias", o sistema usa N; senão, o prazo padrão.</p>
            <p style="margin-bottom: 0"><strong>Confira sempre:</strong> o vencimento é uma sugestão. Prazos em dobro, em horas, em dias corridos (penal) ou suspensões locais precisam de ajuste, o que dá para fazer na tela da publicação ou na tarefa.</p>
        </div>
    </div>
</div>
</div>
@endsection
