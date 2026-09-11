<?php

beforeEach(function () {
    resetSupabaseFixture();
    $this->saidasApiUserId = configureSupabaseTestUser($this, [
        'visualizar_financeiro',
        'gestao_financeira',
    ]);
});

/** @param array<string, mixed> $overrides */
function validSaidaPayload(array $overrides = []): array
{
    return array_merge([
        'descricao' => 'Conta de energia',
        'valor' => '120.50',
        'tipo_despesa' => 'Conta',
        'destino_pagamento' => 'Concessionária',
    ], $overrides);
}

function insertSaidaForList(PDO $pdo, string $descricao, string $status, string $data, string $tipo = 'Conta', string $destino = 'Fornecedor'): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO financeiro_saidas
            (data, descricao, valor, tipo_despesa, destino_pagamento, status, forma_pagamento)
        VALUES (?, ?, 10.00, ?, ?, ?, 'Crédito')
        RETURNING id
    SQL);
    $statement->execute([$data, $descricao, $tipo, $destino, $status]);

    return (int) $statement->fetchColumn();
}

it('lista saídas vazias', function () {
    $this->getJson('/api/financeiro/saidas')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.next_cursor', null);
});

it('cria saída aberta com protocolo e estado definidos pelo servidor', function () {
    $response = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated()
        ->assertJsonPath('data.descricao', 'Conta de energia')
        ->assertJsonPath('data.valor', '120.50')
        ->assertJsonPath('data.status', 'aberta')
        ->assertJsonPath('data.foi_pago', false)
        ->assertJsonPath('data.data_pagamento', null)
        ->assertJsonPath('data.caixa_sessao_id', null);

    expect((string) $response->json('data.protocolo'))->toMatch('/^SAI-\d{4}-\d{7}$/');
    expect((int) supabaseTestPdo()->query('SELECT count(*) FROM financeiro_saidas')?->fetchColumn())->toBe(1);
});

it('permite criar saída já paga e gera data de pagamento quando ausente', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'paga')
        ->assertJsonPath('data.foi_pago', true)
        ->assertJsonPath('data.data_pagamento', now()->toDateString())
        ->assertJsonPath('data.forma_pagamento', 'Crédito');
});

it('recusa estado cancelado e campos controlados pelo servidor na criação', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'status' => 'cancelada',
        'id' => 99,
        'protocolo' => 'SAI-CLIENTE',
        'assinatura_protocolo' => 'fake',
        'foi_pago' => true,
        'caixa_sessao_id' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status',
            'id',
            'protocolo',
            'assinatura_protocolo',
            'foi_pago',
            'caixa_sessao_id',
            'created_at',
            'updated_at',
        ]);
});

