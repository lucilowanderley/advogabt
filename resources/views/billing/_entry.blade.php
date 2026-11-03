{{-- Cobrança e NFS-e na tela do lançamento (Financeiro). --}}
@php
    $charges = $entry->charges()->get();
    $invoices = $entry->invoices()->get();
    $canCharge = \App\Models\BillingProvider::usable()->isNotEmpty();
    $nfse = \App\Models\BillingProvider::nfse();
    $openCharge = $charges->first(fn ($c) => $c->isOpen());
    $activeInvoice = $invoices->first(fn ($i) => in_array($i->status, ['processando', 'emitida'], true));
@endphp
@if ($canCharge || $nfse || $charges->isNotEmpty() || $invoices->isNotEmpty())
<div class="card" style="margin-top: 16px">
    <div class="card-head">
        <h3><x-icon name="dollar" /> Cobrança e nota fiscal</h3>
        <div class="row" style="gap: 6px">
            @if ($canCharge && ! $entry->paid_at && ! $openCharge)
                <a class="btn btn-sm btn-primary" href="{{ route('billing.create', ['lancamento' => $entry->id]) }}"><x-icon name="dollar" class="sm" /> Gerar boleto/Pix</a>
            @endif
            @if ($nfse && ! $activeInvoice)
                <form method="post" action="{{ route('billing.invoices.store') }}" data-confirm="Emitir a NFS-e deste lançamento agora?">@csrf<input type="hidden" name="financial_entry_id" value="{{ $entry->id }}"><button class="btn btn-sm"><x-icon name="file-text" class="sm" /> Emitir NFS-e</button></form>
            @endif
        </div>
    </div>
    <div class="card-body flush">
        @if ($charges->isEmpty() && $invoices->isEmpty())
            <x-empty icon="dollar">{{ $entry->contact_id ? 'Nenhuma cobrança gerada para este lançamento.' : 'Escolha o cliente acima para poder gerar boleto/Pix.' }}</x-empty>
        @else
            <ul class="list">
                @foreach ($charges as $c)
                    <li>
                        <div class="grow"><a class="strong" href="{{ route('billing.show', $c) }}">Cobrança #{{ $c->id }} · {{ $c->provider_label }}</a><div class="small faint">{{ $c->method_label }} · venc. {{ $c->due_date->format('d/m/Y') }}@if ($c->paid_at) · pago em {{ $c->paid_at->format('d/m/Y') }}@endif</div></div>
                        <span class="badge {{ $c->status_badge }}">{{ $c->status_label }}</span>
                    </li>
                @endforeach
                @foreach ($invoices as $inv)
                    <li>
                        <div class="grow"><strong>NFS-e {{ $inv->number ? 'nº '.$inv->number : '#'.$inv->id }}</strong><div class="small faint">{{ ($inv->issued_at ?? $inv->created_at)->format('d/m/Y') }}@if ($inv->last_error) · <span class="text-red">{{ $inv->last_error }}</span>@endif</div></div>
                        @if ($inv->pdf_url)<a class="btn btn-xs" target="_blank" rel="noopener" href="{{ $inv->pdf_url }}">PDF</a>@endif
                        <span class="badge {{ $inv->status_badge }}">{{ $inv->status_label }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endif
