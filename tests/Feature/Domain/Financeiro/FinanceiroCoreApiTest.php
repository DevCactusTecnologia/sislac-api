<?php

beforeEach(function () {
    resetSupabaseFixture();
    $this->financeiroCoreUserId = configureSupabaseTestUser($this, [
        'visualizar_financeiro',
        'visualizar_atendimentos',
        'gestao_financeira',
        'registrar_pagamento',
        'editar_atendimento',
    ]);
});

/**
 * @param  list<array{nome:string,valor:string,status?:string,destino?:string}>  $exames
 * @return array{id:int,protocolo:string}
 */
function financeiroCoreCreateAtendimento(PDO $pdo, array $exames): array
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimentos (protocolo, paciente_nome, paciente_cpf, solicitante)
        VALUES ('TEMP', 'Paciente Financeiro', '12345678901', 'Dr. Financeiro')
        RETURNING id, protocolo
    SQL);
    $statement->execute();
    $atendimento = $statement->fetch(PDO::FETCH_ASSOC);

    if (! is_array($atendimento)) {
        throw new RuntimeException('Falha ao criar atendimento de teste.');
    }

    $examStatement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_exames
            (atendimento_id, nome_exame, valor, valor_original, status, cobranca_destino)
        VALUES (?, ?, ?, ?, ?, ?)
    SQL);

    foreach ($exames as $exame) {
        $examStatement->execute([
            $atendimento['id'],
            $exame['nome'],
            $exame['valor'],
            $exame['valor'],
            $exame['status'] ?? 'pendente',
            $exame['destino'] ?? 'paciente',
        ]);
    }

    return [
        'id' => (int) $atendimento['id'],
        'protocolo' => (string) $atendimento['protocolo'],
    ];
}

function financeiroCoreInsertPayment(PDO $pdo, int $atendimentoId, string $valor, string $status = 'efetuado'): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO atendimento_pagamentos (atendimento_id, tipo, valor, observacao, status_pagamento)
        VALUES (?, 'PIX', ?, 'Pagamento de teste', ?)
        RETURNING id
    SQL);
    $statement->execute([$atendimentoId, $valor, $status]);

    return (int) $statement->fetchColumn();
}

it('lista A Receber de pacientes com saldo calculado somente no backend', function () {
    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
        ['nome' => 'Exame cancelado', 'valor' => '50.00', 'status' => 'cancelado'],
        ['nome' => 'Exame convênio', 'valor' => '70.00', 'destino' => 'convenio'],
    ]);
    financeiroCoreInsertPayment($pdo, $atendimento['id'], '40.00');
    financeiroCoreInsertPayment($pdo, $atendimento['id'], '10.00', 'estornado');

    $this->getJson('/api/financeiro/a-receber/pacientes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $atendimento['id'])
        ->assertJsonPath('data.0.protocolo', $atendimento['protocolo'])
        ->assertJsonPath('data.0.valor_total', '100.00')
        ->assertJsonPath('data.0.valor_pago', '40.00')
        ->assertJsonPath('data.0.saldo', '60.00')
        ->assertJsonPath('data.0.status', 'parcial');
});

it('registra recebimentos de forma aditiva e recompõe o status do atendimento', function () {
    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);

    $first = $this->postJson('/api/financeiro/atendimentos/'.$atendimento['id'].'/pagamentos', [
        'tipo' => 'PIX',
        'valor' => '40.00',
        'observacao' => 'Primeira parcela',
    ])->assertCreated()
        ->assertJsonPath('data.atendimento_id', $atendimento['id'])
        ->assertJsonPath('data.valor', '40.00')
        ->assertJsonPath('data.status_pagamento', 'efetuado');

    $firstId = (int) $first->json('data.id');

    $this->postJson('/api/financeiro/atendimentos/'.$atendimento['id'].'/pagamentos', [
        'tipo' => 'Dinheiro',
        'valor' => '60.00',
    ])->assertCreated();

    expect((int) $pdo->query("SELECT count(*) FROM atendimento_pagamentos WHERE atendimento_id = {$atendimento['id']}")?->fetchColumn())->toBe(2)
        ->and((int) $pdo->query("SELECT count(*) FROM atendimento_pagamentos WHERE id = {$firstId}")?->fetchColumn())->toBe(1)
        ->and((string) $pdo->query("SELECT status_pagamento FROM atendimentos WHERE id = {$atendimento['id']}")?->fetchColumn())->toBe('Pagamento efetuado');
});

it('rejeita valor inválido e sobrepagamento sem escrever parcialmente', function () {
    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);

    $this->postJson('/api/financeiro/atendimentos/'.$atendimento['id'].'/pagamentos', [
        'tipo' => 'PIX',
        'valor' => '0.00',
    ])->assertUnprocessable();

    $this->postJson('/api/financeiro/atendimentos/'.$atendimento['id'].'/pagamentos', [
        'tipo' => 'PIX',
        'valor' => '100.01',
    ])->assertConflict();

    expect((int) $pdo->query("SELECT count(*) FROM atendimento_pagamentos WHERE atendimento_id = {$atendimento['id']}")?->fetchColumn())->toBe(0);
});

