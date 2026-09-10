<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rotinaTransitionDatabase = 'sislac_t_rottr_'.Str::lower(Str::random(9));
    rotinaTransitionControlConnection()->exec('CREATE DATABASE "'.$this->rotinaTransitionDatabase.'"');

    $tenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Laboratório Transição Rotina',
        'code' => 'rottr-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->rotinaTransitionDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $tenant = Tenant::query()->findOrFail($tenantId);
    tenancy()->initialize($tenant);

    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    tenancy()->end();

    $this->rotinaTransitionUser = User::factory()->create(['name' => 'Analista Rotina']);
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->rotinaTransitionUser->getKey(),
        'tenant_id' => $tenantId,
        'role' => 'admin',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->rotinaTransitionTenantId = $tenantId;

    Http::preventStrayRequests();
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $this->rotinaTransitionUser->id,
            'email' => $this->rotinaTransitionUser->email,
        ], 200),
    ]);

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withToken('valid-rotina-transition-token');
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    rotinaTransitionControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->rotinaTransitionDatabase.'" WITH (FORCE)');
});

function rotinaTransitionControlConnection(?string $database = null): PDO
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

function rotinaTransitionSetMode(PDO $pdo, string $mode): void
{
    $statement = $pdo->prepare('UPDATE lab_config SET rotina_fluxo_modo = ? WHERE singleton_key = 1');
    $statement->execute([$mode]);
}

function rotinaTransitionCreateExame(PDO $pdo, string $targetStatus = 'pendente'): int
{
    $atendimentoId = (int) $pdo->query(
        "INSERT INTO atendimentos (paciente_nome, paciente_cpf) VALUES ('Paciente Transição', '') RETURNING id",
    )?->fetchColumn();

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames (atendimento_id, nome_exame, status, tipo_processo, valor, valor_original)
        VALUES (?, ?, 'pendente', 'INTERNO', 50, 50)
        RETURNING id
    SQL);
    $statement->execute([$atendimentoId, 'Exame '.Str::lower(Str::random(8))]);
    $id = (int) $statement->fetchColumn();

    $path = match ($targetStatus) {
        'pendente' => [],
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
function rotinaTransitionExame(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT status, coletor, analista, data_coleta::text AS data_coleta,
               data_analise::text AS data_analise, valor::text AS valor
          FROM atendimento_exames
         WHERE id = ?
    SQL);
    $statement->execute([$id]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

it('coleta exame no modo completo com timestamp e responsável server-side', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $id = rotinaTransitionCreateExame($pdo);

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'coletar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'coletado');

    $row = rotinaTransitionExame($pdo, $id);
    expect($row['status'] ?? null)->toBe('coletado')
        ->and($row['data_coleta'] ?? null)->not->toBeNull()
        ->and($row['coletor'] ?? null)->toBe('Analista Rotina');
});

it('executa análise completa em duas intenções', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $id = rotinaTransitionCreateExame($pdo, 'coletado');

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'iniciar_analise'])
        ->assertOk()
        ->assertJsonPath('data.status', 'em_bancada');

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'finalizar_analise'])
        ->assertOk()
        ->assertJsonPath('data.status', 'analisado');

    $row = rotinaTransitionExame($pdo, $id);
    expect($row['data_analise'] ?? null)->not->toBeNull()
        ->and($row['analista'] ?? null)->toBe('Analista Rotina');
});

it('coletar no modo coleta resultado termina efetivamente analisado', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    rotinaTransitionSetMode($pdo, 'coleta_resultado');
    $id = rotinaTransitionCreateExame($pdo);

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'coletar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'analisado');

    $row = rotinaTransitionExame($pdo, $id);
    expect($row['data_coleta'] ?? null)->not->toBeNull()
        ->and($row['data_analise'] ?? null)->not->toBeNull()
        ->and($row['analista'] ?? null)->toBe('__SEM_REGISTRO__');
});

it('recoleta sem gerar nova cobrança e limpa o ciclo descartado', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $id = rotinaTransitionCreateExame($pdo, 'analisado');

    $pdo->prepare("UPDATE atendimento_exames SET coletor = 'Coletor Antigo', analista = 'Analista Antigo', data_coleta = now(), data_analise = now() WHERE id = ?")
        ->execute([$id]);

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'recoletar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pendente');

    $row = rotinaTransitionExame($pdo, $id);
    expect($row['data_coleta'] ?? null)->toBeNull()
        ->and($row['data_analise'] ?? null)->toBeNull()
        ->and($row['coletor'] ?? null)->toBe('')
        ->and($row['analista'] ?? null)->toBe('')
        ->and($row['valor'] ?? null)->toBe('50.00');
});

