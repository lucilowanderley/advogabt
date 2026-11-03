{{-- Documentos enviados para assinatura de um processo ou cliente. --}}
@php
    $sigList = \App\Models\SignatureRequest::with('signers')
        ->where($type === 'processo' ? 'legal_case_id' : 'contact_id', $owner->id)->latest('id')->limit(8)->get();
@endphp
<div class="card" id="assinaturas">
    <div class="card-head">
        <h3><x-icon name="pen" /> Assinaturas</h3>
        <a class="btn btn-sm btn-ghost" title="Enviar documento para assinatura" href="{{ route('signatures.create', $type === 'processo' ? ['legal_case_id' => $owner->id] : ['contact_id' => $owner->id]) }}"><x-icon name="plus" class="sm" /></a>
    </div>
    <div class="card-body flush">
        @if ($sigList->isEmpty())
            <x-empty icon="pen">Nenhum documento enviado para assinatura.</x-empty>
        @else
            <ul class="list">
                @foreach ($sigList as $sr)
                    <li>
                        <div class="grow">
                            <a class="title" href="{{ route('signatures.show', $sr) }}">{{ $sr->title }}</a>
                            <div class="small muted">{{ $sr->provider_label }} · {{ $sr->signers->where('status', 'assinado')->count() }}/{{ $sr->signers->count() }} assinaram · {{ ($sr->sent_at ?? $sr->created_at)->format('d/m/Y') }}</div>
                        </div>
                        @if ($sr->signed_path)<a class="btn btn-xs btn-ghost" title="Baixar o PDF assinado" href="{{ route('signatures.download', [$sr, 'assinado']) }}"><x-icon name="download" class="sm" /></a>@endif
                        <span class="badge {{ $sr->status_badge }}">{{ $sr->status_label }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
