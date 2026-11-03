@extends('layouts.app', ['title' => $charge->description.' · Cobrança'])
@php use App\Support\Format; $canManage = auth()->user()->isAdmin() || $charge->user_id === auth()->id(); @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('billing.index') }}">Cobranças</a> / #{{ $charge->id }}</div>
<div class="page-head">
    <div>
        <h1>{{ $charge->description }}</h1>
        <div class="row wrap" style="margin-top: 6px; gap: 6px">
            <span class="badge {{ $charge->status_badge }}">{{ $charge->status_label }}</span>
            <span class="badge">{{ $charge->provider_label }}</span>
            <span class="badge">{{ $charge->method_label }}</span>
            @if ($provider?->isSandbox())<span class="badge red" title="Cobranças do ambiente de testes não recebem dinheiro de verdade">ambiente de testes</span>@endif
        </div>
    </div>
    <div class="page-actions">
        @if ($charge->external_id && in_array($charge->status, ['pendente', 'vencido'], true))
            <form method="post" action="{{ route('billing.refresh', $charge) }}">@csrf<button class="btn"><x-icon name="refresh" class="sm" /> Atualizar situação</button></form>
        @endif
        @if ($canManage && ($charge->isOpen() || $charge->status === 'erro'))
            <form method="post" action="{{ route('billing.cancel', $charge) }}" data-confirm="Cancelar esta cobrança? O boleto/Pix deixa de valer e o lançamento continua em aberto.">@csrf<button class="btn btn-danger"><x-icon name="x" class="sm" /> Cancelar</button></form>
        @endif
        @if ($charge->status === 'erro' && $charge->entry && ! $charge->entry->paid_at)
            <a class="btn btn-primary" href="{{ route('billing.create', ['lancamento' => $charge->financial_entry_id]) }}"><x-icon name="refresh" class="sm" /> Tentar de novo</a>
        @endif
    </div>
</div>

@if ($charge->last_error)
    <div class="alert error small"><x-icon name="alert" /> <div>{{ $charge->last_error }}</div></div>
@endif

<div class="grid grid-main">
<div class="stack">
    @if ($charge->isOpen())
        <div class="card">
            <div class="card-head"><h3><x-icon name="dollar" /> Para o cliente pagar</h3></div>
            <div class="card-body">
                @if (! $charge->payment_url && ! $charge->digitable_line && ! $charge->pix_payload)
                    <p class="small muted" style="margin-top: 0">O banco ainda está registrando o boleto. Clique em <strong>Atualizar situação</strong> em alguns segundos (a rotina também busca sozinha).</p>
                @endif
                @if ($charge->pix_qr)
                    <div style="text-align: center; margin-bottom: 12px"><img src="data:image/png;base64,{{ $charge->pix_qr }}" alt="QR Code Pix" width="200" height="200" style="background: #fff; border-radius: 8px; padding: 6px"></div>
                @endif
                @if ($charge->pix_payload)
                    <div class="field"><label>Pix copia e cola</label><div class="copy-field"><code data-copy-source>{{ $charge->pix_payload }}</code><button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button></div></div>
                @endif
                @if ($charge->digitable_line)
                    <div class="field"><label>Linha digitável do boleto</label><div class="copy-field"><code data-copy-source>{{ $charge->digitable_line }}</code><button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button></div></div>
                @endif
                @if ($charge->payment_url)
                    <div class="field"><label>Link de pagamento</label><div class="copy-field"><code data-copy-source>{{ $charge->payment_url }}</code><button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button></div></div>
                @endif
                <div class="row wrap" style="gap: 8px; margin-top: 10px">
                    @if ($charge->whatsappUrl())<a class="btn btn-primary" target="_blank" rel="noopener" href="{{ $charge->whatsappUrl() }}"><x-icon name="whatsapp" class="sm" /> Enviar pelo WhatsApp</a>@endif
                    @if ($charge->contact?->email)<a class="btn" href="mailto:{{ $charge->contact->email }}?subject={{ rawurlencode('Cobrança: '.$charge->description) }}&body={{ rawurlencode($charge->shareText()) }}"><x-icon name="mail" class="sm" /> E-mail</a>@endif
                    @if ($charge->slip_url || ($provider?->info('pdf') && $charge->digitable_line))<a class="btn" target="_blank" rel="noopener" href="{{ route('billing.pdf', $charge) }}"><x-icon name="download" class="sm" /> PDF do boleto</a>@endif
                    @if ($charge->payment_url)<a class="btn btn-ghost" target="_blank" rel="noopener" href="{{ $charge->payment_url }}"><x-icon name="external" class="sm" /> Abrir fatura</a>@endif
                </div>
                @if ($charge->provider === 'asaas' && $provider?->notify_customer)
                    <div class="small faint" style="margin-top: 10px">O Asaas também avisa o cliente por e-mail/SMS (configurado na conta).</div>
                @endif
            </div>
        </div>
    @elseif ($charge->status === 'pago')
        <div class="card">
            <div class="card-head"><h3><x-icon name="check-check" /> Pagamento recebido</h3></div>
            <div class="card-body small">
                <p style="margin-top: 0"><strong>{{ Format::money($charge->paid_amount ?? $charge->amount) }}</strong> em {{ $charge->paid_at?->format('d/m/Y') }}@if ($charge->paid_with) por {{ ['pix' => 'Pix', 'boleto' => 'boleto', 'cartao' => 'cartão', 'outro' => 'outro meio'][$charge->paid_with] ?? $charge->paid_with }}@endif.</p>
                <p class="muted" style="margin-bottom: 0">O lançamento foi baixado automaticamente no Financeiro.</p>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h3><x-icon name="file-text" /> Nota fiscal (NFS-e)</h3></div>
        <div class="card-body small">
            @forelse ($charge->invoices as $inv)
                <div class="row between" style="margin-bottom: 8px">
                    <div>
                        <span class="badge {{ $inv->status_badge }}">{{ $inv->status_label }}</span>
                        @if ($inv->number) <strong>Nº {{ $inv->number }}</strong>@endif
                        @if ($inv->last_error)<div class="text-red">{{ $inv->last_error }}</div>@endif
                    </div>
                    <div class="row" style="gap: 6px">
                        @if ($inv->pdf_url)<a class="btn btn-xs" target="_blank" rel="noopener" href="{{ $inv->pdf_url }}">PDF</a>@endif
                        @if ($inv->xml_url)<a class="btn btn-xs btn-ghost" target="_blank" rel="noopener" href="{{ $inv->xml_url }}">XML</a>@endif
                        <a class="btn btn-xs btn-ghost" href="{{ route('billing.invoices') }}">Ver notas</a>
                    </div>
                </div>
            @empty
                @if ($nfse && $charge->entry && ! $charge->entry->invoices()->active()->exists())
                    <form method="post" action="{{ route('billing.invoices.store') }}" class="row between">
                        @csrf <input type="hidden" name="financial_entry_id" value="{{ $charge->financial_entry_id }}">
                        <span class="muted">{{ $nfse->setting('nfse_auto') ? 'Será emitida sozinha quando o pagamento for confirmado.' : 'Nenhuma nota emitida para esta cobrança.' }}</span>
                        <button class="btn btn-sm"><x-icon name="file-text" class="sm" /> Emitir NFS-e agora</button>
                    </form>
                @elseif ($nfse)
                    <span class="muted">O lançamento já tem nota fiscal. <a class="link" href="{{ route('billing.invoices') }}">Ver notas</a></span>
                @else
                    <span class="muted">Emissão de NFS-e não configurada.@can('admin') <a class="link" href="{{ route('billing.settings') }}">Configurar</a>@endcan</span>
                @endif
            @endforelse
        </div>
    </div>
