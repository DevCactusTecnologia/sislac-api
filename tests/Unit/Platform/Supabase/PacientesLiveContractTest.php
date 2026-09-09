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
