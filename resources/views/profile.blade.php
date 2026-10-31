@extends('layouts.app', ['title' => 'Meu perfil'])

@section('content')
<div class="page-head"><div><h1>Meu perfil</h1><div class="sub">{{ $user->role_label }}</div></div></div>

<form method="post" action="{{ route('profile.update') }}" class="card" style="max-width: 860px">
    @csrf @method('put')
    <div class="card-body form-grid">
        <x-field name="name" label="Nome *" col="6"><input type="text" name="name" id="f-name" value="{{ old('name', $user->name) }}" required></x-field>
        <x-field name="email" label="E-mail *" col="6"><input type="email" name="email" id="f-email" value="{{ old('email', $user->email) }}" required></x-field>
        <x-field name="oab" label="OAB" col="4"><input type="text" name="oab" id="f-oab" value="{{ old('oab', $user->oab) }}"></x-field>
        <x-field name="phone" label="Telefone" col="4"><input type="tel" name="phone" id="f-phone" data-mask="phone" value="{{ old('phone', $user->phone) }}"></x-field>
        <x-field name="color" label="Cor do avatar" col="4"><input type="color" name="color" id="f-color" value="{{ old('color', $user->color) }}"></x-field>
        <div class="form-section">Alterar senha</div>
        <x-field name="current_password" label="Senha atual" col="4"><input type="password" name="current_password" id="f-current_password" autocomplete="current-password"></x-field>
        <x-field name="password" label="Nova senha" col="4"><input type="password" name="password" id="f-password" autocomplete="new-password"></x-field>
        <x-field name="password_confirmation" label="Confirmar nova senha" col="4"><input type="password" name="password_confirmation" autocomplete="new-password"></x-field>
        <div class="col-12 form-actions"><button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button></div>
    </div>
</form>
@endsection
