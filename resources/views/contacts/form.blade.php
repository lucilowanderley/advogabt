@extends('layouts.app', ['title' => $contact->exists ? 'Editar contato' : 'Novo contato'])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('contacts.index') }}">Contatos</a> / {{ $contact->exists ? $contact->name : 'Novo' }}</div>
<div class="page-head"><h1>{{ $contact->exists ? 'Editar contato' : 'Novo contato' }}</h1></div>

<form method="post" action="{{ $contact->exists ? route('contacts.update', $contact) : route('contacts.store') }}" class="card">
    @csrf
    @if ($contact->exists) @method('put') @endif
    <div class="card-body form-grid">
        <div class="form-section">Identificação</div>
        <x-field name="name" label="Nome / razão social *" col="6">
            <input type="text" name="name" id="f-name" value="{{ old('name', $contact->name) }}" required autofocus>
        </x-field>
        <x-field name="person_type" label="Tipo de pessoa" col="2">
            <x-select name="person_type" :options="['PF' => 'Física', 'PJ' => 'Jurídica']" :value="$contact->person_type" />
        </x-field>
        <x-field name="category" label="Categoria" col="2">
            <x-select name="category" :options="Options::CONTACT_CATEGORIES" :value="$contact->category" />
        </x-field>
        <x-field name="crm_stage" label="Etapa no CRM" col="2">
            <x-select name="crm_stage" :options="Options::CRM_STAGES" :value="$contact->crm_stage" />
        </x-field>
        <x-field name="document" label="CPF / CNPJ" col="3">
            <input type="text" name="document" id="f-document" data-mask="doc" value="{{ old('document', $contact->document) }}">
        </x-field>
        <x-field name="rg" label="RG / Inscrição" col="3">
            <input type="text" name="rg" id="f-rg" value="{{ old('rg', $contact->rg) }}">
        </x-field>
        <x-field name="birth_date" label="Nascimento / fundação" col="3">
            <input type="date" name="birth_date" id="f-birth_date" value="{{ old('birth_date', $contact->birth_date?->format('Y-m-d')) }}">
        </x-field>
        <x-field name="nationality" label="Nacionalidade" col="3">
            <input type="text" name="nationality" id="f-nationality" value="{{ old('nationality', $contact->nationality) }}">
        </x-field>
        <x-field name="marital_status" label="Estado civil" col="3">
            <x-select name="marital_status" :options="Options::MARITAL_STATUS" :value="$contact->marital_status" placeholder="—" />
        </x-field>
        <x-field name="profession" label="Profissão" col="3">
            <input type="text" name="profession" id="f-profession" value="{{ old('profession', $contact->profession) }}">
        </x-field>
        <x-field name="origin" label="Como nos conheceu" col="3">
            <x-select name="origin" :options="Options::CONTACT_ORIGINS" :value="$contact->origin" placeholder="—" />
        </x-field>
        <x-field name="user_id" label="Responsável" col="3">
            <x-select name="user_id" :options="$users->pluck('name', 'id')" :value="$contact->user_id" placeholder="—" />
        </x-field>

        <div class="form-section">Contato</div>
        <x-field name="email" label="E-mail" col="4">
            <input type="email" name="email" id="f-email" value="{{ old('email', $contact->email) }}">
        </x-field>
        <x-field name="phone" label="Telefone" col="4">
            <input type="tel" name="phone" id="f-phone" data-mask="phone" value="{{ old('phone', $contact->phone) }}">
        </x-field>
        <x-field name="whatsapp" label="WhatsApp" col="4">
            <input type="tel" name="whatsapp" id="f-whatsapp" data-mask="phone" value="{{ old('whatsapp', $contact->whatsapp) }}">
        </x-field>

        <div class="form-section">Endereço</div>
        <x-field name="zip_code" label="CEP" col="2" hint="Preenche o endereço automaticamente.">
            <input type="text" name="zip_code" id="f-zip_code" data-mask="cep" data-cep-lookup value="{{ old('zip_code', $contact->zip_code) }}">
        </x-field>
        <x-field name="address" label="Endereço" col="5">
            <input type="text" name="address" id="f-address" value="{{ old('address', $contact->address) }}">
        </x-field>
        <x-field name="city" label="Cidade" col="3">
            <input type="text" name="city" id="f-city" value="{{ old('city', $contact->city) }}">
        </x-field>
        <x-field name="state" label="UF" col="2">
            <x-select name="state" :options="array_combine(Options::UFS, Options::UFS)" :value="$contact->state" placeholder="—" />
        </x-field>

        <x-field name="notes" label="Observações" col="12">
            <textarea name="notes" id="f-notes">{{ old('notes', $contact->notes) }}</textarea>
        </x-field>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ $contact->exists ? route('contacts.show', $contact) : route('contacts.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
