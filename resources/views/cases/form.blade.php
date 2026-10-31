@extends('layouts.app', ['title' => $case->exists ? 'Editar processo' : 'Novo processo'])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('cases.index') }}">Processos</a> / {{ $case->exists ? $case->title : 'Novo' }}</div>
<div class="page-head"><h1>{{ $case->exists ? 'Editar processo' : 'Novo processo' }}</h1></div>

<form method="post" action="{{ $case->exists ? route('cases.update', $case) : route('cases.store') }}" class="card">
    @csrf
    @if ($case->exists) @method('put') @endif
    <div class="card-body form-grid">
        <div class="form-section">Dados do processo</div>
        <x-field name="title" label="Título / assunto *" col="8">
            <input type="text" name="title" id="f-title" value="{{ old('title', $case->title) }}" required autofocus placeholder="Ex.: Ação de cobrança — Contrato 123">
        </x-field>
        <x-field name="number" label="Número (CNJ)" col="4">
            <input type="text" name="number" id="f-number" value="{{ old('number', $case->number) }}" placeholder="0000000-00.0000.0.00.0000">
        </x-field>
        <x-field name="area" label="Área" col="3">
            <x-select name="area" :options="Options::CASE_AREAS" :value="$case->area" />
        </x-field>
        <x-field name="action_type" label="Tipo de ação" col="3">
            <input type="text" name="action_type" id="f-action_type" value="{{ old('action_type', $case->action_type) }}">
        </x-field>
        <x-field name="phase" label="Fase" col="3">
            <x-select name="phase" :options="Options::CASE_PHASES" :value="$case->phase" />
        </x-field>
        <x-field name="status" label="Situação" col="3">
            <x-select name="status" :options="Options::CASE_STATUS" :value="$case->status" />
        </x-field>

        <div class="form-section">Partes</div>
        <x-field name="contact_id" label="Cliente" col="5">
            <x-select name="contact_id" :options="$contacts->pluck('name', 'id')" :value="$case->contact_id" placeholder="— Selecione —" />
            <div class="hint"><a class="link" href="{{ route('contacts.create') }}" target="_blank">+ Cadastrar novo cliente</a></div>
        </x-field>
        <x-field name="client_role" label="Polo do cliente" col="3">
            <x-select name="client_role" :options="Options::CLIENT_ROLES" :value="$case->client_role" />
        </x-field>
        <x-field name="opposing_party" label="Parte contrária" col="4">
            <input type="text" name="opposing_party" id="f-opposing_party" value="{{ old('opposing_party', $case->opposing_party) }}">
        </x-field>

        <div class="form-section">Órgão julgador</div>
        <x-field name="court" label="Tribunal" col="4">
            <input type="text" name="court" id="f-court" value="{{ old('court', $case->court) }}" placeholder="Ex.: TJSP, TRT-2, TRF-3">
        </x-field>
        <x-field name="court_division" label="Vara / órgão" col="4">
            <input type="text" name="court_division" id="f-court_division" value="{{ old('court_division', $case->court_division) }}">
        </x-field>
        <x-field name="district" label="Comarca" col="4">
            <input type="text" name="district" id="f-district" value="{{ old('district', $case->district) }}">
        </x-field>

        <div class="form-section">Valores e responsáveis</div>
        <x-field name="claim_value" label="Valor da causa (R$)" col="3">
            <input type="text" name="claim_value" id="f-claim_value" value="{{ old('claim_value', $case->claim_value !== null ? number_format((float) $case->claim_value, 2, ',', '.') : '') }}" placeholder="0,00">
        </x-field>
        <x-field name="fee_percent" label="Honorários de êxito (%)" col="3">
            <input type="number" step="0.01" min="0" max="100" name="fee_percent" id="f-fee_percent" value="{{ old('fee_percent', $case->fee_percent) }}">
        </x-field>
        <x-field name="distributed_at" label="Data de distribuição" col="3">
            <input type="date" name="distributed_at" id="f-distributed_at" value="{{ old('distributed_at', $case->distributed_at?->format('Y-m-d')) }}">
        </x-field>
        <x-field name="responsible_id" label="Advogado responsável" col="3">
            <x-select name="responsible_id" :options="$users->pluck('name', 'id')" :value="$case->responsible_id" placeholder="—" />
        </x-field>
        <x-field name="partner_id" label="Parceiro (correspondente / indicador)" col="6">
            <x-select name="partner_id" :options="$partners->pluck('name', 'id')" :value="$case->partner_id" placeholder="— Nenhum —" />
        </x-field>
        <x-field name="notes" label="Observações" col="12">
            <textarea name="notes" id="f-notes">{{ old('notes', $case->notes) }}</textarea>
        </x-field>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ $case->exists ? route('cases.show', $case) : route('cases.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
