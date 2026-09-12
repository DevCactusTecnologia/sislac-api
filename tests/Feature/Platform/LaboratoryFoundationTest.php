<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('mantém o vínculo operacional direto entre usuário e laboratório', function () {
    $schema = Schema::connection('central');

    expect($schema->hasTable('laboratories'))->toBeTrue()
        ->and($schema->hasColumns('laboratories', [
            'id',
            'name',
            'code',
            'status',
            'database_url',
        ]))->toBeTrue()
        ->and($schema->hasColumns('users', [
            'laboratory_id',
            'role',
            'status',
            'permissions_extra',
            'permissions_revoked',
        ]))->toBeTrue();
});
