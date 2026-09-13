<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rotinaQueuesDatabaseA = 'sislac_t_rotqa_'.Str::lower(Str::random(8));
    $this->rotinaQueuesDatabaseB = 'sislac_t_rotqb_'.Str::lower(Str::random(8));

    $control = rotinaQueuesControlConnection();
    $control->exec('CREATE DATABASE "'.$this->rotinaQueuesDatabaseA.'"');
    $control->exec('CREATE DATABASE "'.$this->rotinaQueuesDatabaseB.'"');

    $now = now();
    $this->rotinaQueuesLaboratoryA = (string) Str::uuid();
    $this->rotinaQueuesLaboratoryB = (string) Str::uuid();

    foreach ([
        [
            'id' => $this->rotinaQueuesLaboratoryA,
            'name' => 'Laboratório Fila A',
            'code' => 'rotqa-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'database_name' => $this->rotinaQueuesDatabaseA,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $this->rotinaQueuesLaboratoryB,
            'name' => 'Laboratório Fila B',
            'code' => 'rotqb-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'database_name' => $this->rotinaQueuesDatabaseB,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ] as $attributes) {
        createTestLaboratory($attributes);
    }

    foreach ([$this->rotinaQueuesLaboratoryA, $this->rotinaQueuesLaboratoryB] as $laboratoryId) {
        connectTestLaboratory(Laboratory::query()->findOrFail($laboratoryId));
        Artisan::call('migrate', [
            '--path' => database_path('migrations/tenant'),
            '--realpath' => true,
            '--force' => true,
        ]);
        disconnectTestLaboratory();
    }

    $this->rotinaQueuesUser = User::factory()->create(['name' => 'Usuário Filas']);

    $this->rotinaQueuesUser->forceFill([
        'laboratory_id' => $this->rotinaQueuesLaboratoryA,
        'role' => 'recepcionista',
        'status' => 'active',
    ])->save();
    $this->rotinaQueuesUserB = User::factory()->create([
        'laboratory_id' => $this->rotinaQueuesLaboratoryB,
        'role' => 'recepcionista',
        'status' => 'active',
    ]);

    Http::preventStrayRequests();
    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->actingAs($this->rotinaQueuesUser->fresh(), 'web');
});

afterEach(function () {
    disconnectTestLaboratory();

    DB::purge('lab');
    $control = rotinaQueuesControlConnection();
    $control->exec('DROP DATABASE IF EXISTS "'.$this->rotinaQueuesDatabaseA.'" WITH (FORCE)');
    $control->exec('DROP DATABASE IF EXISTS "'.$this->rotinaQueuesDatabaseB.'" WITH (FORCE)');
});

function rotinaQueuesControlConnection(?string $database = null): PDO
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

function rotinaQueuesSetMode(PDO $pdo, string $mode): void
{
    $statement = $pdo->prepare('UPDATE lab_config SET rotina_fluxo_modo = ? WHERE singleton_key = 1');
    $statement->execute([$mode]);
}

/** @return array{id:int,atendimento_id:int,protocolo:string} */
function rotinaQueuesCreateExame(
    PDO $pdo,
    string $patient,
    string $status = 'pendente',
    string $process = 'INTERNO',
    string $date = '2026-09-09 12:00:00+00',
): array {
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimentos (data, paciente_nome, paciente_cpf)
        VALUES (?, ?, '')
        RETURNING id, protocolo
    SQL);
    $statement->execute([$date, $patient]);
    $atendimento = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $atendimentoId = (int) ($atendimento['id'] ?? 0);

    if ($process === 'TERCEIRIZADO') {
        $insert = $pdo->prepare(<<<'SQL'
            INSERT INTO atendimento_exames (
                atendimento_id, nome_exame, status, tipo_processo, valor, valor_original, ordem, amostra_seq
            ) VALUES (?, ?, 'digitado', 'TERCEIRIZADO', 50, 50, 1, 1)
            RETURNING id
        SQL);
        $insert->execute([$atendimentoId, 'Exame Terceirizado']);
    } else {
        $insert = $pdo->prepare(<<<'SQL'
            INSERT INTO atendimento_exames (
                atendimento_id, nome_exame, status, tipo_processo, valor, valor_original, ordem, amostra_seq
            ) VALUES (?, ?, 'pendente', 'INTERNO', 50, 50, 1, 1)
            RETURNING id
        SQL);
        $insert->execute([$atendimentoId, 'Exame '.$patient]);
    }

    $id = (int) $insert->fetchColumn();

    if ($process === 'INTERNO') {
        $path = match ($status) {
            'pendente' => [],
            'coletado' => ['coletado'],
            'em_bancada' => ['coletado', 'em_bancada'],
            'analisado' => ['coletado', 'em_bancada', 'analisado'],
            'finalizado' => ['coletado', 'em_bancada', 'analisado', 'finalizado'],
            'cancelado' => ['cancelado'],
            default => throw new InvalidArgumentException('Status de fila inválido.'),
        };

        $update = $pdo->prepare('UPDATE atendimento_exames SET status = ? WHERE id = ?');
        foreach ($path as $nextStatus) {
            $update->execute([$nextStatus, $id]);
        }
    }

    return [
        'id' => $id,
        'atendimento_id' => $atendimentoId,
        'protocolo' => (string) ($atendimento['protocolo'] ?? ''),
    ];
}

