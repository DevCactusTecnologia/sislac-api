@extends('admin.layout')

@section('title', 'Laboratórios')

@section('content')
    <h1>Laboratórios</h1>

    <p><a href="{{ route('admin.tenants.create') }}">Novo laboratório</a></p>

    @if (session('status'))
        <p>{{ session('status') }}</p>
    @endif

    @error('provisioning')
        <p>{{ $message }}</p>
    @enderror

    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>Identificador</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tenants as $tenant)
                <tr>
                    <td>{{ $tenant->name }}</td>
                    <td>{{ $tenant->code }}</td>
                    <td>{{ $tenant->status }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Nenhum laboratório cadastrado.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
