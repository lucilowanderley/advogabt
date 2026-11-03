@extends('layouts.app', ['title' => 'Meu painel'])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ now()->hour < 12 ? 'Bom dia' : (now()->hour < 18 ? 'Boa tarde' : 'Boa noite') }}, {{ auth()->user()->first_name }}</h1>
        <div class="sub">{{ ucfirst(now()->translatedFormat('l, j \d\e F \d\e Y')) }}</div>
    </div>
    <div class="chips">
        <a class="chip" href="{{ route('tasks.index', ['view' => 'lista', 'status' => 'concluida']) }}"><x-icon name="check-check" class="sm" /> {{ $stats['done_month'] }} finalizadas no mês</a>
        <a class="chip" href="{{ route('tasks.index') }}"><x-icon name="clock" class="sm" /> {{ $stats['pending'] }} pendentes</a>
        <span class="chip"><x-icon name="trophy" class="sm" /> {{ number_format($stats['points'], 0, ',', '.') }} pontos</span>
    </div>
</div>

@foreach ($security ?? [] as $warning)
    <div class="alert error mb"><x-icon name="alert" /> {{ $warning }}</div>
@endforeach

<div class="grid grid-4 mb">
    <a class="card kpi" href="{{ route('cases.index') }}">
        <div class="kpi-icon"><x-icon name="scale" /></div>
        <div><div class="value">{{ $stats['active_cases'] }}</div><div class="label">Processos ativos</div></div>
    </a>
    <a class="card kpi" href="{{ route('contacts.index', ['categoria' => 'cliente']) }}">
        <div class="kpi-icon blue"><x-icon name="users" /></div>
        <div><div class="value">{{ $stats['clients'] }}</div><div class="label">Clientes · {{ $stats['leads'] }} em negociação</div></div>
    </a>
    <a class="card kpi" href="{{ route('finance.index', ['tipo' => 'receita']) }}">
        <div class="kpi-icon green"><x-icon name="arrow-up" /></div>
        <div><div class="value">{{ Format::money($finance['received']) }}</div><div class="label">Recebido no mês · a receber {{ Format::money($finance['to_receive']) }}</div></div>
    </a>
    <a class="card kpi" href="{{ route('tasks.index', ['view' => 'lista']) }}">
        <div class="kpi-icon red"><x-icon name="alert" /></div>
        <div><div class="value">{{ $stats['overdue'] }}</div><div class="label">Tarefas atrasadas</div></div>
    </a>
</div>

