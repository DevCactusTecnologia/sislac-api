<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rotinaConfigDatabase = 'sislac_t_rotcfg_'.Str::lower(Str::random(9));
    rotinaConfigControlConnection()->exec('CREATE DATABASE "'.$this->rotinaConfigDatabase.'"');

    $laboratoryId = (string) Str::uuid();
    $now = now();

    createTestLaboratory([
        'id' => $laboratoryId,
        'name' => 'Laboratório Config Rotina',
        'code' => 'rotcfg-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->rotinaConfigDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $laboratory = Laboratory::query()->findOrFail($laboratoryId);
    connectTestLaboratory($laboratory);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    disconnectTestLaboratory();

    $this->rotinaConfigUser = User::factory()->create();
    assignTestLaboratoryUser([
        'user_id' => $this->rotinaConfigUser->getKey(),
        'tenant_id' => $laboratoryId,
        'role' => 'admin',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->rotinaConfigLaboratoryId = $laboratoryId;

    Http::preventStrayRequests();
    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->actingAs($this->rotinaConfigUser->fresh(), 'web');
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
    rotinaConfigControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->rotinaConfigDatabase.'" WITH (FORCE)');
});

function rotinaConfigControlConnection(?string $database = null): PDO
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

function rotinaConfigCreateAtendimento(PDO $pdo): int
{
    $statement = $pdo->query("INSERT INTO atendimentos (paciente_nome, paciente_cpf) VALUES ('Paciente Config Rotina', '') RETURNING id");

    return (int) $statement?->fetchColumn();
}

function rotinaConfigCreateExame(PDO $pdo, int $atendimentoId, string $targetStatus = 'pendente', string $tipoProcesso = 'INTERNO'): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames (atendimento_id, nome_exame, status, tipo_processo)
        VALUES (?, ?, ?, ?)
        RETURNING id
    SQL);

    $initialStatus = $tipoProcesso === 'TERCEIRIZADO' ? $targetStatus : 'pendente';
    $statement->execute([
        $atendimentoId,
        'Exame '.Str::lower(Str::random(8)),
        $initialStatus,
        $tipoProcesso,
    ]);
    $id = (int) $statement->fetchColumn();

    if ($tipoProcesso === 'TERCEIRIZADO' || $targetStatus === 'pendente') {
        return $id;
    }

    $path = match ($targetStatus) {
        'coletado' => ['coletado'],
        'em_bancada' => ['coletado', 'em_bancada'],
        'analisado' => ['coletado', 'em_bancada', 'analisado'],
        'finalizado' => ['coletado', 'em_bancada', 'analisado', 'finalizado'],
        'cancelado' => ['cancelado'],
        default => throw new InvalidArgumentException('Status de fixture inválido.'),
    };

    $update = $pdo->prepare('UPDATE atendimento_exames SET status = ? WHERE id = ?');
    foreach ($path as $status) {
        $update->execute([$status, $id]);
    }

    return $id;
}

