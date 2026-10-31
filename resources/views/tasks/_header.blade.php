<div class="page-head">
    <div>
        <h1>Tarefas</h1>
        <div class="sub">Organize o trabalho da equipe e acompanhe os prazos.</div>
    </div>
    <div class="chips">
        <span class="chip"><x-icon name="check-check" class="sm" /> {{ $summary['done'] }} finalizadas no mês</span>
        <span class="chip"><x-icon name="clock" class="sm" /> {{ $summary['pending'] }} pendentes</span>
        <span class="chip"><x-icon name="trophy" class="sm" /> {{ number_format($summary['points'], 0, ',', '.') }} pontos</span>
        <a class="btn btn-primary" href="{{ route('tasks.create') }}"><x-icon name="plus" class="sm" /> Nova tarefa</a>
    </div>
</div>

<div class="tabs">
    <a href="{{ route('tasks.index', array_merge(request()->except('page', 'view'), ['view' => 'quadro'])) }}" class="{{ $view !== 'lista' ? 'active' : '' }}"><x-icon name="columns" class="sm" /> Quadro</a>
    <a href="{{ route('tasks.index', array_merge(request()->except('page', 'view'), ['view' => 'lista'])) }}" class="{{ $view === 'lista' ? 'active' : '' }}"><x-icon name="list" class="sm" /> Lista</a>
</div>

<form class="toolbar" method="get">
    <input type="hidden" name="view" value="{{ $view }}">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar tarefa..."></div>
    <select name="scope" data-autosubmit>
        <option value="minhas" @selected($scope === 'minhas')>Minhas tarefas</option>
        <option value="todas" @selected($scope === 'todas')>Toda a equipe</option>
        @foreach ($users as $u)
            <option value="{{ $u->id }}" @selected((string) $scope === (string) $u->id)>{{ $u->name }}</option>
        @endforeach
    </select>
    <select name="grupo" data-autosubmit>
        <option value="">Todos os grupos</option>
        @foreach ($groups as $g)
            <option value="{{ $g }}" @selected(request('grupo') === $g)>{{ $g }}</option>
        @endforeach
    </select>
    @isset($status)
        <select name="status" data-autosubmit>
            <option value="abertas" @selected($status === 'abertas')>Em aberto</option>
            @foreach (\App\Support\Options::TASK_STATUS as $k => $l)
                <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>
            @endforeach
            <option value="todas" @selected($status === 'todas')>Todas</option>
        </select>
    @endisset
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>
