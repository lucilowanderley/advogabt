@extends('layouts.app', ['title' => 'Contatos'])
@php use App\Support\Options; @endphp

@section('content')
@include('contacts._tabs')

<form class="toolbar" method="get">
    <div class="search-box"><x-icon name="search" class="sm" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Nome, CPF/CNPJ, e-mail, telefone..."></div>
    <select name="categoria" data-autosubmit>
        <option value="">Todas as categorias</option>
        @foreach (Options::CONTACT_CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('categoria') === $k)>{{ $l }}</option>@endforeach
    </select>
    <button class="btn"><x-icon name="filter" class="sm" /> Filtrar</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nome</th><th>Categoria</th><th>Documento</th><th>Contato</th><th>Cidade</th><th class="right">Processos</th><th></th></tr></thead>
            <tbody>
            @forelse ($contacts as $contact)
                <tr>
                    <td>
                        <a class="title" href="{{ route('contacts.show', $contact) }}">{{ $contact->name }}</a>
                        <div class="small faint">{{ $contact->person_type === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física' }}</div>
                    </td>
                    <td>
                        <span class="badge {{ $contact->category === 'cliente' ? 'gold' : '' }}">{{ Options::label(Options::CONTACT_CATEGORIES, $contact->category) }}</span>
                        @if ($contact->crm_stage !== 'cliente' && $contact->category === 'cliente')<span class="badge blue">{{ Options::label(Options::CRM_STAGES, $contact->crm_stage) }}</span>@endif
                    </td>
                    <td class="nowrap">{{ $contact->document ?: '—' }}</td>
                    <td class="small">
                        {{ $contact->phone ?: '' }}
                        @if ($contact->email)<div class="faint">{{ $contact->email }}</div>@endif
                    </td>
                    <td>{{ $contact->city ? $contact->city.'/'.$contact->state : '—' }}</td>
                    <td class="right">{{ $contact->legal_cases_count }}</td>
                    <td><div class="actions">
                        @if ($contact->whatsapp_link)<a class="btn btn-xs btn-ghost" target="_blank" rel="noopener" href="{{ $contact->whatsapp_link }}" title="WhatsApp"><x-icon name="whatsapp" class="sm" /></a>@endif
                        <a class="btn btn-xs btn-ghost" href="{{ route('contacts.edit', $contact) }}" title="Editar"><x-icon name="edit" class="sm" /></a>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty icon="users">Nenhum contato encontrado.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $contacts->links() }}
</div>
@endsection
