@extends('layouts.app', ['title' => 'Agenda e e-mail · Integrações'])
@php
    use App\Models\CalendarFeed;
    use App\Models\MailLog;
    use App\Models\MailSetting;
    $isAdmin = auth()->user()->isAdmin();
    $provider = old('provider', $settings->provider ?: 'gmail');
    $ready = $settings->exists && $settings->active && $settings->isConfigured();
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Agenda e e-mail</div>
<div class="page-head">
    <div>
        <h1>Agenda e e-mail</h1>
        <div class="sub">Audiências, reuniões e prazos no Google Agenda, Outlook ou iPhone, e e-mails automáticos de convite, lembrete e resumo diário.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('events.index') }}"><x-icon name="calendar" class="sm" /> Abrir a agenda</a>
    </div>
</div>

@include('integrations._tabs')

<div class="grid grid-3 mb">
    <div class="card kpi">
        <div class="kpi-icon {{ $ready ? 'green' : 'red' }}"><x-icon name="mail" /></div>
        <div>
            <div class="value" style="font-size: 18px">{{ $ready ? 'E-mail ativo' : ($settings->exists ? 'E-mail desligado' : 'E-mail não configurado') }}</div>
            <div class="label">{{ $settings->exists && $settings->from_address ? $settings->provider_label.' · '.$settings->from_address : ($isAdmin ? 'Configure a conta abaixo' : 'Um administrador configura a conta do escritório') }}</div>
        </div>
    </div>
    <div class="card kpi"><div class="kpi-icon blue"><x-icon name="calendar" /></div><div><div class="value">{{ $feedCount }}</div><div class="label">Agenda(s) assinada(s) pela equipe</div></div></div>
    <div class="card kpi">
        <div class="kpi-icon {{ ($sentWeek->total ?? 0) > ($sentWeek->ok ?? 0) ? 'red' : 'green' }}"><x-icon name="check-check" /></div>
        <div><div class="value">{{ (int) ($sentWeek->ok ?? 0) }} / {{ (int) ($sentWeek->total ?? 0) }}</div><div class="label">E-mails enviados nos últimos 7 dias</div></div>
    </div>
</div>

