<?php

use App\Platform\Models\Tenant;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rotinaConcurrencyDatabase = 'sislac_t_rotconc_'.Str::lower(Str::random(8));
    rotinaConcurrencyControlConnection()->exec('CREATE DATABASE "'.$this->rotinaConcurrencyDatabase.'"');

    $this->rotinaConcurrencyTenantId = (string) Str::uuid();
    $now = now();

    DB::connection('central')->table('tenants')->insert([
        'id' => $this->rotinaConcurrencyTenantId,
        'name' => 'Laboratório Concorrência Rotina',
        'code' => 'rotconc-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_name' => $this->rotinaConcurrencyDatabase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    tenancy()->initialize(Tenant::query()->findOrFail($this->rotinaConcurrencyTenantId));
    Artisan::call('migrate', [
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
    tenancy()->end();

    $this->rotinaConcurrencyUser = User::factory()->create(['name' => 'Usuário Concorrência']);
    DB::connection('central')->table('memberships')->insert([
        'user_id' => $this->rotinaConcurrencyUser->getKey(),
        'tenant_id' => $this->rotinaConcurrencyTenantId,
        'role' => 'admin',
        'status' => 'active',
        'permissions_extra' => '[]',
        'permissions_revoked' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');
    Http::preventStrayRequests();

    $userId = $this->rotinaConcurrencyUser->id;
    $userEmail = $this->rotinaConcurrencyUser->email;

    Http::fake(function ($request) use ($userId, $userEmail) {
        if ($request->hasHeader('Authorization', 'Bearer invalid-token')) {
            return Http::response(['message' => 'invalid'], 401);
        }

        return Http::response([
            'id' => $userId,
            'email' => $userEmail,
        ], 200);
    });

    $this->withHeader('Origin', 'https://sislac.com.br');
    $this->withHeader('X-Tenant', $this->rotinaConcurrencyTenantId);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::purge('tenant');
    rotinaConcurrencyControlConnection()->exec('DROP DATABASE IF EXISTS "'.$this->rotinaConcurrencyDatabase.'" WITH (FORCE)');
});

function rotinaConcurrencyControlConnection(?string $database = null): PDO
{
    $config = config('database.connections.central');

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $database ?? 'postgres',
        ),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function rotinaConcurrencyCreateExame(PDO $pdo, string $targetStatus = 'pendente'): int
{
    $atendimentoId = (int) $pdo->query(
        "INSERT INTO atendimentos (paciente_nome, paciente_cpf) VALUES ('Paciente Concorrência', '') RETURNING id",
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
        default => throw new InvalidArgumentException('Status de concorrência inválido.'),
    };

    $update = $pdo->prepare('UPDATE atendimento_exames SET status = ? WHERE id = ?');
    foreach ($path as $status) {
        $update->execute([$status, $id]);
    }

    return $id;
}

function rotinaConcurrencyTransitionProcess(
    string $database,
    int $examId,
    string $action,
    string $actorName,
    int $delayMicroseconds = 0,
): Process {
    $actorId = (string) Str::uuid();
    $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tenantConnection = config('database.connections.tenant_template');
$tenantConnection['database'] = $argv[1];
config([
    'database.default' => 'tenant',
    'database.connections.tenant' => $tenantConnection,
]);
Illuminate\Support\Facades\DB::purge('tenant');
if ((int) $argv[6] > 0) {
    usleep((int) $argv[6]);
}
try {
    $exam = app(App\Domain\Atendimentos\Actions\TransitionAtendimentoExame::class)->handle(
        (int) $argv[2],
        $argv[3],
        $argv[4],
        $argv[5],
        strtolower(str_replace(' ', '.', $argv[5])).'@example.test',
    );
    echo json_encode(['ok' => true, 'status' => $exam->getAttribute('status')], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
    exit(3);
}
PHP;

    return new Process([
        PHP_BINARY,
        '-r',
        $script,
        $database,
        (string) $examId,
        $action,
        $actorId,
        $actorName,
        (string) $delayMicroseconds,
    ], base_path(), null, null, 15);
}

function rotinaConcurrencyConfigProcess(string $database, string $mode, int $delayMicroseconds = 0): Process
{
    $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tenantConnection = config('database.connections.tenant_template');
$tenantConnection['database'] = $argv[1];
config([
    'database.default' => 'tenant',
    'database.connections.tenant' => $tenantConnection,
]);
Illuminate\Support\Facades\DB::purge('tenant');
if ((int) $argv[3] > 0) {
    usleep((int) $argv[3]);
}
try {
    $result = app(App\Domain\Atendimentos\Actions\UpdateRotinaConfig::class)->handle($argv[2]);
    echo json_encode(['ok' => true, 'mode' => $result['rotina_fluxo_modo']], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
    exit(3);
}
PHP;

    return new Process([
        PHP_BINARY,
        '-r',
        $script,
        $database,
        $mode,
        (string) $delayMicroseconds,
    ], base_path(), null, null, 15);
}

it('serializa duas coletas concorrentes sem reescrever timestamp nem duplicar auditoria', function () {
    $pdo = rotinaConcurrencyControlConnection($this->rotinaConcurrencyDatabase);
    $id = rotinaConcurrencyCreateExame($pdo);

    $first = rotinaConcurrencyTransitionProcess($this->rotinaConcurrencyDatabase, $id, 'coletar', 'Coletor A');
    $second = rotinaConcurrencyTransitionProcess($this->rotinaConcurrencyDatabase, $id, 'coletar', 'Coletor B');

    $first->start();
    $second->start();
    $first->wait();
    $second->wait();

    expect($first->isSuccessful())->toBeTrue($first->getOutput().$first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getOutput().$second->getErrorOutput());

    $row = $pdo->query("SELECT status, data_coleta::text, coletor FROM atendimento_exames WHERE id = {$id}")?->fetch(PDO::FETCH_ASSOC) ?: [];
    $auditCount = (int) $pdo->query(
        "SELECT count(*) FROM atendimento_audit WHERE entidade = 'atendimento_exames' AND registro_id = {$id} AND operacao = 'UPDATE'",
    )?->fetchColumn();

    expect($row['status'] ?? null)->toBe('coletado')
        ->and($row['data_coleta'] ?? null)->not->toBeNull()
        ->and($row['coletor'] ?? null)->toBeIn(['Coletor A', 'Coletor B'])
        ->and($auditCount)->toBe(1);
});

it('rejeita transição stale incompatível mesmo quando outra intenção vence primeiro', function () {
    $pdo = rotinaConcurrencyControlConnection($this->rotinaConcurrencyDatabase);
    $id = rotinaConcurrencyCreateExame($pdo);

    $collect = rotinaConcurrencyTransitionProcess($this->rotinaConcurrencyDatabase, $id, 'coletar', 'Coletor Vencedor');
    $staleFinish = rotinaConcurrencyTransitionProcess(
        $this->rotinaConcurrencyDatabase,
        $id,
        'finalizar_analise',
        'Analista Stale',
        100_000,
    );

    $collect->start();
    $staleFinish->start();
    $collect->wait();
    $staleFinish->wait();

    expect($collect->isSuccessful())->toBeTrue($collect->getOutput().$collect->getErrorOutput())
        ->and($staleFinish->isSuccessful())->toBeFalse()
        ->and($staleFinish->getExitCode())->toBe(3)
        ->and($pdo->query("SELECT status FROM atendimento_exames WHERE id = {$id}")?->fetchColumn())->toBe('coletado');
});

it('serializa mudanças concorrentes de modo mantendo estado compatível com o modo final', function () {
    $pdo = rotinaConcurrencyControlConnection($this->rotinaConcurrencyDatabase);
    rotinaConcurrencyCreateExame($pdo, 'pendente');
    rotinaConcurrencyCreateExame($pdo, 'coletado');
    rotinaConcurrencyCreateExame($pdo, 'em_bancada');

    $resultOnly = rotinaConcurrencyConfigProcess($this->rotinaConcurrencyDatabase, 'apenas_resultado');
    $collectionResult = rotinaConcurrencyConfigProcess($this->rotinaConcurrencyDatabase, 'coleta_resultado', 50_000);

    $resultOnly->start();
    $collectionResult->start();
    $resultOnly->wait();
    $collectionResult->wait();

    expect($resultOnly->isSuccessful())->toBeTrue($resultOnly->getOutput().$resultOnly->getErrorOutput())
        ->and($collectionResult->isSuccessful())->toBeTrue($collectionResult->getOutput().$collectionResult->getErrorOutput());

    $mode = (string) $pdo->query('SELECT rotina_fluxo_modo FROM lab_config WHERE singleton_key = 1')?->fetchColumn();
    $statuses = $pdo->query("SELECT status FROM atendimento_exames WHERE tipo_processo = 'INTERNO'")?->fetchAll(PDO::FETCH_COLUMN) ?: [];

    expect($mode)->toBeIn(['apenas_resultado', 'coleta_resultado']);

    if ($mode === 'apenas_resultado') {
        expect(array_intersect($statuses, ['pendente', 'coletado', 'em_bancada']))->toBe([]);
    } else {
        expect(in_array('em_bancada', $statuses, true))->toBeFalse();
    }
});

it('não aceita sessão Laravel isolada como autenticação clínica da rotina', function () {
    $this->actingAs($this->rotinaConcurrencyUser, 'web');

    $this->getJson('/api/rotina/coleta')->assertUnauthorized();
});

it('rejeita Bearer Supabase inválido', function () {
    $this->withToken('invalid-token')
        ->getJson('/api/rotina/coleta')
        ->assertUnauthorized();
});

it('rejeita X-Tenant sem membership antes de tocar no banco clínico', function () {
    $this->withToken('valid-token')
        ->withHeader('X-Tenant', (string) Str::uuid())
        ->getJson('/api/rotina/coleta')
        ->assertForbidden();
});

it('rejeita ação clínica sem a permissão específica', function () {
    DB::connection('central')->table('memberships')
        ->where('user_id', $this->rotinaConcurrencyUser->getKey())
        ->where('tenant_id', $this->rotinaConcurrencyTenantId)
        ->update([
            'role' => 'financeiro',
            'permissions_extra' => '[]',
        ]);

    $pdo = rotinaConcurrencyControlConnection($this->rotinaConcurrencyDatabase);
    $id = rotinaConcurrencyCreateExame($pdo);

    $this->withToken('valid-token')
        ->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'coletar'])
        ->assertForbidden();
});

it('mantém auditoria append-only e registra evidência das transições', function () {
    $pdo = rotinaConcurrencyControlConnection($this->rotinaConcurrencyDatabase);
    $id = rotinaConcurrencyCreateExame($pdo);

    $this->withToken('valid-token')
        ->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'coletar'])
        ->assertOk();

    $this->withToken('valid-token')
        ->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'iniciar_analise'])
        ->assertOk();

    $audits = $pdo->query(<<<SQL
        SELECT id, changed_by::text, changed_by_email
          FROM atendimento_audit
         WHERE entidade = 'atendimento_exames'
           AND registro_id = {$id}
           AND operacao = 'UPDATE'
         ORDER BY id
    SQL)?->fetchAll(PDO::FETCH_ASSOC) ?: [];

    expect($audits)->toHaveCount(2)
        ->and($audits[0]['changed_by'] ?? null)->toBe((string) $this->rotinaConcurrencyUser->getKey())
        ->and($audits[1]['changed_by'] ?? null)->toBe((string) $this->rotinaConcurrencyUser->getKey());

    $auditId = (int) ($audits[0]['id'] ?? 0);

    expect(fn () => $pdo->exec("UPDATE atendimento_audit SET acao = 'adulterada' WHERE id = {$auditId}"))
        ->toThrow(PDOException::class);
    expect(fn () => $pdo->exec("DELETE FROM atendimento_audit WHERE id = {$auditId}"))
        ->toThrow(PDOException::class);
});