it('recoleta em apenas resultado não cria etapa desativada', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    rotinaTransitionSetMode($pdo, 'apenas_resultado');
    $id = rotinaTransitionCreateExame($pdo);

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'recoletar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'analisado');

    $row = rotinaTransitionExame($pdo, $id);
    expect($row['coletor'] ?? null)->toBe('__SEM_REGISTRO__')
        ->and($row['analista'] ?? null)->toBe('__SEM_REGISTRO__')
        ->and($row['data_coleta'] ?? null)->not->toBeNull()
        ->and($row['data_analise'] ?? null)->not->toBeNull();
});

it('exige motivo não vazio para cancelar e preserva timestamps clínicos', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $id = rotinaTransitionCreateExame($pdo, 'coletado');
    $before = rotinaTransitionExame($pdo, $id);

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', [
        'acao' => 'cancelar',
        'motivo' => '   ',
    ])->assertUnprocessable();

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', [
        'acao' => 'cancelar',
        'motivo' => 'Amostra imprópria',
    ])->assertOk()
        ->assertJsonPath('data.status', 'cancelado');

    $after = rotinaTransitionExame($pdo, $id);
    expect($after['data_coleta'] ?? null)->toBe($before['data_coleta'] ?? null);
});

it('não reabre exame finalizado por recoleta nesta onda', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $id = rotinaTransitionCreateExame($pdo, 'finalizado');

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'recoletar'])
        ->assertConflict();
});

it('retorna 404 para ocorrência inexistente', function () {
    $this->postJson('/api/rotina/exames/999999/transicao', ['acao' => 'coletar'])
        ->assertNotFound();
});

it('rejeita estado e timestamps enviados diretamente pelo cliente', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $id = rotinaTransitionCreateExame($pdo);

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', [
        'acao' => 'coletar',
        'status' => 'finalizado',
        'data_coleta' => '2020-01-01T00:00:00Z',
        'data_analise' => '2020-01-01T00:00:00Z',
    ])->assertUnprocessable();
});

it('aplica permissão específica para cada intenção', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->rotinaTransitionUser->getKey())
        ->where('tenant_id', $this->rotinaTransitionTenantId)
        ->update([
            'role' => 'financeiro',
            'permissions_extra' => json_encode(['registrar_coleta']),
        ]);

    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $coletaId = rotinaTransitionCreateExame($pdo);
    $analiseId = rotinaTransitionCreateExame($pdo, 'coletado');

    $this->postJson('/api/rotina/exames/'.$coletaId.'/transicao', ['acao' => 'coletar'])
        ->assertOk();

    $this->postJson('/api/rotina/exames/'.$analiseId.'/transicao', ['acao' => 'iniciar_analise'])
        ->assertForbidden();

    $this->postJson('/api/rotina/exames/'.$analiseId.'/transicao', [
        'acao' => 'cancelar',
        'motivo' => 'Sem autorização',
    ])->assertForbidden();
});

it('registra usuário e justificativa na auditoria existente', function () {
    $pdo = rotinaTransitionControlConnection($this->rotinaTransitionDatabase);
    $id = rotinaTransitionCreateExame($pdo, 'coletado');

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', [
        'acao' => 'cancelar',
        'motivo' => 'Amostra hemolisada',
    ])->assertOk();

    $statement = $pdo->prepare(<<<'SQL'
        SELECT changed_by::text, changed_by_email, justificativa
          FROM atendimento_audit
         WHERE entidade = 'atendimento_exames'
           AND registro_id = ?
           AND operacao = 'UPDATE'
         ORDER BY id DESC
         LIMIT 1
    SQL);
    $statement->execute([$id]);
    $audit = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    expect($audit['changed_by'] ?? null)->toBe((string) $this->rotinaTransitionUser->getKey())
        ->and($audit['changed_by_email'] ?? null)->toBe($this->rotinaTransitionUser->email)
        ->and($audit['justificativa'] ?? null)->toBe('Amostra hemolisada');
});