/** @return array<string, mixed> */
function rotinaConfigExame(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT status, tipo_processo, coletor, analista,
               data_coleta::text AS data_coleta, data_analise::text AS data_analise
          FROM atendimento_exames
         WHERE id = ?
    SQL);
    $statement->execute([$id]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

it('lê o modo completo padrão para usuário autenticado do tenant', function () {
    $this->getJson('/api/rotina/config')
        ->assertOk()
        ->assertJsonPath('data.rotina_fluxo_modo', 'completo');
});

it('exige configuracoes sistema para alterar o modo', function () {
    DB::connection('central')->table('users')
        ->where('id', $this->rotinaConfigUser->getKey())
        ->where('laboratory_id', $this->rotinaConfigLaboratoryId)
        ->update(['role' => 'recepcionista']);
    $this->actingAs($this->rotinaConfigUser->fresh(), 'web');

    $this->patchJson('/api/rotina/config', ['rotina_fluxo_modo' => 'coleta_resultado'])
        ->assertForbidden();
});

it('rejeita modo fora do vocabulário canônico', function () {
    $this->patchJson('/api/rotina/config', ['rotina_fluxo_modo' => 'atalho_invalido'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rotina_fluxo_modo');
});

it('normaliza coletado e bancada ao encurtar para coleta resultado sem tocar pendente ou terminais', function () {
    $pdo = rotinaConfigControlConnection($this->rotinaConfigDatabase);
    $atendimentoId = rotinaConfigCreateAtendimento($pdo);

    $pendente = rotinaConfigCreateExame($pdo, $atendimentoId, 'pendente');
    $coletado = rotinaConfigCreateExame($pdo, $atendimentoId, 'coletado');
    $bancada = rotinaConfigCreateExame($pdo, $atendimentoId, 'em_bancada');
    $finalizado = rotinaConfigCreateExame($pdo, $atendimentoId, 'finalizado');
    $cancelado = rotinaConfigCreateExame($pdo, $atendimentoId, 'cancelado');
    $terceirizado = rotinaConfigCreateExame($pdo, $atendimentoId, 'digitado', 'TERCEIRIZADO');

    $this->patchJson('/api/rotina/config', ['rotina_fluxo_modo' => 'coleta_resultado'])
        ->assertOk()
        ->assertJsonPath('data.rotina_fluxo_modo', 'coleta_resultado');

    expect(rotinaConfigExame($pdo, $pendente)['status'] ?? null)->toBe('pendente')
        ->and(rotinaConfigExame($pdo, $coletado)['status'] ?? null)->toBe('analisado')
        ->and(rotinaConfigExame($pdo, $bancada)['status'] ?? null)->toBe('analisado')
        ->and(rotinaConfigExame($pdo, $finalizado)['status'] ?? null)->toBe('finalizado')
        ->and(rotinaConfigExame($pdo, $cancelado)['status'] ?? null)->toBe('cancelado')
        ->and(rotinaConfigExame($pdo, $terceirizado)['status'] ?? null)->toBe('digitado');

    foreach ([$coletado, $bancada] as $id) {
        $row = rotinaConfigExame($pdo, $id);
        expect($row['data_analise'] ?? null)->not->toBeNull()
            ->and($row['analista'] ?? null)->toBe('__SEM_REGISTRO__');
    }
});

it('normaliza etapas desativadas ao mudar para apenas resultado preservando rastreabilidade real existente', function () {
    $pdo = rotinaConfigControlConnection($this->rotinaConfigDatabase);
    $atendimentoId = rotinaConfigCreateAtendimento($pdo);

    $pendente = rotinaConfigCreateExame($pdo, $atendimentoId, 'pendente');
    $coletado = rotinaConfigCreateExame($pdo, $atendimentoId, 'coletado');
    $bancada = rotinaConfigCreateExame($pdo, $atendimentoId, 'em_bancada');

    $pdo->prepare("UPDATE atendimento_exames SET coletor = 'Coletor Real', data_coleta = '2026-09-09 10:00:00+00' WHERE id = ?")
        ->execute([$coletado]);

    $this->patchJson('/api/rotina/config', ['rotina_fluxo_modo' => 'apenas_resultado'])
        ->assertOk()
        ->assertJsonPath('data.rotina_fluxo_modo', 'apenas_resultado');

    foreach ([$pendente, $coletado, $bancada] as $id) {
        $row = rotinaConfigExame($pdo, $id);
        expect($row['status'] ?? null)->toBe('analisado')
            ->and($row['data_coleta'] ?? null)->not->toBeNull()
            ->and($row['data_analise'] ?? null)->not->toBeNull()
            ->and($row['analista'] ?? null)->toBe('__SEM_REGISTRO__');
    }

    $coletadoRow = rotinaConfigExame($pdo, $coletado);
    expect($coletadoRow['coletor'] ?? null)->toBe('Coletor Real')
        ->and($coletadoRow['data_coleta'] ?? null)->toStartWith('2026-09-09 10:00:00');

    expect(rotinaConfigExame($pdo, $pendente)['coletor'] ?? null)->toBe('__SEM_REGISTRO__')
        ->and(rotinaConfigExame($pdo, $bancada)['coletor'] ?? null)->toBe('__SEM_REGISTRO__');
});

it('não regride exame analisado ao alongar o fluxo novamente', function () {
    $pdo = rotinaConfigControlConnection($this->rotinaConfigDatabase);

    $this->patchJson('/api/rotina/config', ['rotina_fluxo_modo' => 'apenas_resultado'])->assertOk();
    $id = rotinaConfigCreateExame($pdo, rotinaConfigCreateAtendimento($pdo));

    expect(rotinaConfigExame($pdo, $id)['status'] ?? null)->toBe('analisado');

    $this->patchJson('/api/rotina/config', ['rotina_fluxo_modo' => 'completo'])
        ->assertOk()
        ->assertJsonPath('data.rotina_fluxo_modo', 'completo');

    expect(rotinaConfigExame($pdo, $id)['status'] ?? null)->toBe('analisado');
});

it('faz rollback da configuração quando a normalização falha', function () {
    $pdo = rotinaConfigControlConnection($this->rotinaConfigDatabase);
    $atendimentoId = rotinaConfigCreateAtendimento($pdo);
    rotinaConfigCreateExame($pdo, $atendimentoId, 'coletado');

    $pdo->exec(<<<'SQL'
        CREATE FUNCTION test_block_rotina_normalization()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            IF OLD.status IN ('coletado', 'em_bancada') AND NEW.status = 'analisado' THEN
                RAISE EXCEPTION 'falha de normalização simulada' USING ERRCODE = '23514';
            END IF;
            RETURN NEW;
        END;
        $$;

        CREATE TRIGGER zzz_test_block_rotina_normalization
        BEFORE UPDATE ON atendimento_exames
        FOR EACH ROW EXECUTE FUNCTION test_block_rotina_normalization();
    SQL);

    $this->withoutExceptionHandling();

    expect(fn () => $this->patchJson('/api/rotina/config', ['rotina_fluxo_modo' => 'coleta_resultado']))
        ->toThrow(QueryException::class);

    expect($pdo->query('SELECT rotina_fluxo_modo FROM lab_config WHERE singleton_key = 1')?->fetchColumn())
        ->toBe('completo');
});
