@extends('layouts.app', ['title' => $entry->exists ? 'Editar lançamento' : 'Novo lançamento'])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('finance.index') }}">Financeiro</a> / {{ $entry->exists ? 'Editar' : 'Novo' }}</div>
<div class="page-head">
    <h1>{{ $entry->exists ? 'Editar lançamento' : ($entry->type === 'despesa' ? 'Nova despesa' : 'Nova receita') }}</h1>
    @if ($entry->exists)
        <form method="post" action="{{ route('finance.destroy', $entry) }}" data-confirm="Excluir este lançamento?">@csrf @method('delete')<button class="btn btn-danger"><x-icon name="trash" class="sm" /> Excluir</button></form>
    @endif
</div>

<form method="post" action="{{ $entry->exists ? route('finance.update', $entry) : route('finance.store') }}" class="card">
    @csrf
    @if ($entry->exists) @method('put') @endif
    <input type="hidden" name="redirect" value="{{ old('redirect', url()->previous() !== url()->current() ? url()->previous() : '') }}">
    <div class="card-body form-grid">
        <x-field name="type" label="Tipo" col="3">
            <x-select name="type" :options="Options::FINANCE_TYPES" :value="$entry->type" />
        </x-field>
        <x-field name="description" label="Descrição *" col="9">
            <input type="text" name="description" id="f-description" value="{{ old('description', $entry->description) }}" required autofocus placeholder="Ex.: Honorários iniciais — Ação trabalhista">
        </x-field>
        <x-field name="amount" label="Valor (R$) *" col="3">
            <input type="text" name="amount" id="f-amount" value="{{ old('amount', $entry->amount !== null ? number_format((float) $entry->amount, 2, ',', '.') : '') }}" required placeholder="0,00">
        </x-field>
        <x-field name="due_date" label="Vencimento *" col="3">
            <input type="date" name="due_date" id="f-due_date" value="{{ old('due_date', $entry->due_date?->format('Y-m-d')) }}" required>
        </x-field>
        <x-field name="paid_at" label="Data do pagamento" col="3" hint="Deixe vazio se ainda está em aberto.">
            <input type="date" name="paid_at" id="f-paid_at" value="{{ old('paid_at', $entry->paid_at?->format('Y-m-d')) }}">
        </x-field>
        @unless ($entry->exists)
            <x-field name="installments" label="Parcelas" col="3" hint="Gera lançamentos mensais.">
                <input type="number" min="1" max="60" name="installments" id="f-installments" value="{{ old('installments', 1) }}">
            </x-field>
        @endunless
        <x-field name="category" label="Categoria" col="4">
            <x-select name="category" :options="Options::FINANCE_CATEGORIES" :value="$entry->category" placeholder="—" />
        </x-field>
        <x-field name="payment_method" label="Forma de pagamento" col="4">
            <x-select name="payment_method" :options="Options::PAYMENT_METHODS" :value="$entry->payment_method" placeholder="—" />
        </x-field>
        <x-field name="contact_id" label="Cliente / contato" col="4">
            <x-select name="contact_id" :options="$contacts->pluck('name', 'id')" :value="$entry->contact_id" placeholder="— Nenhum —" />
        </x-field>
        <x-field name="legal_case_id" label="Processo" col="6">
            <select name="legal_case_id" id="f-legal_case_id">
                <option value="">— Nenhum —</option>
                @foreach ($cases as $c)
                    <option value="{{ $c->id }}" @selected((string) old('legal_case_id', $entry->legal_case_id) === (string) $c->id)>{{ $c->number ? $c->number.' — ' : '' }}{{ $c->title }}</option>
                @endforeach
            </select>
        </x-field>
        <x-field name="partner_id" label="Parceiro" col="6">
            <x-select name="partner_id" :options="$partners->pluck('name', 'id')" :value="$entry->partner_id" placeholder="— Nenhum —" />
        </x-field>
        <x-field name="notes" label="Observações" col="12">
            <textarea name="notes" id="f-notes">{{ old('notes', $entry->notes) }}</textarea>
        </x-field>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ route('finance.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