it('recusa data de pagamento para saída aberta e valor inválido', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'data_pagamento' => '2026-09-10',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('data_pagamento');

    foreach (['0.00', '-0.01', '10.001'] as $valor) {
        $this->postJson('/api/financeiro/saidas', validSaidaPayload(['valor' => $valor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valor');
    }
});

it('normaliza campos textuais de negócio na criação', function () {
    $this->postJson('/api/financeiro/saidas', validSaidaPayload([
        'descricao' => '  Conta   de   água  ',
        'tipo_despesa' => '  Conta   pública ',
        'destino_pagamento' => '  Companhia   de Água ',
        'forma_pagamento' => '  Crédito  ',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.descricao', 'Conta de água')
        ->assertJsonPath('data.tipo_despesa', 'Conta pública')
        ->assertJsonPath('data.destino_pagamento', 'Companhia de Água')
        ->assertJsonPath('data.forma_pagamento', 'Crédito');
});

it('filtra saídas por status busca e intervalo de data', function () {
    $pdo = supabaseTestPdo();
    insertSaidaForList($pdo, 'Energia matriz', 'aberta', '2026-09-01 10:00:00+00', 'Energia', 'Energisa');
    insertSaidaForList($pdo, 'Água filial', 'paga', '2026-09-05 10:00:00+00', 'Água', 'Companhia');
    insertSaidaForList($pdo, 'Internet matriz', 'aberta', '2026-08-15 10:00:00+00', 'Internet', 'Operadora');

    $this->getJson('/api/financeiro/saidas?status=aberta&search=Energisa&date_from=2026-09-01&date_to=2026-09-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.descricao', 'Energia matriz')
        ->assertJsonPath('data.0.status', 'aberta');
});

it('pagina de modo estável por data e id', function () {
    $pdo = supabaseTestPdo();
    insertSaidaForList($pdo, 'Primeira', 'aberta', '2026-09-10 10:00:00+00');
    insertSaidaForList($pdo, 'Segunda', 'aberta', '2026-09-10 10:00:00+00');
    insertSaidaForList($pdo, 'Terceira', 'aberta', '2026-09-09 10:00:00+00');

    $first = $this->getJson('/api/financeiro/saidas?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $cursor = (string) $first->json('meta.next_cursor');
    expect($cursor)->not->toBe('');

    $second = $this->getJson('/api/financeiro/saidas?limit=2&cursor='.urlencode($cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($first->json('data.0.id'))->not->toBe($first->json('data.1.id'))
        ->and($second->json('data.0.descricao'))->toBe('Terceira');
});

it('separa permissão de leitura da gestão financeira', function () {
    setSupabaseTestPermissions($this->saidasApiUserId, ['visualizar_financeiro']);

    $this->getJson('/api/financeiro/saidas')->assertOk();

    $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertForbidden();

    expect((int) supabaseTestPdo()->query('SELECT count(*) FROM financeiro_saidas')?->fetchColumn())->toBe(0);
});

it('edita campos de negócio enquanto a saída está aberta', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'descricao' => '  Energia   setembro ',
        'valor' => '135.75',
        'tipo_despesa' => '  Conta   pública ',
        'destino_pagamento' => '  Concessionária   estadual ',
        'data_vencimento' => '2026-09-20',
    ])
        ->assertOk()
        ->assertJsonPath('data.descricao', 'Energia setembro')
        ->assertJsonPath('data.valor', '135.75')
        ->assertJsonPath('data.tipo_despesa', 'Conta pública')
        ->assertJsonPath('data.destino_pagamento', 'Concessionária estadual')
        ->assertJsonPath('data.data_vencimento', '2026-09-20')
        ->assertJsonPath('data.status', 'aberta')
        ->assertJsonPath('data.foi_pago', false);
});

it('efetiva pagamento de saída aberta uma única vez', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => '  Crédito  ',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paga')
        ->assertJsonPath('data.foi_pago', true)
        ->assertJsonPath('data.data_pagamento', now()->toDateString())
        ->assertJsonPath('data.forma_pagamento', 'Crédito');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'descricao' => 'Não pode alterar',
    ])->assertStatus(409);
});

it('vincula saída paga em dinheiro ou pix ao único caixa aberto', function () {
    $pdo = supabaseTestPdo();
    $caixaId = (int) $pdo->query("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-001', 0) RETURNING id")?->fetchColumn();

    foreach (['Dinheiro', 'PIX'] as $forma) {
        $saida = $this->postJson('/api/financeiro/saidas', validSaidaPayload([
            'descricao' => 'Despesa '.$forma,
        ]))->assertCreated();

        $id = (int) $saida->json('data.id');

        $this->patchJson('/api/financeiro/saidas/'.$id, [
            'status' => 'paga',
            'forma_pagamento' => $forma,
        ])
            ->assertOk()
            ->assertJsonPath('data.caixa_sessao_id', $caixaId);
    }
});

it('não vincula outras formas de pagamento ao caixa', function () {
    $pdo = supabaseTestPdo();
    $pdo->exec("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-001', 0)");

    $saida = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => 'Débito',
    ])
        ->assertOk()
        ->assertJsonPath('data.caixa_sessao_id', null);
});

it('recusa cancelamento direto e campos controlados pelo servidor no patch', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'cancelada',
        'id' => 999,
        'protocolo' => 'SAI-CLIENTE',
        'assinatura_protocolo' => 'fake',
        'foi_pago' => true,
        'caixa_sessao_id' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status',
            'id',
            'protocolo',
            'assinatura_protocolo',
            'foi_pago',
            'caixa_sessao_id',
            'created_at',
            'updated_at',
        ]);
});

it('recusa edição ou reabertura de saída cancelada', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');
    $pdo = supabaseTestPdo();
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO financeiro_estornos (origem_tipo, origem_id, motivo, valor, criado_por)
        VALUES ('saida', ?, 'Cancelamento de teste', 120.50, ?)
    SQL);
    $statement->execute([$id, $this->saidasApiUserId]);
    $pdo->exec("UPDATE financeiro_saidas SET status = 'cancelada' WHERE id = {$id}");

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'descricao' => 'Não pode alterar',
    ])->assertStatus(409);

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => 'Crédito',
    ])->assertStatus(409);
});

it('exige gestão financeira para editar saída', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');
    setSupabaseTestPermissions($this->saidasApiUserId, ['visualizar_financeiro']);

    $this->patchJson('/api/financeiro/saidas/'.$id, [
        'descricao' => 'Sem permissão',
    ])->assertForbidden();
});
