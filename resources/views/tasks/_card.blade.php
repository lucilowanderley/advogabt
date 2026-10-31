@php use App\Support\Format; @endphp
<div class="task-card {{ $task->status === 'concluida' ? 'done' : '' }}" data-draggable data-id="{{ $task->id }}" data-move-url="{{ route('tasks.move', $task) }}">
    @if ($task->priority !== 'normal' || $task->is_deadline)
        <div class="labels">
            @if ($task->priority !== 'normal')<span class="pri-{{ $task->priority }}" title="Prioridade {{ \App\Support\Options::label(\App\Support\Options::TASK_PRIORITIES, $task->priority) }}"></span>@endif
            @if ($task->is_deadline)<span class="deadline-tag" title="Prazo processual"></span>@endif
        </div>
    @endif
    <a href="{{ route('tasks.show', $task) }}" class="t"><x-icon name="file-text" class="sm" /> <span>{{ $task->title }}</span></a>
    @if ($task->context_label || $task->group)
        <div class="ctx">
            @if ($task->context_label){{ $task->context_label }}<br>@endif
            @if ($task->group){{ $task->group }}@endif
        </div>
    @endif
    @php
        $dueClass = $task->status !== 'concluida' && $task->due_at ? ($task->isOverdue() ? 'late' : ($task->due_at->isToday() ? 'today' : '')) : '';
    @endphp
    <div class="due {{ $dueClass }}">
        <x-icon name="clock" class="sm" />
        @if ($task->status === 'concluida')
            Concluída {{ Format::relativeDay($task->completed_at, true) }}
        @else
            {{ Format::relativeDay($task->due_at, true) }}
        @endif
    </div>
    <div class="foot">
        <div class="avatars">
            @foreach ($task->users->take(3) as $u)<x-avatar :user="$u" />@endforeach
            @if ($task->users->count() > 3)<span class="avatar more">+{{ $task->users->count() - 3 }}</span>@endif
        </div>
        <div class="acts">
            @if ($task->comments_count)<a href="{{ route('tasks.show', $task) }}#comentarios" title="Comentários" class="small"><x-icon name="message" class="sm" />&nbsp;{{ $task->comments_count }}</a>@endif
            <a href="{{ route('tasks.edit', $task) }}" title="Editar"><x-icon name="edit" class="sm" /></a>
            @if ($task->status !== 'concluida')
                <form method="post" action="{{ route('tasks.move', $task) }}">
                    @csrf @method('patch')
                    <input type="hidden" name="column" value="concluidas">
                    <button title="Concluir (+{{ $task->points }} pts)"><x-icon name="check" class="sm" /></button>
                </form>
            @else
                <form method="post" action="{{ route('tasks.move', $task) }}">
                    @csrf @method('patch')
                    <input type="hidden" name="column" value="todas">
                    <button title="Reabrir"><x-icon name="history" class="sm" /></button>
                </form>
            @endif
        </div>
    </div>
</div>
