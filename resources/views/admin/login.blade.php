@extends('admin.layout')

@section('title', 'Acesso administrativo')

@section('content')
    <h1>Acesso administrativo</h1>
    <p>Entre com sua conta central do SISLAC.</p>

    <form method="POST" action="{{ route('admin.login.store') }}">
        @csrf

        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            @error('email')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <button type="submit">Entrar</button>
    </form>
@endsection
