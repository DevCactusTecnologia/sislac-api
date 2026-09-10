<?php

use App\Platform\Models\Tenant;
use App\Platform\Provisioning\PostgresDatabaseAdmin;
use App\Platform\Provisioning\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisionedDatabases = [];

    $central = config('database.connections.central');

    config([
        'provisioning.database.host' => $central['host'],
        'provisioning.database.port' => (string) $central['port'],
        'provisioning.database.username' => $central['username'],
        'provisioning.database.password' => $central['password'],
        'provisioning.database.maintenance_database' => 'postgres',
        'provisioning.database.application_role' => $central['username'],
    ]);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');

    foreach ($this->provisionedDatabases as $database) {
        provisioningControlConnection()->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }
});

function provisioningControlConnection(?string $database = null): PDO
{
    $config = config('provisioning.database');
    $database ??= (string) $config['maintenance_database'];

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $database),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function provisioningTenant(string $databaseName): Tenant
{
    $id = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Laboratório de provisionamento',
        'code' => 'prov-'.Str::lower(Str::random(8)),
        'status' => 'provisioning',
        'database_name' => $databaseName,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Tenant::query()->findOrFail($id);
}

it('ativa o tenant somente depois de criar banco migrar e executar smoke check', function () {
    $database = 'sislac_t_prov_'.Str::lower(Str::random(12));
    $this->provisionedDatabases[] = $database;
    $tenant = provisioningTenant($database);

    app(TenantProvisioner::class)->provision($tenant);

    expect($tenant->fresh()->status)->toBe('active')
        ->and(app(PostgresDatabaseAdmin::class)->databaseExists($database))->toBeTrue();

    $run = DB::connection('central')->table('provisioning_runs')
        ->where('tenant_id', $tenant->getKey())
        ->latest('id')
        ->first();

    expect($run?->status)->toBe('succeeded')
        ->and($run?->schema_version)->toBe('2026_09_09_000500_add_financeiro_core')
        ->and($run?->finished_at)->not->toBeNull();

    expect(DB::connection('central')->table('platform_audit')
        ->where('subject_id', $tenant->getKey())
        ->where('action', 'tenant.provisioned')
        ->exists())->toBeTrue();

    $tenantDb = provisioningControlConnection($database);

    foreach ([
        'protocolo_sequence',
        'atendimentos',
        'atendimento_exames',
        'atendimento_pagamentos',
        'atendimento_audit',
        'lab_config',
    ] as $table) {
        $statement = $tenantDb->prepare('SELECT to_regclass(?)');
        $statement->execute(['public.'.$table]);
        expect($statement->fetchColumn())->toBe($table);
    }

    expect($tenantDb->query('SELECT rotina_fluxo_modo FROM lab_config WHERE singleton_key = 1')?->fetchColumn())
        ->toBe('completo');

    $created = $tenantDb->query(<<<'SQL'
        INSERT INTO atendimentos (paciente_nome, paciente_cpf)
        VALUES ('Paciente Provisionado', '12345678901')
        RETURNING id, protocolo
    SQL)?->fetch(PDO::FETCH_ASSOC);

    expect($created)->toBeArray()
        ->and($created['protocolo'] ?? null)->toBeString()->toMatch('/^\d{7}$/');

    $update = $tenantDb->prepare('UPDATE atendimentos SET protocolo = ? WHERE id = ?');

    expect(fn () => $update->execute(['9999999', $created['id'] ?? 0]))
        ->toThrow(PDOException::class, 'protocolo do atendimento é imutável');
});

it('permite retry sem criar um segundo banco para o mesmo tenant', function () {
    $database = 'sislac_t_retry_'.Str::lower(Str::random(12));
    $this->provisionedDatabases[] = $database;
    $tenant = provisioningTenant($database);
    $provisioner = app(TenantProvisioner::class);

    $provisioner->provision($tenant);
    $provisioner->provision($tenant->fresh());

    $statement = provisioningControlConnection()->prepare(
        'select count(*) from pg_database where datname = ?',
    );
    $statement->execute([$database]);

    expect((int) $statement->fetchColumn())->toBe(1)
        ->and(DB::connection('central')->table('provisioning_runs')
            ->where('tenant_id', $tenant->getKey())
            ->where('status', 'succeeded')
            ->count())->toBe(2)
        ->and($tenant->fresh()->status)->toBe('active');
});

it('registra falha e nunca ativa tenant quando o provisionamento falha', function () {
    $database = 'sislac_t_fail_'.Str::lower(Str::random(12));
    $this->provisionedDatabases[] = $database;
    $tenant = provisioningTenant($database);
    $password = (string) config('provisioning.database.password');

    config(['provisioning.database.application_role' => 'papel-invalido']);

    expect(fn () => app(TenantProvisioner::class)->provision($tenant))
        ->toThrow(InvalidArgumentException::class, 'papel PostgreSQL inválido.');

    expect($tenant->fresh()->status)->toBe('provisioning_failed')
        ->and(app(PostgresDatabaseAdmin::class)->databaseExists($database))->toBeFalse();

    $run = DB::connection('central')->table('provisioning_runs')
        ->where('tenant_id', $tenant->getKey())
        ->latest('id')
        ->first();

    expect($run?->status)->toBe('failed')
        ->and($run?->error_code)->toBe('InvalidArgumentException')
        ->and((string) $run?->error_message)->not->toContain($password);

    expect(DB::connection('central')->table('platform_audit')
        ->where('subject_id', $tenant->getKey())
        ->where('action', 'tenant.provisioning_failed')
        ->exists())->toBeTrue();
});

it('recusa nome de banco arbitrário antes de executar DDL', function () {
    $tenant = provisioningTenant('tenant;drop_database');

    expect(fn () => app(TenantProvisioner::class)->provision($tenant))
        ->toThrow(InvalidArgumentException::class, 'Nome de banco de tenant inválido.');

    expect($tenant->fresh()->status)->toBe('provisioning_failed');
});