<div class="grid grid-main">
<div class="stack">
    {{-- Assinatura da agenda: cada usuário cria o seu link --}}
    <div class="card">
        <div class="card-head"><h3><x-icon name="calendar" /> Minha agenda no Google Agenda ou Outlook</h3></div>
        <div class="card-body">
            <p class="small muted" style="margin-top: 0">Crie um link e assine-o no app de agenda: os compromissos e prazos do ADVOGABT aparecem lá e se atualizam sozinhos (o Google atualiza a cada poucas horas; o Outlook e o iPhone, mais rápido). A agenda assinada é só para consulta: altere os compromissos aqui no sistema.</p>
            @if ($localHost)
                <div class="alert warning small"><x-icon name="alert" /> <div>Este sistema está em um endereço local (<code>{{ request()->getHost() }}</code>). O Google e o Outlook buscam o link pela internet e não conseguem acessá-lo. Enquanto o sistema não estiver publicado (domínio próprio ou túnel como o Cloudflare Tunnel), use os <strong>convites por e-mail</strong> abaixo, que levam cada compromisso para a agenda na hora, ou o botão <strong>Baixar</strong> para importar uma cópia.</div></div>
            @endif

            @if ($feeds->isNotEmpty())
                <div class="stack" style="gap: 10px; margin-bottom: 14px">
                @foreach ($feeds as $feed)
                    <div class="card {{ session('new_feed') === $feed->id ? 'new-secret' : '' }}" style="box-shadow: none">
                        <div class="card-body" style="padding: 12px 14px">
                            <div class="row" style="justify-content: space-between; align-items: flex-start">
                                <div>
                                    <strong>{{ $feed->name }}</strong>
                                    <div class="small faint">{{ CalendarFeed::SCOPES[$feed->scope] ?? $feed->scope }} · {{ CalendarFeed::TASKS[$feed->tasks] ?? $feed->tasks }} · {{ $feed->last_accessed_at ? 'lido '.$feed->last_accessed_at->diffForHumans() : 'ainda não foi lido' }}</div>
                                </div>
                                <div class="actions">
                                    <form method="post" action="{{ route('agenda-email.feeds.regenerate', $feed) }}" data-confirm="Gerar um link novo? O atual para de funcionar e será preciso assinar de novo.">@csrf<button class="btn btn-xs btn-ghost" title="Trocar o link (se ele vazou)"><x-icon name="refresh" class="sm" /></button></form>
                                    <form method="post" action="{{ route('agenda-email.feeds.destroy', $feed) }}" data-confirm="Excluir este link? A agenda assinada deixa de ser atualizada.">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Excluir"><x-icon name="trash" class="sm" /></button></form>
                                </div>
                            </div>
                            <div class="copy-field" style="margin-top: 8px"><code data-copy-source>{{ $feed->url() }}</code><button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button></div>
                            <div class="row wrap" style="margin-top: 8px; gap: 6px">
                                <a class="btn btn-xs" target="_blank" rel="noopener" href="https://calendar.google.com/calendar/u/0/r/settings/addbyurl"><x-icon name="external" class="sm" /> Google Agenda: adicionar por URL</a>
                                <a class="btn btn-xs" target="_blank" rel="noopener" href="https://outlook.office.com/calendar/addcalendar"><x-icon name="external" class="sm" /> Outlook: assinar da Web</a>
                                <a class="btn btn-xs" href="{{ $feed->webcalUrl() }}"><x-icon name="calendar" class="sm" /> Abrir no app de agenda</a>
                                <a class="btn btn-xs btn-ghost" href="{{ $feed->url() }}" download><x-icon name="download" class="sm" /> Baixar</a>
                            </div>
                        </div>
                    </div>
                @endforeach
                </div>
            @endif

            <form method="post" action="{{ route('agenda-email.feeds.store') }}" class="form-grid">
                @csrf
                <x-field name="scope" label="O que mostrar" col="6">
                    <select name="scope" id="f-scope">@foreach (CalendarFeed::SCOPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                </x-field>
                <x-field name="tasks" label="Tarefas" col="6">
                    <select name="tasks" id="f-tasks">@foreach (CalendarFeed::TASKS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                </x-field>
                <x-field name="name" label="Nome da agenda (opcional)" col="8">
                    <input type="text" name="name" id="f-name" maxlength="100" placeholder="Minha agenda · {{ config('app.name') }}">
                </x-field>
                <div class="col-4 form-actions" style="align-items: flex-end; padding-bottom: 0"><button class="btn btn-primary"><x-icon name="plus" class="sm" /> Criar link</button></div>
            </form>
            <p class="small faint" style="margin-bottom: 0">O link é secreto: quem o tiver vê a agenda, sem precisar de senha. Não compartilhe; se vazar, troque-o pelo botão <x-icon name="refresh" class="sm" />.</p>
        </div>
    </div>

    @if ($isAdmin)
    {{-- Conta de e-mail do escritório --}}
    <form method="post" action="{{ route('agenda-email.mail.save') }}" class="card" data-mail-form>
        @csrf
        <div class="card-head"><h3><x-icon name="mail" /> E-mail do escritório</h3></div>
        <div class="card-body form-grid">
            <x-field name="provider" label="Conta" col="12">
                <select name="provider" id="f-provider" data-mail-provider>
                    @foreach (MailSetting::PROVIDERS as $k => $p)
                        <option value="{{ $k }}" data-host="{{ $p['host'] }}" data-port="{{ $p['port'] }}" data-encryption="{{ $p['encryption'] }}" @selected($provider === $k)>{{ $p['label'] }}</option>
                    @endforeach
                </select>
            </x-field>
            @foreach (MailSetting::PROVIDERS as $k => $p)
                <div class="col-12 small muted" data-provider-help="{{ $k }}" @if ($provider !== $k) hidden @endif>
                    {{ $p['help'] }}
                    @if ($p['link'])<a class="link" target="_blank" rel="noopener" href="{{ $p['link'] }}">Gerar senha de app <x-icon name="external" class="sm" /></a>@endif
                </div>
            @endforeach
            <x-field name="from_address" label="E-mail do remetente *" col="6" hint="A conta que envia. No Gmail e no Outlook, é também o usuário do login.">
                <input type="email" name="from_address" id="f-from_address" value="{{ old('from_address', $settings->from_address) }}" required maxlength="255" placeholder="escritorio@gmail.com">
            </x-field>
            <x-field name="password" label="Senha de app" col="6" :hint="$settings->password ? 'Já cadastrada. Deixe em branco para manter.' : 'Fica gravada criptografada.'">
                <input type="password" name="password" id="f-password" autocomplete="new-password" placeholder="{{ $settings->password ? '••••••••' : 'abcd efgh ijkl mnop' }}">
            </x-field>
            <x-field name="from_name" label="Nome do remetente" col="6">
                <input type="text" name="from_name" id="f-from_name" value="{{ old('from_name', $settings->from_name) }}" maxlength="100" placeholder="{{ config('app.name') }}">
            </x-field>
            <x-field name="reply_to" label="Responder para (opcional)" col="6">
                <input type="email" name="reply_to" id="f-reply_to" value="{{ old('reply_to', $settings->reply_to) }}" maxlength="255">
            </x-field>
            <details class="col-12" @if ($provider === 'smtp' || $errors->hasAny(['host', 'port', 'encryption', 'username'])) open @endif>
                <summary class="small" style="cursor: pointer; margin-bottom: 10px">Servidor SMTP (preenchido automaticamente para Gmail e Outlook)</summary>
                <div class="form-grid">
                    <x-field name="host" label="Servidor SMTP *" col="6">
                        <input type="text" name="host" id="f-host" value="{{ old('host', $settings->host) }}" required maxlength="255" placeholder="smtp.seudominio.com.br">
                    </x-field>
                    <x-field name="port" label="Porta *" col="2">
                        <input type="number" name="port" id="f-port" value="{{ old('port', $settings->port) }}" required min="1" max="65535">
                    </x-field>
                    <x-field name="encryption" label="Criptografia" col="4">
                        <select name="encryption" id="f-encryption">@foreach (MailSetting::ENCRYPTIONS as $k => $l)<option value="{{ $k }}" @selected(old('encryption', $settings->encryption) === $k)>{{ $l }}</option>@endforeach</select>
                    </x-field>
                    <x-field name="username" label="Usuário (se diferente do e-mail)" col="12">
                        <input type="text" name="username" id="f-username" value="{{ old('username', $settings->username !== $settings->from_address ? $settings->username : '') }}" maxlength="255" autocomplete="off">
                    </x-field>
                </div>
            </details>
        </div>

        <div class="card-head" style="border-top: 1px solid var(--border-soft)"><h3><x-icon name="bell" /> E-mails automáticos</h3></div>
        <div class="card-body form-grid">
            <div class="field col-12">
                <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $settings->active))> <span><strong>Envio de e-mails ligado</strong></span></label>
            </div>
            <div class="field col-12">
                <label class="check"><input type="checkbox" name="send_invites" value="1" @checked(old('send_invites', $settings->send_invites))> <span>Enviar <strong>convite</strong> (.ics) ao responsável quando um compromisso é marcado, alterado ou excluído</span></label>
                <div class="hint">O convite coloca o compromisso direto no Google Agenda/Outlook do advogado e o atualiza nas mudanças. Funciona mesmo com o sistema só no computador do escritório.</div>
                <label class="check" style="margin-top: 6px"><input type="checkbox" name="invite_clients" value="1" @checked(old('invite_clients', $settings->invite_clients))> <span>Convidar também o <strong>cliente</strong> vinculado (audiências, reuniões e perícias; sem as observações internas)</span></label>
            </div>
            <div class="field col-8">
                <label class="check"><input type="checkbox" name="remind_events" value="1" @checked(old('remind_events', $settings->remind_events))> <span><strong>Lembrete</strong> do compromisso ao responsável</span></label>
                <label class="check" style="margin-top: 6px"><input type="checkbox" name="remind_clients" value="1" @checked(old('remind_clients', $settings->remind_clients))> <span>Lembrar também o cliente vinculado (audiências, reuniões e perícias)</span></label>
            </div>
            <x-field name="remind_hours" label="Antecedência (horas)" col="4">
                <input type="number" name="remind_hours" id="f-remind_hours" value="{{ old('remind_hours', $settings->remind_hours) }}" min="1" max="168" required>
            </x-field>
            <div class="field col-8">
                <label class="check"><input type="checkbox" name="daily_digest" value="1" @checked(old('daily_digest', $settings->daily_digest))> <span><strong>Resumo diário</strong> para cada pessoa: compromissos do dia, prazos e tarefas vencendo ou atrasados</span></label>
            </div>
            <x-field name="digest_hour" label="Horário do resumo" col="4">
                <select name="digest_hour" id="f-digest_hour">@for ($h = 0; $h < 24; $h++)<option value="{{ $h }}" @selected((int) old('digest_hour', $settings->digest_hour) === $h)>{{ sprintf('%02d:00', $h) }}</option>@endfor</select>
            </x-field>
            <div class="col-12 small faint">Lembretes e resumos saem pela rotina das integrações (a cada 15 minutos pelo agendador do Laravel, ou durante o uso do sistema se o agendador não estiver configurado).</div>
            <div class="col-12 form-actions">
                <span class="spacer"></span>
                <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
            </div>
        </div>
    </form>
    @endif
