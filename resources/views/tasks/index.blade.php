@extends('layouts.app', ['title' => 'Tarefas'])
@php use App\Support\Format; use App\Support\Options; @endphp

@section('content')
@include('tasks._header')

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th></th><th>Tarefa</th><th>Vínculo</th><th>Responsáveis</th><th>Prazo</th><th>Situação</th><th class="right">Pts</th><th></th></tr></thead>
            <tbody>
            @forelse ($tasks as $task)
                <tr>
                    <td style="width: 40px">
                        <form method="post" action="{{ route('tasks.move', $task) }}">
                            @csrf @method('patch')
                            <input type="hidden" name="column" value="{{ $task->status === 'concluida' ? 'todas' : 'concluidas' }}">
                            <button class="btn btn-xs" title="{{ $task->status === 'concluida' ? 'Reabrir' : 'Concluir' }}"><x-icon :name="$task->status === 'concluida' ? 'history' : 'check'" class="sm" /></button>
                        </form>
                    </td>
                    <td>
                        <a class="title" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                        <div class="small muted">
                            @if ($task->is_deadline)<span class="badge purple">Prazo</span>@endif
                            @if ($task->priority !== 'normal')<span class="badge {{ in_array($task->priority, ['alta', 'urgente']) ? 'orange' : '' }}">{{ Options::label(Options::TASK_PRIORITIES, $task->priority) }}</span>@endif
                            {{ $task->group }}
                        </div>
                    </td>
                    <td class="small">{{ $task->context_label ?? '—' }}</td>
                    <td><div class="avatars">@foreach ($task->users->take(4) as $u)<x-avatar :user="$u" />@endforeach</div></td>
                    <td class="nowrap {{ $task->isOverdue() ? 'text-red' : '' }}">{{ Format::relativeDay($task->due_at, true) }}</td>
                    <td>
                        @php $c = ['pendente' => '', 'fazendo' => 'blue', 'concluida' => 'green'][$task->status] ?? ''; @endphp
                        <span class="badge {{ $c }}">{{ Options::label(Options::TASK_STATUS, $task->status) }}</span>
                    </td>
                    <td class="right">{{ $task->points }}</td>
                    <td><div class="actions"><a class="btn btn-xs btn-ghost" href="{{ route('tasks.edit', $task) }}"><x-icon name="edit" class="sm" /></a></div></td>
                </tr>
            @empty
                <tr><td colspan="8"><x-empty icon="check-check">Nenhuma tarefa encontrada.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $tasks->links() }}
</div>
@endsection
