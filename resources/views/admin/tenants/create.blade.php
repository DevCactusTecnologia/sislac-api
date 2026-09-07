@extends('admin.layout')

@section('title', 'Novo laboratório')

@section('content')
    <h1>Novo laboratório</h1>

    <form method="POST" action="{{ route('admin.tenants.store') }}">
        @csrf

        <div>
            <label for="name">Nome</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255">
            @error('name')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="code">Identificador</label>
            <input id="code" name="code" type="text" value="{{ old('code') }}" required maxlength="255">
            @error('code')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <button type="submit">Criar laboratório</button>
    </form>
@endsection
