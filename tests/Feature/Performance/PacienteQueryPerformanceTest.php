<?php

use App\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->performanceDatabase = 'sislac_t_perf_'.Str::lower(Str::random(10));
    pacientePerformanceControlConnection()->exec('CREATE DATABASE "'.$this->performanceDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Performance',
        'code' => 'perf-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->performanceDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->performanceTenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($this->performanceTenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    seedPacientePerformanceRows($this->performanceDatabase, 1000);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    pacientePerformanceControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->performanceDatabase.'" WITH (FORCE)');
});

function pacientePerformanceControlConnection(?string $database = null): PDO
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

function seedPacientePerformanceRows(string $database, int $count): void
{
    $pdo = pacientePerformanceControlConnection($database);
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO pacientes (nome, cpf, status, friendly_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)
    SQL);

    for ($index = 1; $index <= $count; $index++) {
        $timestamp = sprintf('2026-09-%02d 12:%02d:%02d+00', (($index - 1) % 7) + 1, $index % 60, $index % 60);
        $name = $index % 20 === 0 ? sprintf('Paciente Alvo %04d', $index) : sprintf('Paciente Controle %04d', $index);

        $statement->execute([
            $name,
            sprintf('%011d', 10000000000 + $index),
            $index % 3 === 0 ? 'Inativo' : 'Ativo',
            sprintf('PAC-%06d', $index),
            $timestamp,
            $timestamp,
        ]);
    }

    $pdo->exec('ANALYZE pacientes');
}

/** @param array<string, mixed> $plan */
function pacientePlanUsesIndex(array $plan, string $indexName): bool
{
    if (($plan['Index Name'] ?? null) === $indexName) {
        return true;
    }

    foreach (($plan['Plans'] ?? []) as $child) {
        if (is_array($child) && pacientePlanUsesIndex($child, $indexName)) {
            return true;
        }
    }

    return false;
}

it('permite ao planner usar o índice composto da paginação keyset', function () {
    $pdo = pacientePerformanceControlConnection($this->performanceDatabase);
    $pdo->exec('SET enable_seqscan = off');

    $statement = $pdo->query(<<<'SQL'
        EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
        SELECT id, updated_at
        FROM pacientes
        WHERE updated_at < '2026-09-05 12:45:45+00'
           OR (updated_at = '2026-09-05 12:45:45+00' AND id < 500)
        ORDER BY updated_at DESC, id DESC
        LIMIT 51
    SQL);

    $raw = $statement?->fetchColumn();
    $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    $plan = $decoded[0]['Plan'];

    expect(pacientePlanUsesIndex($plan, 'idx_pacientes_cursor'))->toBeTrue()
        ->and($plan['Actual Rows'])->toBeLessThanOrEqual(51);
});

it('permite ao planner usar trigram na busca case insensitive por nome', function () {
    $pdo = pacientePerformanceControlConnection($this->performanceDatabase);
    $pdo->exec('SET enable_seqscan = off');

    $statement = $pdo->query(<<<'SQL'
        EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
        SELECT id, nome
        FROM pacientes
        WHERE LOWER(nome) LIKE LOWER('%Alvo%')
        ORDER BY updated_at DESC, id DESC
        LIMIT 51
    SQL);

    $raw = $statement?->fetchColumn();
    $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    $plan = $decoded[0]['Plan'];

    Assert::assertTrue(
        pacientePlanUsesIndex($plan, 'idx_pacientes_nome_trgm'),
        json_encode($plan, JSON_THROW_ON_ERROR),
    );

    expect($plan['Actual Rows'])->toBeLessThanOrEqual(51);
});