it('não registra recebimento para atendimento cancelado', function () {
    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);
    $pdo->exec("UPDATE atendimentos SET status_atendimento = 'Cancelado' WHERE id = {$atendimento['id']}");

    $this->postJson('/api/financeiro/atendimentos/'.$atendimento['id'].'/pagamentos', [
        'tipo' => 'PIX',
        'valor' => '10.00',
    ])->assertConflict();

    expect((int) $pdo->query("SELECT count(*) FROM atendimento_pagamentos WHERE atendimento_id = {$atendimento['id']}")?->fetchColumn())->toBe(0);
});

it('lista Recebimentos somente com pagamentos efetivos de atendimentos ativos', function () {
    $pdo = supabaseTestPdo();
    $ativo = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);
    $cancelado = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Glicose', 'valor' => '30.00'],
    ]);

    $efetivoId = financeiroCoreInsertPayment($pdo, $ativo['id'], '40.00');
    financeiroCoreInsertPayment($pdo, $ativo['id'], '10.00', 'estornado');
    financeiroCoreInsertPayment($pdo, $cancelado['id'], '30.00');
    $pdo->exec("UPDATE atendimentos SET status_atendimento = 'Cancelado' WHERE id = {$cancelado['id']}");

    $this->getJson('/api/financeiro/recebimentos/pacientes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.pagamento_id', $efetivoId)
        ->assertJsonPath('data.0.atendimento_id', $ativo['id'])
        ->assertJsonPath('data.0.valor', '40.00')
        ->assertJsonPath('data.0.origem', 'pagamento');
});

it('estorna pagamento formalmente preservando o original e reabrindo o saldo', function () {
    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);
    $pagamentoId = financeiroCoreInsertPayment($pdo, $atendimento['id'], '100.00');

    $this->postJson('/api/financeiro/pagamentos/'.$pagamentoId.'/estorno', [
        'motivo' => 'Pagamento lançado em duplicidade',
    ])->assertOk()
        ->assertJsonPath('data.pagamento_id', $pagamentoId)
        ->assertJsonPath('data.status_pagamento', 'estornado')
        ->assertJsonPath('data.valor', '100.00')
        ->assertJsonPath('data.motivo', 'Pagamento lançado em duplicidade');

    expect((int) $pdo->query("SELECT count(*) FROM atendimento_pagamentos WHERE id = {$pagamentoId}")?->fetchColumn())->toBe(1)
        ->and((string) $pdo->query("SELECT status_pagamento FROM atendimento_pagamentos WHERE id = {$pagamentoId}")?->fetchColumn())->toBe('estornado')
        ->and((int) $pdo->query("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'pagamento' AND origem_id = {$pagamentoId}")?->fetchColumn())->toBe(1)
        ->and((string) $pdo->query("SELECT status_pagamento FROM atendimentos WHERE id = {$atendimento['id']}")?->fetchColumn())->toBe('Pagamento pendente');

    $this->getJson('/api/financeiro/a-receber/pacientes')
        ->assertOk()
        ->assertJsonPath('data.0.saldo', '100.00')
        ->assertJsonPath('data.0.status', 'pendente');
});

it('exige motivo e impede segundo estorno do mesmo pagamento', function () {
    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);
    $pagamentoId = financeiroCoreInsertPayment($pdo, $atendimento['id'], '50.00');

    $this->postJson('/api/financeiro/pagamentos/'.$pagamentoId.'/estorno', [
        'motivo' => '   ',
    ])->assertUnprocessable();

    $this->postJson('/api/financeiro/pagamentos/'.$pagamentoId.'/estorno', [
        'motivo' => 'Correção operacional',
    ])->assertOk();

    $this->postJson('/api/financeiro/pagamentos/'.$pagamentoId.'/estorno', [
        'motivo' => 'Tentativa duplicada',
    ])->assertConflict();

    expect((int) $pdo->query("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'pagamento' AND origem_id = {$pagamentoId}")?->fetchColumn())->toBe(1);
});

it('não concede estorno apenas por possuir registrar_pagamento', function () {
    setSupabaseTestPermissions($this->financeiroCoreUserId, ['registrar_pagamento']);

    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);
    $pagamentoId = financeiroCoreInsertPayment($pdo, $atendimento['id'], '50.00');

    $this->postJson('/api/financeiro/pagamentos/'.$pagamentoId.'/estorno', [
        'motivo' => 'Não deve autorizar',
    ])->assertForbidden();

    expect((string) $pdo->query("SELECT status_pagamento FROM atendimento_pagamentos WHERE id = {$pagamentoId}")?->fetchColumn())->toBe('efetuado');
});

it('proíbe substituição destrutiva de pagamentos pelo PATCH de atendimento', function () {
    $pdo = supabaseTestPdo();
    $atendimento = financeiroCoreCreateAtendimento($pdo, [
        ['nome' => 'Hemograma', 'valor' => '100.00'],
    ]);
    $pagamentoId = financeiroCoreInsertPayment($pdo, $atendimento['id'], '50.00');

    $this->patchJson('/api/atendimentos/'.$atendimento['id'], [
        'pagamentos' => [],
    ])->assertUnprocessable();

    expect((int) $pdo->query("SELECT count(*) FROM atendimento_pagamentos WHERE id = {$pagamentoId}")?->fetchColumn())->toBe(1)
        ->and((string) $pdo->query("SELECT status_pagamento FROM atendimento_pagamentos WHERE id = {$pagamentoId}")?->fetchColumn())->toBe('efetuado');
});
