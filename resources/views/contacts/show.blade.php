@extends('layouts.app', ['title' => $contact->name])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('contacts.index') }}">Contatos</a> / {{ $contact->name }}</div>
<div class="page-head">
    <div class="row" style="gap: 14px">
        <span class="avatar xl" style="background: var(--accent)">{{ Format::initials($contact->name) }}</span>
        <div>
            <h1>{{ $contact->name }}</h1>
            <div class="row wrap" style="margin-top: 6px">
                <span class="badge gold">{{ Options::label(Options::CONTACT_CATEGORIES, $contact->category) }}</span>
                <span class="badge blue">{{ Options::label(Options::CRM_STAGES, $contact->crm_stage) }}</span>
                <span class="badge">{{ $contact->person_type === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física' }}</span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        @if ($contact->whatsapp_link)<a class="btn" target="_blank" rel="noopener" href="{{ $contact->whatsapp_link }}"><x-icon name="whatsapp" class="sm" /> WhatsApp</a>@endif
        <a class="btn" href="{{ route('assistant.index', ['contato' => $contact->id]) }}"><x-icon name="sparkles" class="sm" /> Perguntar à IA</a>
        <div class="dropdown">
            <button class="btn" data-dropdown-toggle><x-icon name="plus" class="sm" /> Adicionar <x-icon name="chevron-down" class="sm" /></button>
            <div class="dropdown-menu">
                <a href="{{ route('cases.create', ['contato' => $contact->id]) }}"><x-icon name="scale" /> Processo</a>
                <a href="{{ route('tasks.create', ['contato' => $contact->id]) }}"><x-icon name="check-square" /> Tarefa</a>
                <a href="{{ route('events.create', ['contato' => $contact->id]) }}"><x-icon name="calendar" /> Compromisso</a>
                <a href="{{ route('finance.create', ['contato' => $contact->id, 'tipo' => 'receita']) }}"><x-icon name="dollar" /> Lançamento financeiro</a>
            </div>
        </div>
        <a class="btn" href="{{ route('contacts.edit', $contact) }}"><x-icon name="edit" class="sm" /> Editar</a>
        <form method="post" action="{{ route('contacts.destroy', $contact) }}" data-confirm="Excluir este contato? Processos e lançamentos vinculados serão mantidos sem o vínculo.">@csrf @method('delete')<button class="btn btn-danger"><x-icon name="trash" class="sm" /></button></form>
    </div>
</div>

<div class="grid grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3><x-icon name="scale" /> Processos ({{ $contact->legalCases->count() }})</h3></div>
            <div class="card-body flush">
                @if ($contact->legalCases->isEmpty())
                    <x-empty icon="scale">Nenhum processo vinculado.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($contact->legalCases as $case)
                            <li>
                                <div class="grow">
                                    <a class="title" href="{{ route('cases.show', $case) }}">{{ $case->title }}</a>
                                    <div class="small muted">{{ $case->number ?: 'Sem número' }} · {{ Options::label(Options::CASE_AREAS, $case->area) }}</div>
                                </div>
                                <span class="badge {{ $case->status === 'ativo' ? 'green' : '' }}">{{ Options::label(Options::CASE_STATUS, $case->status) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h3><x-icon name="check-square" /> Tarefas</h3></div>
            <div class="card-body flush">
                @if ($contact->tasks->isEmpty())
                    <x-empty icon="check-square">Nenhuma tarefa.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($contact->tasks as $task)
                            <li>
                                <div class="grow"><a class="title" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a></div>
                                <span class="small {{ $task->isOverdue() ? 'text-red' : 'muted' }}">{{ Format::relativeDay($task->due_at) }}</span>
                                <span class="badge {{ $task->status === 'concluida' ? 'green' : '' }}">{{ Options::label(Options::TASK_STATUS, $task->status) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h3><x-icon name="wallet" /> Financeiro</h3></div>
            <div class="card-body flush">
                @include('finance._mini', ['entries' => $contact->financialEntries])
            </div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h3>Dados cadastrais</h3></div>
            <div class="card-body">
                <dl class="dl" style="grid-template-columns: 110px 1fr">
                    <dt>CPF/CNPJ</dt><dd>{{ $contact->document ?: '—' }}</dd>
                    <dt>RG</dt><dd>{{ $contact->rg ?: '—' }}</dd>
                    <dt>Nascimento</dt><dd>{{ Format::date($contact->birth_date) }}</dd>
                    <dt>Estado civil</dt><dd>{{ $contact->marital_status ?: '—' }}</dd>
                    <dt>Profissão</dt><dd>{{ $contact->profession ?: '—' }}</dd>
                    <dt>E-mail</dt><dd>@if ($contact->email)<a class="link" href="mailto:{{ $contact->email }}">{{ $contact->email }}</a>@else — @endif</dd>
                    <dt>Telefone</dt><dd>{{ $contact->phone ?: '—' }}</dd>
                    <dt>WhatsApp</dt><dd>{{ $contact->whatsapp ?: '—' }}</dd>
                    <dt>Endereço</dt><dd>{{ collect([$contact->address, $contact->city ? $contact->city.'/'.$contact->state : null, $contact->zip_code])->filter()->implode(' · ') ?: '—' }}</dd>
                    <dt>Origem</dt><dd>{{ $contact->origin ? Options::label(Options::CONTACT_ORIGINS, $contact->origin) : '—' }}</dd>
                    <dt>Responsável</dt><dd>{{ $contact->owner?->name ?? '—' }}</dd>
                </dl>
                @if ($contact->notes)<hr><div class="pre small">{{ $contact->notes }}</div>@endif
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h3><x-icon name="calendar" /> Próximos compromissos</h3></div>
            <div class="card-body flush">
                @if ($contact->events->isEmpty())
                    <x-empty icon="calendar">Nada agendado.</x-empty>
                @else
                    <ul class="list">
                        @foreach ($contact->events as $event)
                            <li><div class="grow"><a class="title" href="{{ route('events.edit', $event) }}">{{ $event->title }}</a><div class="small muted">{{ $event->starts_at->format('d/m/Y H:i') }}</div></div></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
