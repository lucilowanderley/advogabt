{{-- Tribunais na ficha do processo: consulta ao Datajud e publicações do DJEN. --}}
@php
    $cnjValid = \App\Support\Courts\Cnj::isValid($case->number);
    $casePublications = $case->publications()->with('task')->limit(5)->get();
@endphp
<div class="card" id="tribunal">
    <div class="card-head">
        <h3><x-icon name="gavel" /> Tribunal</h3>
        @if ($cnjValid)<span class="badge">{{ \App\Support\Courts\Cnj::court($case->number) }}</span>@endif
    </div>
    <div class="card-body">
        @if (! $case->number)
            <div class="small muted">Cadastre o número CNJ do processo para o sistema buscar os andamentos no Datajud e vincular as publicações do DJEN.</div>
        @elseif (! $cnjValid)
            <div class="small muted">O número não está no padrão CNJ (NNNNNNN-DD.AAAA.J.TR.OOOO) ou o dígito verificador não confere, então não dá para consultar o Datajud.</div>
        @else
            <div class="small muted">
                @if (! $case->datajud_sync)
                    Consulta automática ao Datajud desligada para este processo.
                @elseif ($case->datajud_checked_at)
                    Datajud consultado {{ $case->datajud_checked_at->diffForHumans() }}.@if ($case->datajud_updated_at) Base do CNJ atualizada em {{ $case->datajud_updated_at->format('d/m/Y') }}.@endif
                @else
                    Ainda não consultado no Datajud.
                @endif
            </div>
            @if ($case->datajud_error)<div class="small text-red" style="margin-top: 4px">{{ $case->datajud_error }}</div>@endif
            <div class="row wrap" style="margin-top: 10px">
                <form method="post" action="{{ route('cases.court.refresh', $case) }}">@csrf<button class="btn btn-sm"><x-icon name="refresh" class="sm" /> Buscar andamentos</button></form>
                <form method="post" action="{{ route('cases.court.toggle', $case) }}">@csrf<button class="btn btn-sm btn-ghost">{{ $case->datajud_sync ? 'Parar de acompanhar' : 'Acompanhar automaticamente' }}</button></form>
            </div>
        @endif
    </div>
    @if ($casePublications->isNotEmpty())
        <div class="card-body flush" style="border-top: 1px solid var(--border)">
            <ul class="list">
                @foreach ($casePublications as $pub)
                    <li>
                        <div class="grow">
                            <a class="title" href="{{ route('publications.show', $pub) }}">{{ $pub->title }}</a>
                            <div class="small muted">{{ $pub->available_at->format('d/m/Y') }}@if ($pub->task) · prazo {{ $pub->task->due_at?->format('d/m/Y') }}@endif</div>
                        </div>
                        <span class="badge {{ \App\Models\CourtPublication::STATUS[$pub->status]['badge'] }}">{{ \App\Models\CourtPublication::STATUS[$pub->status]['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
