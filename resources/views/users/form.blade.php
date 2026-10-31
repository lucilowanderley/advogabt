@extends('layouts.app', ['title' => $user->exists ? 'Editar usuário' : 'Novo usuário'])
@php use App\Support\Options; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('users.index') }}">Usuários</a> / {{ $user->exists ? $user->name : 'Novo' }}</div>
<div class="page-head"><h1>{{ $user->exists ? 'Editar usuário' : 'Novo usuário' }}</h1></div>

<form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card" style="max-width: 860px">
    @csrf
    @if ($user->exists) @method('put') @endif
    <div class="card-body form-grid">
        <x-field name="name" label="Nome *" col="6"><input type="text" name="name" id="f-name" value="{{ old('name', $user->name) }}" required></x-field>
        <x-field name="email" label="E-mail *" col="6"><input type="email" name="email" id="f-email" value="{{ old('email', $user->email) }}" required></x-field>
        <x-field name="role" label="Perfil" col="4"><x-select name="role" :options="Options::USER_ROLES" :value="$user->role" /></x-field>
        <x-field name="oab" label="OAB" col="4"><input type="text" name="oab" id="f-oab" value="{{ old('oab', $user->oab) }}"></x-field>
        <x-field name="phone" label="Telefone" col="4"><input type="tel" name="phone" id="f-phone" data-mask="phone" value="{{ old('phone', $user->phone) }}"></x-field>
        <x-field name="password" label="{{ $user->exists ? 'Nova senha' : 'Senha *' }}" col="4" :hint="$user->exists ? 'Deixe em branco para manter.' : 'Mínimo de 8 caracteres.'"><input type="password" name="password" id="f-password" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}></x-field>
        <x-field name="password_confirmation" label="Confirmar senha" col="4"><input type="password" name="password_confirmation" autocomplete="new-password"></x-field>
        <x-field name="color" label="Cor do avatar" col="2"><input type="color" name="color" id="f-color" value="{{ old('color', $user->color) }}"></x-field>
        <x-field name="active" col="2"><label>&nbsp;</label><label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $user->active))> Ativo</label></x-field>
        <div class="col-12 form-actions">
            <a class="btn btn-ghost" href="{{ route('users.index') }}">Cancelar</a>
            <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
        </div>
    </div>
</form>
@endsection
