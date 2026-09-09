<?php

use App\Platform\Supabase\PacientesLiveContract;

it('detecta coluna ausente no contrato live', function () {
    $checker = new PacientesLiveContract;

    $differences = $checker->compareColumns(
        [['name' => 'id', 'type' => 'bigint', 'nullable' => false]],
        [
            ['name' => 'id', 'type' => 'bigint', 'nullable' => false],
            ['name' => 'nome', 'type' => 'text', 'nullable' => false],
        ],
    );

    expect($differences)->toContain('coluna ausente: nome');
});

it('detecta tipo e nullability divergentes', function () {
    $checker = new PacientesLiveContract;

    $differences = $checker->compareColumns(
        [['name' => 'cpf', 'type' => 'varchar', 'nullable' => false]],
        [['name' => 'cpf', 'type' => 'text', 'nullable' => true]],
    );

    expect($differences)
        ->toContain('tipo divergente em cpf: varchar != text')
        ->toContain('nullability divergente em cpf');
});

it('detecta default divergente quando o contrato declara o default', function () {
    $checker = new PacientesLiveContract;

    $differences = $checker->compareColumns(
        [[
            'name' => 'status',
            'type' => 'text',
            'nullable' => false,
            'default' => "'Inativo'::text",
        ]],
        [[
            'name' => 'status',
            'type' => 'text',
            'nullable' => false,
            'default' => 'Ativo',
        ]],
    );

    expect($differences)->toContain('default divergente em status: Inativo != Ativo');
});

it('detecta policy adicional no mesmo comando', function () {
    $checker = new PacientesLiveContract;

    $differences = $checker->comparePolicies(
        [
            [
                'name' => 'pacientes_select',
                'command' => 'SELECT',
                'permissive' => 'PERMISSIVE',
                'roles' => ['authenticated'],
                'expression' => 'visualizar_pacientes',
            ],
            [
                'name' => 'pacientes_select_extra',
                'command' => 'SELECT',
                'permissive' => 'PERMISSIVE',
                'roles' => ['authenticated'],
                'expression' => 'true',
            ],
        ],
        [
            'SELECT' => [
                'name' => 'pacientes_select',
                'permission' => 'visualizar_pacientes',
                'role' => 'authenticated',
            ],
        ],
    );

    expect($differences)->toContain('policy SELECT: quantidade divergente (2 != 1)');
});
