@extends('admin.layout')

@section('title', 'Super Admin')

@section('content')
    <h1>Super Admin</h1>

    <p><a href="{{ route('admin.tenants.index') }}">Laboratórios</a></p>

    <dl>
        <div>
            <dt>Laboratórios</dt>
            <dd>{{ $totalTenants }}</dd>
        </div>
        <div>
            <dt>Ativos</dt>
            <dd>{{ $activeTenants }}</dd>
        </div>
    </dl>

    <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit">Sair</button>
    </form>
@endsection
