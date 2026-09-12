<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

it('protege a conexão e resolve um laboratório por usuário operacional', function () {
    $databaseUrl = 'postgresql://sislac:secret@example.invalid:5432/lab';

    $laboratory = Laboratory::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Laboratório Teste',
        'code' => 'laboratorio-teste',
        'status' => 'active',
        'database_url' => $databaseUrl,
    ]);

    $storedDatabaseUrl = DB::connection('central')
        ->table('laboratories')
        ->where('id', $laboratory->getKey())
        ->value('database_url');

    $firstUser = User::factory()->create(['laboratory_id' => $laboratory->getKey()]);
    User::factory()->create(['laboratory_id' => $laboratory->getKey()]);

    expect($storedDatabaseUrl)->toBeString()->not->toBe($databaseUrl)
        ->and($laboratory->database_url)->toBe($databaseUrl)
        ->and($laboratory->toArray())->not->toHaveKey('database_url')
        ->and($firstUser->laboratory->is($laboratory))->toBeTrue()
        ->and($laboratory->users()->count())->toBe(2);
});
