<?php

beforeEach(function () {
    resetSupabaseFixture();
    $this->saidasEstornoUserId = configureSupabaseTestUser($this, [
        'visualizar_financeiro',
        'gestao_financeira',
    ]);
});

/** @param array<string, mixed> $overrides */
function validSaidaEstornoPayload(array $overrides = []): array
{
    return array_merge([
        'descricao' => 'Despesa para estorno',
        'valor' => '120.50',
        'tipo_despesa' => 'Conta',
        'destino_pagamento' => 'Fornecedor',
    ], $overrides);
}

it('estorna saída aberta formalmente sem apagar o lançamento', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();

    $id = (int) $saida->json('data.id');
    $protocolo = (string) $saida->json('data.protocolo');

    $response = $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => '  Lançamento   indevido  ',
    ])
        ->assertOk()
        ->assertJsonPath('data.saida.id', $id)
        ->assertJsonPath('data.saida.protocolo', $protocolo)
        ->assertJsonPath('data.saida.valor', '120.50')
        ->assertJsonPath('data.saida.status', 'cancelada')
        ->assertJsonPath('data.saida.foi_pago', false)
        ->assertJsonPath('data.estorno.origem_tipo', 'saida')
        ->assertJsonPath('data.estorno.origem_id', $id)
        ->assertJsonPath('data.estorno.motivo', 'Lançamento indevido')
        ->assertJsonPath('data.estorno.valor', '120.50');

    expect((int) $response->json('data.estorno.id'))->toBeGreaterThan(0);

    $pdo = supabaseTestPdo();
    expect((int) $pdo->query('SELECT count(*) FROM financeiro_saidas')?->fetchColumn())->toBe(1)
        ->and((int) $pdo->query("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'saida' AND origem_id = {$id}")?->fetchColumn())->toBe(1);
});

it('estorna saída paga preservando data de pagamento e vínculo histórico do caixa', function () {
    $pdo = supabaseTestPdo();
    $caixaId = (int) $pdo->query("INSERT INTO caixa_sessoes (unidade_id, valor_abertura) VALUES ('und-001', 0) RETURNING id")?->fetchColumn();

    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    $paga = $this->patchJson('/api/financeiro/saidas/'.$id, [
        'status' => 'paga',
        'forma_pagamento' => 'Dinheiro',
        'data_pagamento' => '2026-09-10',
    ])
        ->assertOk()
        ->assertJsonPath('data.caixa_sessao_id', $caixaId);

    $protocolo = (string) $paga->json('data.protocolo');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Pagamento duplicado',
    ])
        ->assertOk()
        ->assertJsonPath('data.saida.protocolo', $protocolo)
        ->assertJsonPath('data.saida.status', 'cancelada')
        ->assertJsonPath('data.saida.foi_pago', false)
        ->assertJsonPath('data.saida.data_pagamento', '2026-09-10')
        ->assertJsonPath('data.saida.caixa_sessao_id', $caixaId)
        ->assertJsonPath('data.estorno.motivo', 'Pagamento duplicado');
});

it('recusa estorno sem motivo não vazio', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', ['motivo' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');
});

it('recusa segundo estorno da mesma saída', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Primeiro estorno',
    ])->assertOk();

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Segundo estorno',
    ])->assertStatus(409);

    expect((int) supabaseTestPdo()->query("SELECT count(*) FROM financeiro_estornos WHERE origem_tipo = 'saida' AND origem_id = {$id}")?->fetchColumn())->toBe(1);
});

it('retorna 404 ao tentar estornar saída inexistente', function () {
    $this->postJson('/api/financeiro/saidas/999999/estorno', [
        'motivo' => 'Não existe',
    ])->assertNotFound();
});

it('exige gestão financeira para estornar saída', function () {
    $saida = $this->postJson('/api/financeiro/saidas', validSaidaEstornoPayload())
        ->assertCreated();
    $id = (int) $saida->json('data.id');

    setSupabaseTestPermissions($this->saidasEstornoUserId, ['visualizar_financeiro']);

    $this->postJson('/api/financeiro/saidas/'.$id.'/estorno', [
        'motivo' => 'Sem permissão',
    ])->assertForbidden();
});
