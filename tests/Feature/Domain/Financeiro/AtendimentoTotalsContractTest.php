<?php

beforeEach(function () {
    resetSupabaseFixture();
    configureSupabaseTestUser($this, [
        'criar_atendimento',
        'editar_atendimento',
        'visualizar_atendimentos',
    ]);
});

function totaisCreateAtendimento(PDO $pdo): int
{
    $statement = $pdo->query(<<<'SQL'
        INSERT INTO atendimentos (paciente_nome, paciente_cpf)
        VALUES ('Paciente Totais', '')
        RETURNING id
    SQL);

    return (int) $statement?->fetchColumn();
}

function totaisInsertExame(
    PDO $pdo,
    int $atendimentoId,
    string $valor,
    string $valorOriginal,
    string $status = 'pendente',
    string $nome = 'Exame Totais',
): int {
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames
            (atendimento_id, nome_exame, valor, valor_original, status, tipo_processo, amostra_seq, cobranca_destino)
        VALUES (?, ?, ?, ?, ?, 'INTERNO', 1, 'paciente')
        RETURNING id
    SQL);
    $statement->execute([$atendimentoId, $nome, $valor, $valorOriginal, $status]);

    return (int) $statement->fetchColumn();
}

/** @return array{subtotal:string,desconto_total:string,acrescimo_total:string,total:string} */
function totaisReadParent(PDO $pdo, int $atendimentoId): array
{
    $statement = $pdo->query(sprintf(
        'SELECT subtotal::text, desconto_total::text, acrescimo_total::text, total::text FROM atendimentos WHERE id = %d',
        $atendimentoId,
    ));
    $row = $statement?->fetch(PDO::FETCH_ASSOC);

    if (! is_array($row)) {
        throw new RuntimeException('Atendimento de teste não encontrado.');
    }

    return [
        'subtotal' => (string) $row['subtotal'],
        'desconto_total' => (string) $row['desconto_total'],
        'acrescimo_total' => (string) $row['acrescimo_total'],
        'total' => (string) $row['total'],
    ];
}

it('deriva desconto a partir de valor_original e valor sem cálculo no cliente', function () {
    $pdo = supabaseTestPdo();
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '20.00',
        'acrescimo_total' => '0.00',
        'total' => '80.00',
    ]);
});

it('deriva acréscimo a partir de valor_original e valor sem cálculo paralelo', function () {
    $pdo = supabaseTestPdo();
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '120.00', '100.00');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '0.00',
        'acrescimo_total' => '20.00',
        'total' => '120.00',
    ]);
});

it('mantém desconto e acréscimo líquidos quando ajustes opostos se compensam', function () {
    $pdo = supabaseTestPdo();
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '90.00', '100.00', 'pendente', 'Exame com desconto');
    totaisInsertExame($pdo, $atendimentoId, '60.00', '50.00', 'pendente', 'Exame com acréscimo');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '150.00',
        'desconto_total' => '0.00',
        'acrescimo_total' => '0.00',
        'total' => '150.00',
    ]);
});

it('exclui exame cancelado do subtotal e do total', function () {
    $pdo = supabaseTestPdo();
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00', 'pendente', 'Exame ativo');
    totaisInsertExame($pdo, $atendimentoId, '999.00', '999.00', 'cancelado', 'Exame cancelado');

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '20.00',
        'acrescimo_total' => '0.00',
        'total' => '80.00',
    ]);
});

it('preserva valor_original já definido em alteração direta no banco', function () {
    $pdo = supabaseTestPdo();
    $atendimentoId = totaisCreateAtendimento($pdo);
    $exameId = totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    $update = $pdo->prepare('UPDATE atendimento_exames SET valor_original = ? WHERE id = ?');
    $update->execute(['500.00', $exameId]);

    $statement = $pdo->prepare('SELECT valor_original::text FROM atendimento_exames WHERE id = ?');
    $statement->execute([$exameId]);

    expect((string) $statement->fetchColumn())->toBe('100.00');
});

it('recusa totais derivados enviados na criação do atendimento', function () {
    $this->postJson('/api/atendimentos', [
        'paciente_nome' => 'Paciente HTTP Totais',
        'paciente_cpf' => '',
        'exames' => [[
            'nome_exame' => 'Hemograma',
            'valor' => '80.00',
            'valor_original' => '100.00',
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
        ]],
        'subtotal' => '1.00',
        'desconto_total' => '1.00',
        'acrescimo_total' => '1.00',
        'total' => '1.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['subtotal', 'desconto_total', 'acrescimo_total', 'total']);
});

it('recusa totais derivados enviados na atualização do atendimento', function () {
    $pdo = supabaseTestPdo();
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    $this->patchJson('/api/atendimentos/'.$atendimentoId, [
        'subtotal' => '1.00',
        'desconto_total' => '1.00',
        'acrescimo_total' => '1.00',
        'total' => '1.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['subtotal', 'desconto_total', 'acrescimo_total', 'total']);

    expect(totaisReadParent($pdo, $atendimentoId))->toBe([
        'subtotal' => '100.00',
        'desconto_total' => '20.00',
        'acrescimo_total' => '0.00',
        'total' => '80.00',
    ]);
});

it('expõe no recurso somente os totais persistidos pelo backend', function () {
    $pdo = supabaseTestPdo();
    $atendimentoId = totaisCreateAtendimento($pdo);
    totaisInsertExame($pdo, $atendimentoId, '80.00', '100.00');

    $this->getJson('/api/atendimentos/'.$atendimentoId)
        ->assertOk()
        ->assertJsonPath('data.subtotal', '100.00')
        ->assertJsonPath('data.desconto_total', '20.00')
        ->assertJsonPath('data.acrescimo_total', '0.00')
        ->assertJsonPath('data.total', '80.00');
});