it('deriva filas do modo completo e exclui terminais e terceirizados', function () {
    $pdo = rotinaQueuesControlConnection($this->rotinaQueuesDatabaseA);

    $pending = rotinaQueuesCreateExame($pdo, 'Paciente Coleta', 'pendente', 'INTERNO', '2026-09-09 14:00:00+00');
    $collected = rotinaQueuesCreateExame($pdo, 'Paciente Coletado', 'coletado', 'INTERNO', '2026-09-09 13:00:00+00');
    $bench = rotinaQueuesCreateExame($pdo, 'Paciente Bancada', 'em_bancada', 'INTERNO', '2026-09-09 12:00:00+00');
    rotinaQueuesCreateExame($pdo, 'Paciente Analisado', 'analisado');
    rotinaQueuesCreateExame($pdo, 'Paciente Finalizado', 'finalizado');
    rotinaQueuesCreateExame($pdo, 'Paciente Cancelado', 'cancelado');
    rotinaQueuesCreateExame($pdo, 'Paciente Apoio', 'digitado', 'TERCEIRIZADO');

    $this->getJson('/api/rotina/coleta')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending['id'])
        ->assertJsonPath('data.0.paciente_nome', 'Paciente Coleta')
        ->assertJsonMissingPath('data.0.resultados');

    $this->getJson('/api/rotina/analise')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $collected['id'])
        ->assertJsonPath('data.0.status', 'coletado')
        ->assertJsonPath('data.1.id', $bench['id'])
        ->assertJsonPath('data.1.status', 'em_bancada')
        ->assertJsonMissingPath('data.0.resultados');
});

it('mantém apenas coleta habilitada no modo coleta resultado', function () {
    $pdo = rotinaQueuesControlConnection($this->rotinaQueuesDatabaseA);
    rotinaQueuesSetMode($pdo, 'coleta_resultado');
    rotinaQueuesCreateExame($pdo, 'Paciente Coleta Resultado');

    $this->getJson('/api/rotina/coleta')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/rotina/analise')
        ->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertExactJson(['enabled' => false, 'data' => []]);
});

it('não materializa coleta nem análise no modo apenas resultado', function () {
    $pdo = rotinaQueuesControlConnection($this->rotinaQueuesDatabaseA);
    rotinaQueuesSetMode($pdo, 'apenas_resultado');
    rotinaQueuesCreateExame($pdo, 'Paciente Apenas Resultado');

    $this->getJson('/api/rotina/coleta')
        ->assertOk()
        ->assertExactJson(['enabled' => false, 'data' => []]);

    $this->getJson('/api/rotina/analise')
        ->assertOk()
        ->assertExactJson(['enabled' => false, 'data' => []]);
});

it('isola filas entre usuários de laboratórios diferentes', function () {
    $pdoA = rotinaQueuesControlConnection($this->rotinaQueuesDatabaseA);
    $pdoB = rotinaQueuesControlConnection($this->rotinaQueuesDatabaseB);
    rotinaQueuesCreateExame($pdoA, 'Paciente Tenant A');
    rotinaQueuesCreateExame($pdoB, 'Paciente Tenant B');

    $this->withHeader('X-Tenant', $this->rotinaQueuesLaboratoryA)
        ->getJson('/api/rotina/coleta')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.paciente_nome', 'Paciente Tenant A');

    $this->actingAs($this->rotinaQueuesUserB, 'web')
        ->getJson('/api/rotina/coleta')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.paciente_nome', 'Paciente Tenant B');
});

it('exige visualizar atendimentos nas duas filas', function () {
    DB::connection('central')->table('users')
        ->where('id', $this->rotinaQueuesUser->getKey())
        ->where('laboratory_id', $this->rotinaQueuesLaboratoryA)
        ->update(['permissions_revoked' => json_encode(['visualizar_atendimentos'])]);
    $this->actingAs($this->rotinaQueuesUser->fresh(), 'web');

    $this->getJson('/api/rotina/coleta')->assertForbidden();
    $this->getJson('/api/rotina/analise')->assertForbidden();
});
