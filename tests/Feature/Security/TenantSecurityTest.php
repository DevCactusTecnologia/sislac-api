<?php

use App\Platform\Models\Tenant;

it('não aceita status nem database_name por mass assignment', function () {
    $tenant = new Tenant;

    $tenant->fill([
        'name' => 'Laboratório Seguro',
        'code' => 'lab-seguro',
        'status' => 'active',
        'database_name' => 'sislac_t_injetado',
    ]);

    expect($tenant->name)->toBe('Laboratório Seguro')
        ->and($tenant->code)->toBe('lab-seguro')
        ->and($tenant->getAttribute('status'))->toBeNull()
        ->and($tenant->getAttribute('database_name'))->toBeNull();
});
