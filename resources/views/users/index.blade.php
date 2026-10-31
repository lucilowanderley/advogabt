@extends('layouts.app', ['title' => 'Usuários'])

@section('content')
<div class="page-head">
    <div><h1>Usuários</h1><div class="sub">Equipe com acesso ao sistema.</div></div>
    <a class="btn btn-primary" href="{{ route('users.create') }}"><x-icon name="plus" class="sm" /> Novo usuário</a>
</div>
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>OAB</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td><div class="row"><x-avatar :user="$u" /> <span class="title">{{ $u->name }}</span></div></td>
                    <td>{{ $u->email }}</td>
                    <td><span class="badge {{ $u->isAdmin() ? 'gold' : '' }}">{{ $u->role_label }}</span></td>
                    <td>{{ $u->oab ?: '—' }}</td>
                    <td><span class="badge {{ $u->active ? 'green' : 'red' }}">{{ $u->active ? 'Ativo' : 'Inativo' }}</span></td>
                    <td><div class="actions">
                        <a class="btn btn-xs btn-ghost" href="{{ route('users.edit', $u) }}"><x-icon name="edit" class="sm" /></a>
                        @unless ($u->is(auth()->user()))
                            <form method="post" action="{{ route('users.destroy', $u) }}" data-confirm="Excluir este usuário?">@csrf @method('delete')<button class="btn btn-xs btn-ghost"><x-icon name="trash" class="sm" /></button></form>
                        @endunless
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