</div>

<div class="stack">
    @if ($isAdmin)
        <div class="card">
            <div class="card-head"><h3><x-icon name="plug" /> Testar</h3></div>
            <div class="card-body small">
                @if ($settings->last_test_at)
                    <p style="margin-top: 0">Último teste {{ $settings->last_test_at->diffForHumans() }}: <span class="badge {{ $settings->last_test_ok ? 'green' : 'red' }}">{{ $settings->last_test_ok ? 'enviado' : 'falhou' }}</span></p>
                    @if (! $settings->last_test_ok && $settings->last_error)<p class="text-red">{{ $settings->last_error }}</p>@endif
                @endif
                <form method="post" action="{{ route('agenda-email.mail.test') }}" class="row">
                    @csrf
                    <input type="email" name="to" value="{{ auth()->user()->email }}" maxlength="255" style="flex: 1">
                    <button class="btn" @disabled(! $settings->exists)><x-icon name="mail" class="sm" /> Enviar e-mail de teste</button>
                </form>
                <form method="post" action="{{ route('agenda-email.mail.digest') }}" style="margin-top: 10px" data-confirm="Enviar agora o resumo de hoje para toda a equipe que tem compromissos ou prazos?">
                    @csrf
                    <button class="btn btn-sm btn-ghost" @disabled(! $ready)><x-icon name="bell" class="sm" /> Enviar o resumo de hoje agora</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h3><x-icon name="history" /> Últimos envios</h3></div>
            <div class="card-body flush">
                @if ($logs->isEmpty())
                    <x-empty icon="mail">Nenhum e-mail enviado ainda.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($logs as $log)
                            <li>
                                <div class="grow">
                                    <div class="row" style="gap: 6px"><span class="badge {{ $log->success ? 'green' : 'red' }}">{{ MailLog::KINDS[$log->kind] ?? $log->kind }}</span><span class="small faint">{{ $log->created_at?->diffForHumans() }}</span></div>
                                    <div class="small" style="margin-top: 3px">{{ $log->subject }}</div>
                                    <div class="small faint">{{ $log->to }}</div>
                                    @if ($log->error)<div class="small text-red">{{ $log->error }}</div>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h3><x-icon name="book" /> Como assinar</h3></div>
        <div class="card-body small muted">
            <p style="margin-top: 0"><strong>Google Agenda (computador):</strong> Configurações › Adicionar agenda › <em>Do URL</em> › cole o link › Adicionar agenda. No celular ela aparece sozinha depois disso.</p>
            <p><strong>Outlook (web ou novo Outlook):</strong> Agenda › Adicionar agenda › <em>Assinar da Web</em> › cole o link › Importar. No Outlook clássico: Agenda › Adicionar › Da Internet.</p>
            <p><strong>iPhone:</strong> Ajustes › Calendário › Contas › Adicionar conta › Outra › <em>Adicionar calendário assinado</em> › cole o link.</p>
            <p style="margin-bottom: 0"><strong>Um compromisso só:</strong> na tela do compromisso há os botões "Google Agenda", "Outlook" e ".ics".</p>
        </div>
    </div>
</div>
</div>
@endsection