<div class="grid grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-head">
                <h2><x-icon name="gavel" /> Prazos processuais (próximos 10 dias)</h2>
                <a class="btn btn-sm btn-ghost" href="{{ route('tasks.create') }}"><x-icon name="plus" class="sm" /> Novo prazo</a>
            </div>
            <div class="card-body flush">
                @if ($deadlines->isEmpty())
                    <x-empty icon="check-check">Nenhum prazo nos próximos dias.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($deadlines as $task)
                            <li>
                                <div class="date-box" style="{{ $task->isOverdue() ? 'border-color: var(--red)' : '' }}">
                                    <div class="d">{{ $task->due_at->format('d') }}</div>
                                    <div class="m">{{ $task->due_at->translatedFormat('M') }}</div>
                                </div>
                                <div class="grow">
                                    <a class="title" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                    <div class="small muted">{{ $task->legalCase?->display_name ?? 'Sem processo vinculado' }}</div>
                                </div>
                                @if ($task->isOverdue())
                                    <span class="badge red">Vencido</span>
                                @elseif ($task->due_at->isToday())
                                    <span class="badge orange">Hoje</span>
                                @else
                                    <span class="badge purple">{{ (int) today()->diffInDays($task->due_at->copy()->startOfDay()) }} dia(s)</span>
                                @endif
                                <div class="avatars hide-sm">@foreach ($task->users->take(3) as $u)<x-avatar :user="$u" />@endforeach</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2><x-icon name="check-square" /> Minhas tarefas em aberto</h2>
                <a class="btn btn-sm btn-ghost" href="{{ route('tasks.index') }}">Ver quadro <x-icon name="chevron-right" class="sm" /></a>
            </div>
            <div class="card-body flush">
                @if ($myOpenTasks->isEmpty())
                    <x-empty icon="check-check">Tudo em dia! Nenhuma tarefa pendente.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($myOpenTasks as $task)
                            <li>
                                <form method="post" action="{{ route('tasks.move', $task) }}">
                                    @csrf @method('patch')
                                    <input type="hidden" name="column" value="concluidas">
                                    <button class="btn btn-xs" title="Concluir"><x-icon name="check" class="sm" /></button>
                                </form>
                                <div class="grow">
                                    <a class="title" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                    <div class="small muted">{{ $task->context_label ?? ($task->group ?: '—') }}</div>
                                </div>
                                @if ($task->status === 'fazendo')<span class="badge blue">Fazendo</span>@endif
                                <span class="small nowrap {{ $task->isOverdue() ? 'text-red' : 'muted' }}"><x-icon name="clock" class="sm" style="vertical-align: -3px" /> {{ Format::relativeDay($task->due_at, true) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2><x-icon name="chart" /> Fluxo de caixa (últimos 6 meses)</h2>
                <div class="legend"><span><i style="background: var(--accent)"></i> Receitas</span><span><i style="background: var(--faint)"></i> Despesas</span></div>
            </div>
            <div class="card-body">
                @php $max = max(1, $chart->max(fn ($m) => max($m['receita'], $m['despesa']))); @endphp
                <div class="bars">
                    @foreach ($chart as $m)
                        <div class="bar-group">
                            <div class="pair">
                                <div class="bar in" style="height: {{ round($m['receita'] / $max * 100) }}%" title="Receitas: {{ Format::money($m['receita']) }}"></div>
                                <div class="bar out" style="height: {{ round($m['despesa'] / $max * 100) }}%" title="Despesas: {{ Format::money($m['despesa']) }}"></div>
                            </div>
                            <div class="lbl">{{ $m['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head">
                <h2><x-icon name="calendar" /> Agenda de hoje</h2>
                <a class="btn btn-sm btn-ghost" href="{{ route('events.create') }}"><x-icon name="plus" class="sm" /></a>
            </div>
            <div class="card-body flush">
                @if ($todayEvents->isEmpty())
                    <x-empty icon="calendar">Nenhum compromisso hoje.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($todayEvents as $event)
                            <li>
                                <div class="strong nowrap" style="width: 44px">{{ $event->all_day ? 'Dia' : $event->starts_at->format('H:i') }}</div>
                                <div class="grow">
                                    <a class="title" href="{{ route('events.edit', $event) }}">{{ $event->title }}</a>
                                    <div class="small muted">{{ Options::label(Options::EVENT_TYPES, $event->type) }}{{ $event->location ? ' · '.$event->location : '' }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($upcomingEvents->isNotEmpty())
                    <div class="small faint" style="padding: 10px 16px 0; text-transform: uppercase; letter-spacing: .08em">Próximos 7 dias</div>
                    <ul class="list">
                        @foreach ($upcomingEvents as $event)
                            <li>
                                <div class="date-box"><div class="d">{{ $event->starts_at->format('d') }}</div><div class="m">{{ $event->starts_at->translatedFormat('M') }}</div></div>
                                <div class="grow">
                                    <a class="title" href="{{ route('events.edit', $event) }}">{{ $event->title }}</a>
                                    <div class="small muted">{{ $event->all_day ? 'Dia inteiro' : $event->starts_at->format('H:i') }} · {{ Options::label(Options::EVENT_TYPES, $event->type) }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2><x-icon name="wallet" /> Financeiro do mês</h2></div>
            <div class="card-body">
                <dl class="dl" style="grid-template-columns: 1fr auto">
                    <dt>Recebido</dt><dd class="right text-green strong">{{ Format::money($finance['received']) }}</dd>
                    <dt>A receber</dt><dd class="right">{{ Format::money($finance['to_receive']) }}</dd>
                    <dt>Pago</dt><dd class="right text-red">{{ Format::money($finance['paid']) }}</dd>
                    <dt>A pagar</dt><dd class="right">{{ Format::money($finance['to_pay']) }}</dd>
                    <dt>Inadimplência total</dt><dd class="right text-orange">{{ Format::money($finance['overdue']) }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2><x-icon name="trophy" /> Ranking de pontos do mês</h2></div>
            <div class="card-body flush">
                @php $top = max(1, (int) $ranking->max('points_month')); @endphp
                <ul class="list">
                    @foreach ($ranking as $i => $u)
                        <li>
                            <span class="faint strong" style="width: 14px">{{ $loop->iteration }}</span>
                            <x-avatar :user="$u" />
                            <div class="grow">
                                <div class="row between"><span class="strong">{{ $u->name }}</span><span class="small muted">{{ (int) $u->points_month }} pts</span></div>
                                <div class="progress" style="margin-top: 5px"><span style="width: {{ round(((int) $u->points_month) / $top * 100) }}%"></span></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
