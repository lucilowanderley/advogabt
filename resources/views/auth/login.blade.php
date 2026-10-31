<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    @include('layouts.head', ['title' => 'Entrar'])
</head>
<body>
<div class="auth">
    <section class="auth-hero">
        <div class="brand" style="padding: 0">
            <x-logo style="width: 44px; height: 44px" />
            <div>
                <div class="brand-name" style="font-size: 20px">ADVOGA<span>BT</span></div>
                <div class="brand-tag">Gestão jurídica</div>
            </div>
        </div>
        <div>
            <h1>O escritório inteiro em um só lugar.</h1>
            <p>Prazos, processos, clientes, agenda e financeiro organizados para a sua equipe trabalhar com foco no que importa.</p>
        </div>
        <div class="auth-features">
            <div><x-icon name="columns" /> Quadro de tarefas</div>
            <div><x-icon name="scale" /> Controle de processos</div>
            <div><x-icon name="funnel" /> CRM de clientes</div>
            <div><x-icon name="wallet" /> Financeiro</div>
            <div><x-icon name="calendar" /> Agenda e prazos</div>
            <div><x-icon name="file-text" /> Modelos de documentos</div>
        </div>
    </section>
    <section class="auth-form">
        <div class="box">
            <h2 style="font-size: 22px">Entrar</h2>
            <p class="muted" style="margin-top: 6px">Acesse com seu e-mail e senha.</p>
            <form method="post" action="{{ url('/login') }}" style="margin-top: 22px">
                @csrf
                <div class="field">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                    @error('email')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label class="check"><input type="checkbox" name="remember" value="1"> Manter conectado</label>
                </div>
                <button class="btn btn-primary" style="width: 100%; padding: 11px">Entrar</button>
            </form>
        </div>
    </section>
</div>
</body>
</html>
