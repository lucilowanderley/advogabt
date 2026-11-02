@if ($deliveries->isEmpty())
    <x-empty icon="history">Nenhum envio registrado.</x-empty>
@else
<div class="table-wrap">
    <table class="table">
        <thead><tr><th>Quando</th>@if ($showWebhook ?? false)<th>Webhook</th>@endif<th>Evento</th><th>Resultado</th><th>Tentativas</th><th></th></tr></thead>
        <tbody>
        @foreach ($deliveries as $d)
            <tr>
                <td class="small nowrap">{{ $d->created_at->format('d/m/Y H:i:s') }}</td>
                @if ($showWebhook ?? false)<td class="small">{{ $d->webhook?->name }}</td>@endif
                <td class="small"><code>{{ $d->event }}</code></td>
                <td class="small">
                    @if ($d->success)
                        <span class="badge green">HTTP {{ $d->response_status }}</span> <span class="faint">{{ $d->duration_ms }} ms</span>
                    @elseif ($d->attempts === 0)
                        <span class="badge orange">Na fila</span>
                    @else
                        <span class="badge red">{{ $d->response_status ? 'HTTP '.$d->response_status : 'Falhou' }}</span>
                        <div class="text-red" style="max-width: 360px">{{ \Illuminate\Support\Str::limit($d->error, 160) }}</div>
                    @endif
                </td>
                <td class="small">{{ $d->attempts }}</td>
                <td><div class="actions">
                    <details class="payload">
                        <summary class="btn btn-xs btn-ghost" title="Ver conteúdo enviado">JSON</summary>
                        <pre class="code-block">{{ json_encode($d->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        @if ($d->response_body)<div class="small faint">Resposta do destino:</div><pre class="code-block">{{ $d->response_body }}</pre>@endif
                    </details>
                    <form method="post" action="{{ route('integrations.deliveries.redeliver', $d) }}">@csrf<button class="btn btn-xs btn-ghost" title="Reenviar"><x-icon name="refresh" class="sm" /></button></form>
                </div></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
