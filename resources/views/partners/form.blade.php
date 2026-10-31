@extends('layouts.app', ['title' => $partner->exists ? 'Editar parceiro' : 'Novo parceiro'])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('partners.index') }}">Parceiros</a> / {{ $partner->exists ? $partner->name : 'Novo' }}</div>
<div class="page-head"><h1>{{ $partner->exists ? 'Editar parceiro' : 'Novo parceiro' }}</h1></div>

<form method="post" action="{{ $partner->exists ? route('partners.update', $partner) : route('partners.store') }}" class="card">
    @csrf
    @if ($partner->exists) @method('put') @endif
    <div class="card-body form-grid">
        <x-field name="name" label="Nome *" col="6">
            <input type="text" name="name" id="f-name" value="{{ old('name', $partner->name) }}" required autofocus>
        </x-field>
        <x-field name="type" label="Tipo" col="3">
            <x-select name="type" :options="Options::PARTNER_TYPES" :value="$partner->type" />
        </x-field>
        <x-field name="oab" label="OAB" col="3">
            <input type="text" name="oab" id="f-oab" value="{{ old('oab', $partner->oab) }}">
        </x-field>
        <x-field name="document" label="CPF / CNPJ" col="3">
            <input type="text" name="document" id="f-document" data-mask="doc" value="{{ old('document', $partner->document) }}">
        </x-field>
        <x-field name="email" label="E-mail" col="3">
            <input type="email" name="email" id="f-email" value="{{ old('email', $partner->email) }}">
        </x-field>
        <x-field name="phone" label="Telefone" col="3">
            <input type="tel" name="phone" id="f-phone" data-mask="phone" value="{{ old('phone', $partner->phone) }}">
        </x-field>
        <x-field name="commission_percent" label="Comissão / repasse (%)" col="3">
            <input type="number" step="0.01" min="0" max="100" name="commission_percent" id="f-commission_percent" value="{{ old('commission_percent', $partner->commission_percent) }}">
        </x-field>
        <x-field name="city" label="Cidade" col="4">
            <input type="text" name="city" id="f-city" value="{{ old('city', $partner->city) }}">
        </x-field>
        <x-field name="state" label="UF" col="2">
            <x-select name="state" :options="array_combine(Options::UFS, Options::UFS)" :value="$partner->state" placeholder="—" />
        </x-field>
        <x-field name="pix_key" label="Chave PIX" col="4">
            <input type="text" name="pix_key" id="f-pix_key" value="{{ old('pix_key', $partner->pix_key) }}">
        </x-field>
        <x-field name="active" col="2">
            <label>&nbsp;</label>
            <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $partner->active))> Ativo</label>
        </x-field>
        <x-field name="notes" label="Observações" col="12">
            <textarea name="notes" id="f-notes">{{ old('notes', $partner->notes) }}</textarea>
        </x-field>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ route('partners.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
