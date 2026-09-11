<?php

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    resetSupabaseFixture();
    $this->rotinaConcurrencyUserId = configureSupabaseTestUser(
        $this,
        ['registrar_coleta', 'analisar_amostra', 'cancelar_atendimento'],
        email: 'concorrencia.rotina@example.test',
    );
});

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
if ((int) $argv[5] > 0) {
    usleep((int) $argv[5]);
}
try {
    $exam = app(App\Domain\Atendimentos\Actions\TransitionAtendimentoExame::class)->handle(
        (int) $argv[1],
        $argv[2],
        $argv[3],
        $argv[4],
        strtolower(str_replace(' ', '.', $argv[4])).'@example.test',
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
        (string) $examId,
        $action,
        $actorId,
        $actorName,
        (string) $delayMicroseconds,
    ], base_path(), null, null, 15);
}

function rotinaConcurrencyConfigProcess(string $mode, int $delayMicroseconds = 0): Process
{
    $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ((int) $argv[2] > 0) {
    usleep((int) $argv[2]);
}
try {
    $result = app(App\Domain\Atendimentos\Actions\UpdateRotinaConfig::class)->handle($argv[1]);
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
        $mode,
        (string) $delayMicroseconds,
    ], base_path(), null, null, 15);
}

it('serializa duas coletas concorrentes sem reescrever timestamp nem duplicar auditoria', function () {
    $pdo = supabaseTestPdo();
    $id = rotinaConcurrencyCreateExame($pdo);

    $first = rotinaConcurrencyTransitionProcess($id, 'coletar', 'Coletor A');
    $second = rotinaConcurrencyTransitionProcess($id, 'coletar', 'Coletor B');

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
    $pdo = supabaseTestPdo();
    $id = rotinaConcurrencyCreateExame($pdo);

    $collect = rotinaConcurrencyTransitionProcess($id, 'coletar', 'Coletor Vencedor');
    $staleFinish = rotinaConcurrencyTransitionProcess($id, 'finalizar_analise', 'Analista Stale', 100_000);

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
    $pdo = supabaseTestPdo();
    rotinaConcurrencyCreateExame($pdo, 'pendente');
    rotinaConcurrencyCreateExame($pdo, 'coletado');
    rotinaConcurrencyCreateExame($pdo, 'em_bancada');

    $resultOnly = rotinaConcurrencyConfigProcess('apenas_resultado');
    $collectionResult = rotinaConcurrencyConfigProcess('coleta_resultado', 50_000);

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

it('rejeita ação clínica sem a permissão específica', function () {
    setSupabaseTestPermissions($this->rotinaConcurrencyUserId, []);

    $id = rotinaConcurrencyCreateExame(supabaseTestPdo());

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'coletar'])
        ->assertForbidden();
});

it('mantém auditoria append-only e registra evidência das transições', function () {
    $pdo = supabaseTestPdo();
    $id = rotinaConcurrencyCreateExame($pdo);

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'coletar'])
        ->assertOk();

    $this->postJson('/api/rotina/exames/'.$id.'/transicao', ['acao' => 'iniciar_analise'])
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
        ->and($audits[0]['changed_by'] ?? null)->toBe($this->rotinaConcurrencyUserId)
        ->and($audits[1]['changed_by'] ?? null)->toBe($this->rotinaConcurrencyUserId);

    $auditId = (int) ($audits[0]['id'] ?? 0);

    expect(fn () => $pdo->exec("UPDATE atendimento_audit SET acao = 'adulterada' WHERE id = {$auditId}"))
        ->toThrow(PDOException::class);
    expect(fn () => $pdo->exec("DELETE FROM atendimento_audit WHERE id = {$auditId}"))
        ->toThrow(PDOException::class);
});
