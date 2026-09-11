<?php

beforeEach(function () {
    resetSupabaseFixture();
    $this->rotinaQueuesUserId = configureSupabaseTestUser($this, ['visualizar_atendimentos']);
});

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
    $pdo = supabaseTestPdo();

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
    $pdo = supabaseTestPdo();
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
    $pdo = supabaseTestPdo();
    rotinaQueuesSetMode($pdo, 'apenas_resultado');
    rotinaQueuesCreateExame($pdo, 'Paciente Apenas Resultado');

    $this->getJson('/api/rotina/coleta')
        ->assertOk()
        ->assertExactJson(['enabled' => false, 'data' => []]);

    $this->getJson('/api/rotina/analise')
        ->assertOk()
        ->assertExactJson(['enabled' => false, 'data' => []]);
});

it('exige visualizar atendimentos nas duas filas', function () {
    setSupabaseTestPermissions($this->rotinaQueuesUserId, []);

    $this->getJson('/api/rotina/coleta')->assertForbidden();
    $this->getJson('/api/rotina/analise')->assertForbidden();
});
