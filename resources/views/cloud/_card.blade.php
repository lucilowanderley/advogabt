{{-- Pasta na nuvem do processo/cliente. O conteúdo é carregado depois da página (cloud.files.panel). --}}
@php $cloudStorage = \App\Models\CloudStorage::connected(); @endphp
@if ($cloudStorage)
    <div class="card" id="arquivos">
        <div class="card-head">
            <h3><x-icon name="cloud" /> Arquivos</h3>
            <span class="badge">{{ $cloudStorage->driver_label }}</span>
        </div>
        <div data-cloud-panel="{{ route('cloud.files.panel', ['type' => $type, 'id' => $owner->id]) }}">
            <div class="empty"><x-icon name="refresh" /><div>Carregando os arquivos do {{ $cloudStorage->driver_label }}…</div></div>
        </div>
    </div>
@endif
