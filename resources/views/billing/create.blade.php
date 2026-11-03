@extends('layouts.app', ['title' => 'Gerar cobrança'])
@php
    use App\Models\BillingProvider;
    use App\Support\Format;
    $payer = $entry->contact;
    $doc = preg_replace('/\D+/', '', (string) $payer?->document);
    $hasDoc = in_array(strlen($doc), [11, 14], true);
    $hasAddress = $payer && filled($payer->address) && filled($payer->city) && filled($payer->state) && strlen(preg_replace('/\D+/', '', (string) $payer->zip_code)) === 8;
    $default = $providers->first();
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('billing.index') }}">Cobranças</a> / Gerar cobrança</div>
<div class="page-head">
    <div>
        <h1>Gerar cobrança</h1>
        <div class="sub">Boleto e Pix para o lançamento "{{ $entry->description }}".</div>
    </div>
</div>

@if ($entry->type !== 'receita' || $entry->paid_at)
    <div class="alert error small"><x-icon name="alert" /> <div>Só dá para cobrar receitas em aberto.</div></div>
@elseif (! $payer)
    <div class="alert error small"><x-icon name="alert" /> <div>O lançamento não tem cliente. <a class="link" href="{{ route('finance.edit', $entry) }}">Edite o lançamento</a> e escolha quem paga.</div></div>
@endif

<div class="grid grid-main">
<form method="post" action="{{ route('billing.store') }}" class="card" data-billing-form>
    @csrf
    <input type="hidden" name="financial_entry_id" value="{{ $entry->id }}">
    <div class="card-body form-grid">
        <x-field name="provider" label="Gerar pelo" col="6">
            <select name="provider" id="f-provider" data-billing-provider>
                @foreach ($providers as $p)
                    <option value="{{ $p->driver }}" data-methods="{{ implode(',', array_keys($p->methods())) }}" @selected(old('provider', $default->driver) === $p->driver)>{{ $p->label }}{{ $p->isSandbox() ? ' (testes)' : '' }}</option>
                @endforeach
            </select>
        </x-field>
        <x-field name="method" label="Forma de pagamento" col="6">
            <select name="method" id="f-method" data-billing-method>
                @foreach (BillingProvider::METHODS as $k => $l)<option value="{{ $k }}" @selected(old('method', 'boleto_pix') === $k)>{{ $l }}</option>@endforeach
            </select>
        </x-field>
        <x-field name="due_date" label="Vencimento" col="6" :hint="$entry->due_date->lt(today()) ? 'O lançamento venceu em '.$entry->due_date->format('d/m/Y').'; escolha uma nova data.' : null">
            <input type="date" name="due_date" id="f-due_date" value="{{ old('due_date', $due->format('Y-m-d')) }}" min="{{ today()->format('Y-m-d') }}" required>
        </x-field>
        <div class="field col-6"><label>Valor</label><div class="strong" style="padding: 8px 0; font-size: 18px">{{ Format::money($entry->amount) }}</div></div>
        <x-field name="description" label="Descrição que o cliente vê" col="12">
            <input type="text" name="description" id="f-description" maxlength="255" value="{{ old('description', $entry->description) }}">
        </x-field>
        <div class="col-12 small muted">Multa e juros por atraso seguem o que está configurado na conta. O valor e o cliente vêm do lançamento; para mudar, <a class="link" href="{{ route('finance.edit', $entry) }}">edite o lançamento</a>.</div>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ url()->previous() }}">Voltar</a>
            <span class="spacer"></span>
            <button class="btn btn-primary" @disabled(! $payer || $entry->paid_at || $entry->type !== 'receita')><x-icon name="dollar" class="sm" /> Gerar cobrança</button>
        </div>
    </div>
</form>

<div class="stack">
    <div class="card">
        <div class="card-head"><h3><x-icon name="user" /> Pagador</h3>@if ($payer)<a class="btn btn-xs btn-ghost" href="{{ route('contacts.edit', $payer) }}"><x-icon name="edit" class="sm" /> Editar</a>@endif</div>
        <div class="card-body small">
            @if ($payer)
                <div class="strong">{{ $payer->name }}</div>
                <div class="{{ $hasDoc ? 'muted' : 'text-red' }}">{{ $hasDoc ? ($payer->person_type === 'PJ' ? 'CNPJ ' : 'CPF ').$payer->document : 'Sem CPF/CNPJ: obrigatório para gerar o boleto.' }}</div>
                <div class="muted">{{ $payer->email ?: 'Sem e-mail' }}@if ($payer->whatsapp ?: $payer->phone) · {{ $payer->whatsapp ?: $payer->phone }}@endif</div>
                <div class="{{ $hasAddress ? 'muted' : 'text-orange' }}" style="margin-top: 6px">
                    @if ($hasAddress){{ $payer->address }}, {{ $payer->city }}/{{ $payer->state }} · CEP {{ $payer->zip_code }}@else Endereço incompleto: o Banco Inter exige endereço, cidade, UF e CEP (o Asaas não).@endif
                </div>
            @else
                <span class="faint">Sem cliente no lançamento.</span>
            @endif
        </div>
    </div>
    @if ($entry->charges->isNotEmpty())
        <div class="card">
            <div class="card-head"><h3><x-icon name="history" /> Cobranças anteriores</h3></div>
            <div class="card-body flush">
                <ul class="list">
                    @foreach ($entry->charges as $c)
                        <li><div class="grow"><a href="{{ route('billing.show', $c) }}">#{{ $c->id }} · {{ $c->provider_label }}</a><div class="small faint">venc. {{ $c->due_date->format('d/m/Y') }}</div></div><span class="badge {{ $c->status_badge }}">{{ $c->status_label }}</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
</div>
@endsection