</div>

<div class="stack">
    <div class="card">
        <div class="card-head"><h3><x-icon name="list" /> Dados</h3></div>
        <div class="card-body small">
            <dl class="dl" style="grid-template-columns: 110px 1fr">
                <dt>Valor</dt><dd class="strong">{{ Format::money($charge->amount) }}</dd>
                <dt>Vencimento</dt><dd>{{ $charge->due_date->format('d/m/Y') }}</dd>
                <dt>Cliente</dt><dd>@if ($charge->contact)<a class="link" href="{{ route('contacts.show', $charge->contact) }}">{{ $charge->contact->name }}</a>@else — @endif</dd>
                <dt>Lançamento</dt><dd>@if ($charge->entry)<a class="link" href="{{ route('finance.edit', $charge->entry) }}">{{ $charge->entry->description }}</a>@else excluído @endif</dd>
                @if ($charge->entry?->legalCase)<dt>Processo</dt><dd><a class="link" href="{{ route('cases.show', $charge->entry->legalCase) }}">{{ $charge->entry->legalCase->number ?: $charge->entry->legalCase->title }}</a></dd>@endif
                <dt>Gerada</dt><dd>{{ $charge->created_at->format('d/m/Y H:i') }}@if ($charge->user) por {{ $charge->user->name }}@endif</dd>
                @if ($charge->external_id)<dt>Código no {{ $charge->provider_label }}</dt><dd><code>{{ $charge->external_id }}</code></dd>@endif
                @if ($charge->bank_number && $charge->bank_number !== $charge->external_id)<dt>Nosso número</dt><dd><code>{{ $charge->bank_number }}</code></dd>@endif
                @if ($charge->last_checked_at)<dt>Última consulta</dt><dd>{{ $charge->last_checked_at->diffForHumans() }}@if ($charge->remote_status) ({{ $charge->remote_status }})@endif</dd>@endif
            </dl>
        </div>
    </div>
    @if ($charge->isOpen())
        <div class="card">
            <div class="card-body small muted">
                @if (\App\Support\Billing\Billing::isLocalUrl(url('/')))
                    Como o sistema está em um endereço local, o {{ $charge->provider_label }} não consegue avisar quando o cliente paga. O sistema consulta sozinho de tempos em tempos (de hora em hora perto do vencimento) e dá baixa no Financeiro; use <strong>Atualizar situação</strong> para ver na hora.
                @else
                    O {{ $charge->provider_label }} avisa o sistema quando o cliente paga, e a baixa no Financeiro é automática. Se demorar, use <strong>Atualizar situação</strong>.
                @endif
            </div>
        </div>
    @endif
</div>
</div>
@endsection
