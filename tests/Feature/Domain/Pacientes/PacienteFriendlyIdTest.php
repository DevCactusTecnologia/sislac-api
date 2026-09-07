<?php

use App\Domain\Pacientes\Models\Paciente;
use App\Domain\Pacientes\Services\PacienteFriendlyId;
use App\Platform\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->friendlyDatabase = 'sislac_t_friendly_'.Str::lower(Str::random(10));
    pacienteFriendlyControlConnection()->exec('CREATE DATABASE "'.$this->friendlyDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Friendly ID',
        'code' => 'friendly-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->friendlyDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->friendlyTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->friendlyTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    pacienteFriendlyControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->friendlyDatabase.'" WITH (FORCE)');
});

function pacienteFriendlyControlConnection(?string $database = null): PDO
{
    $config = config('database.connections.central');
    $database ??= 'postgres';

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $database),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

it('gera friendly ids sequenciais no formato canônico', function () {
    $generator = app(PacienteFriendlyId::class);

    expect($generator->next())->toBe('PAC-000001')
        ->and($generator->next())->toBe('PAC-000002');
});

it('mantém o contador atômico entre conexões independentes', function () {
    $pdoA = pacienteFriendlyControlConnection($this->friendlyDatabase);
    $pdoB = pacienteFriendlyControlConnection($this->friendlyDatabase);

    $sql = <<<'SQL'
        INSERT INTO friendly_id_counters (scope, next_value)
        VALUES ('paciente', 2)
        ON CONFLICT (scope)
        DO UPDATE SET next_value = friendly_id_counters.next_value + 1
        RETURNING next_value - 1 AS value
    SQL;

    $first = (int) $pdoA->query($sql)->fetchColumn();
    $second = (int) $pdoB->query($sql)->fetchColumn();

    expect([$first, $second])->toBe([1, 2]);
});

it('impede alteração do friendly id depois de persistido', function () {
    $paciente = new Paciente(['nome' => 'Paciente Imutável']);
    $paciente->forceFill(['friendly_id' => 'PAC-000001']);
    $paciente->save();

    expect(function () use ($paciente): void {
        $paciente->forceFill(['friendly_id' => 'PAC-999999'])->save();
    })->toThrow(QueryException::class, 'friendly_id de paciente é imutável');
});
