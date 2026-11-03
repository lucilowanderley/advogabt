@php
    use App\Http\Controllers\CloudFileController;
    $params = ['type' => $type, 'id' => $owner->id];
    $label = $storage?->driver_label ?? 'nuvem';
    $subfolders = collect($items)->where('folder', true);
@endphp
@if (! $storage)
    <x-empty icon="cloud">A nuvem do escritório não está conectada.</x-empty>
@elseif ($error)
    <div class="card-body"><div class="alert error" style="margin: 0"><x-icon name="alert" /> {{ $error }}</div></div>
@elseif (! $folder)
    <div class="card-body">
        <p class="small muted" style="margin-top: 0">{{ $type === 'processo' ? 'Este processo' : 'Este cliente' }} ainda não tem pasta no {{ $label }}.</p>
        <div class="row wrap">
            <form method="post" action="{{ route('cloud.files.folder', $params) }}">@csrf<button class="btn btn-primary btn-sm"><x-icon name="plus" class="sm" /> Criar pasta</button></form>
            <form method="post" action="{{ route('cloud.files.link', $params) }}" class="row grow" style="flex: 1; min-width: 240px">
                @csrf
                <input type="text" name="link" placeholder="ou cole o link de uma pasta que já existe" required maxlength="500" style="flex: 1">
                <button class="btn btn-sm">Vincular</button>
            </form>
        </div>
    </div>
@else
    <div class="card-body" style="padding-bottom: 8px">
        <div class="row between wrap" style="gap: 8px">
            <a class="link small" target="_blank" rel="noopener" href="{{ $folder->web_url }}"><x-icon name="external" class="sm" /> {{ $folder->name }}</a>
            <form method="post" action="{{ route('cloud.files.unlink', $params) }}" data-confirm="Remover o vínculo com esta pasta? Ela e os arquivos continuam no {{ $label }}.">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Desvincular pasta">Desvincular</button></form>
        </div>
        <form method="post" action="{{ route('cloud.files.upload', $params) }}" enctype="multipart/form-data" class="row wrap mt" style="margin-top: 10px; gap: 8px" data-cloud-upload>
            @csrf
            <input type="file" name="files[]" multiple required style="flex: 1; min-width: 200px">
            @if ($subfolders->isNotEmpty())
                <select name="target" style="width: auto">
                    <option value="{{ $folder->remote_id }}">Pasta do {{ $type === 'processo' ? 'processo' : 'cliente' }}</option>
                    @foreach ($subfolders as $sub)<option value="{{ $sub['id'] }}">{{ $sub['name'] }}</option>@endforeach
                </select>
            @endif
            <button class="btn btn-primary btn-sm"><x-icon name="arrow-up" class="sm" /> Enviar</button>
        </form>
        <div class="hint small faint" style="margin-top: 4px">Até {{ CloudFileController::uploadLimit() }} por envio (limite do PHP do servidor).</div>
    </div>
    @if (! $items)
        <x-empty icon="cloud">Pasta vazia. Envie arquivos acima ou coloque direto no {{ $label }}.</x-empty>
    @else
        <ul class="list cloud-list">
            @foreach ($items as $item)
                <li>
                    <x-icon :name="$item['folder'] ? 'briefcase' : 'file-text'" style="flex: none; color: {{ $item['folder'] ? 'var(--accent)' : 'var(--faint)' }}" />
                    <div class="grow">
                        <a class="title" target="_blank" rel="noopener" href="{{ $item['url'] }}">{{ $item['name'] }}</a>
                        <div class="small faint">
                            {{ $item['folder'] ? 'Pasta' : ($item['size'] !== null ? $client->bytes($item['size']) : 'Arquivo') }}@if ($item['modified']) · {{ $item['modified']->format('d/m/Y H:i') }}@endif
                        </div>
                    </div>
                    @unless ($item['folder'])
                        <form method="post" action="{{ route('cloud.files.trash', $params + ['file' => $item['id']]) }}" data-confirm="Mover &quot;{{ $item['name'] }}&quot; para a lixeira do {{ $label }}?">@csrf @method('delete')<button class="btn btn-xs btn-ghost" title="Mover para a lixeira"><x-icon name="trash" class="sm" /></button></form>
                    @endunless
                </li>
            @endforeach
        </ul>
    @endif
@endif
