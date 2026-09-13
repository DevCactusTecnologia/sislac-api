<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use App\Support\LaboratoryDatabase;
use Illuminate\Support\Facades\DB;

/** @param array<string, mixed> $attributes */
function createTestLaboratory(array $attributes): Laboratory
{
    $config = config('database.connections.central');
    $database = $attributes['database_name'];
    unset($attributes['database_name']);
    $attributes['database_url'] = sprintf(
        'postgresql://%s:%s@%s:%s/%s',
        rawurlencode((string) $config['username']),
        rawurlencode((string) $config['password']),
        $config['host'],
        $config['port'],
        $database,
    );

    $laboratory = new Laboratory;
    $laboratory->forceFill($attributes)->save();

    return $laboratory;
}

function connectTestLaboratory(Laboratory $laboratory): void
{
    app(LaboratoryDatabase::class)->connect($laboratory);
    DB::setDefaultConnection('lab');
}

function disconnectTestLaboratory(): void
{
    app(LaboratoryDatabase::class)->disconnect();
    DB::setDefaultConnection('central');
}

/** @param array<string, mixed> $attributes */
function assignTestLaboratoryUser(array $attributes): void
{
    $user = User::query()->findOrFail($attributes['user_id']);
    $user->forceFill([
        'laboratory_id' => $attributes['tenant_id'],
        'role' => $attributes['role'],
        'status' => $attributes['status'],
        'permissions_extra' => json_decode($attributes['permissions_extra'] ?? '[]', true, flags: JSON_THROW_ON_ERROR),
        'permissions_revoked' => json_decode($attributes['permissions_revoked'] ?? '[]', true, flags: JSON_THROW_ON_ERROR),
    ])->save();
}

function testLaboratoryControlPdo(): PDO
{
    $config = config('database.connections.central');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}
