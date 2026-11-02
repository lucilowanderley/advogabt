@php
    $who = match ($m->sender) { 'cliente' => null, 'bot' => 'Robô', 'sistema' => 'Aviso automático', default => $m->user?->name ?? 'Equipe' };
@endphp
<div class="wa-msg {{ $m->isInbound() ? 'in' : 'out' }} {{ $m->sender }} {{ $m->status === 'erro' ? 'failed' : '' }}" data-id="{{ $m->id }}">
    <div class="wa-bubble">
        @if ($who)<div class="wa-who">{{ $who }}</div>@endif
        @if ($m->type !== 'text')
            <div class="wa-media">
                <x-icon :name="$m->type === 'image' ? 'file-text' : ($m->type === 'audio' ? 'message' : 'file-text')" class="sm" />
                @if ($m->media_path)
                    <a href="{{ route('whatsapp.media', $m) }}">{{ $m->media_name ?: 'Arquivo' }}</a>
                @else
                    <span>{{ ['document' => 'Documento', 'image' => 'Imagem', 'audio' => 'Áudio', 'video' => 'Vídeo'][$m->type] ?? 'Mensagem não suportada' }}{{ $m->media_name ? ': '.$m->media_name : '' }}</span>
                @endif
            </div>
        @endif
        @if ($m->body)<div class="pre">{{ $m->body }}</div>@endif
        <div class="wa-time">{{ $m->created_at->format('d/m H:i') }}@if ($m->status === 'erro') · <span class="text-red" title="{{ $m->error }}">não enviada</span>@endif</div>
        @if ($m->status === 'erro' && $m->error)<div class="small text-red">{{ \Illuminate\Support\Str::limit($m->error, 200) }}</div>@endif
        @if ($m->isInbound() && $m->error)<div class="small faint">{{ $m->error }}</div>@endif
    </div>
</div>
