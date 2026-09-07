@extends('admin.layout')

@section('title', 'Super Admin')

@section('content')
    <h1>Super Admin</h1>

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
@endsection
