<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('cria o schema central mínimo da plataforma', function () {
    $schema = Schema::connection('central');

    foreach ([
        'users',
        'tenants',
        'plans',
        'memberships',
        'subscriptions',
        'provisioning_runs',
        'platform_audit',
    ] as $table) {
        expect($schema->hasTable($table))->toBeTrue("Tabela central ausente: {$table}");
    }
});

it('usa uuid nas identidades interoperáveis com o Supabase', function () {
    $columns = DB::connection('central')->table('information_schema.columns')
        ->select(['table_name', 'data_type'])
        ->where('table_schema', 'public')
        ->where('column_name', 'id')
        ->whereIn('table_name', ['users', 'tenants'])
        ->pluck('data_type', 'table_name');

    expect($columns->get('users'))->toBe('uuid')
        ->and($columns->get('tenants'))->toBe('uuid');
});
