@extends('layouts.app', ['title' => $task->title])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('tasks.index') }}">Tarefas</a> / Detalhes</div>
<div class="page-head">
    <div>
        <h1>{{ $task->title }}</h1>
        <div class="row wrap mt" style="margin-top: 8px">
            @php $c = ['pendente' => '', 'fazendo' => 'blue', 'concluida' => 'green'][$task->status] ?? ''; @endphp
            <span class="badge {{ $c }}">{{ Options::label(Options::TASK_STATUS, $task->status) }}</span>
            <span class="badge">Prioridade {{ Options::label(Options::TASK_PRIORITIES, $task->priority) }}</span>
            @if ($task->is_deadline)<span class="badge purple">Prazo processual</span>@endif
            <span class="badge gold">{{ $task->points }} pontos</span>
        </div>
    </div>
    <div class="page-actions">
        @if ($task->status === 'pendente')
            <form method="post" action="{{ route('tasks.move', $task) }}">@csrf @method('patch')<input type="hidden" name="column" value="fazendo"><button class="btn">Iniciar</button></form>
        @endif
        @if ($task->status !== 'concluida')
            <form method="post" action="{{ route('tasks.move', $task) }}">@csrf @method('patch')<input type="hidden" name="column" value="concluidas"><button class="btn btn-primary"><x-icon name="check" class="sm" /> Concluir</button></form>
        @else
            <form method="post" action="{{ route('tasks.move', $task) }}">@csrf @method('patch')<input type="hidden" name="column" value="todas"><button class="btn"><x-icon name="history" class="sm" /> Reabrir</button></form>
        @endif
        <a class="btn" href="{{ route('tasks.edit', $task) }}"><x-icon name="edit" class="sm" /> Editar</a>
        <form method="post" action="{{ route('tasks.destroy', $task) }}" data-confirm="Excluir esta tarefa?">@csrf @method('delete')<button class="btn btn-danger"><x-icon name="trash" class="sm" /></button></form>
    </div>
</div>

<div class="grid grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3>Descrição</h3></div>
            <div class="card-body pre">{{ $task->description ?: 'Sem descrição.' }}</div>
        </div>
        <div class="card" id="comentarios">
            <div class="card-head"><h3><x-icon name="message" /> Comentários ({{ $task->comments->count() }})</h3></div>
            <div class="card-body">
                <form method="post" action="{{ route('tasks.comment', $task) }}" class="mb">
                    @csrf
                    <textarea name="body" rows="3" placeholder="Escreva um comentário ou atualização..." required></textarea>
                    <div class="form-actions"><button class="btn btn-primary btn-sm">Comentar</button></div>
                </form>
                @foreach ($task->comments as $comment)
                    <div class="row" style="align-items: flex-start; margin-bottom: 14px">
                        @if ($comment->user)<x-avatar :user="$comment->user" />@endif
                        <div class="grow">
                            <div class="small"><b>{{ $comment->user?->name ?? 'Usuário removido' }}</b> <span class="faint">· {{ $comment->created_at->diffForHumans() }}</span></div>
                            <div class="pre">{{ $comment->body }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <dl class="dl" style="grid-template-columns: 110px 1fr">
                <dt>Prazo</dt><dd class="{{ $task->isOverdue() ? 'text-red' : '' }}">{{ $task->due_at ? $task->due_at->format($task->due_at->format('H:i') === '00:00' ? 'd/m/Y' : 'd/m/Y H:i') : 'Sem prazo' }}</dd>
                @if ($task->completed_at)<dt>Concluída em</dt><dd>{{ $task->completed_at->format('d/m/Y H:i') }}</dd>@endif
                <dt>Grupo</dt><dd>{{ $task->group ?: '—' }}</dd>
                <dt>Processo</dt><dd>@if ($task->legalCase)<a class="link" href="{{ route('cases.show', $task->legalCase) }}">{{ $task->legalCase->display_name }}</a>@else — @endif</dd>
                <dt>Contato</dt><dd>@if ($task->contact)<a class="link" href="{{ route('contacts.show', $task->contact) }}">{{ $task->contact->name }}</a>@else — @endif</dd>
                <dt>Criada por</dt><dd>{{ $task->creator?->name ?? '—' }}<br><span class="small faint">{{ $task->created_at->format('d/m/Y H:i') }}</span></dd>
                <dt>Responsáveis</dt>
                <dd>@forelse ($task->users as $u)<div class="row" style="margin-bottom: 4px"><x-avatar :user="$u" /> {{ $u->name }}</div>@empty — @endforelse</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
