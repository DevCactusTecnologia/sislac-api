<?php

use Illuminate\Support\Str;

beforeEach(function () {
    resetSupabaseFixture();
    $this->atendimentoAuthUserId = configureSupabaseTestUser($this, ['criar_atendimento']);

    $created = $this->postJson('/api/atendimentos', [
        'paciente_nome' => 'Paciente Autorização',
        'paciente_cpf' => '12345678901',
        'idempotency_key' => (string) Str::uuid(),
        'exames' => [[
            'nome_exame' => 'Hemograma',
            'valor' => '100.00',
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
        ]],
    ])->assertCreated();

    $this->atendimentoAuthId = (int) $created->json('atendimento_id');
});

it('exige editar_atendimento para alteração clínica ou cadastral normal', function () {
    setSupabaseTestPermissions($this->atendimentoAuthUserId, ['visualizar_atendimentos']);

    $this->patchJson('/api/atendimentos/'.$this->atendimentoAuthId, [
        'solicitante' => 'Sem Permissão',
    ])->assertForbidden();
});

it('exige cancelar_atendimento para cancelamento mesmo quando usuário pode editar', function () {
    setSupabaseTestPermissions($this->atendimentoAuthUserId, ['editar_atendimento']);

    $this->patchJson('/api/atendimentos/'.$this->atendimentoAuthId, [
        'cancelar' => true,
        'motivo_cancelamento' => 'Tentativa sem permissão',
    ])->assertForbidden();
});

it('permite registrar pagamento com registrar_pagamento sem editar_atendimento', function () {
    setSupabaseTestPermissions($this->atendimentoAuthUserId, ['registrar_pagamento']);

    $this->postJson('/api/financeiro/atendimentos/'.$this->atendimentoAuthId.'/pagamentos', [
        'tipo' => 'PIX',
        'valor' => '30.00',
        'observacao' => 'Pagamento financeiro',
    ])->assertCreated()
        ->assertJsonPath('data.status_pagamento', 'efetuado');

    expect((string) supabaseTestPdo()->query(
        "SELECT status_pagamento FROM atendimentos WHERE id = {$this->atendimentoAuthId}",
    )?->fetchColumn())->toBe('Pagamento parcial');
});

it('registrar_pagamento não concede editar_atendimento', function () {
    setSupabaseTestPermissions($this->atendimentoAuthUserId, ['registrar_pagamento']);

    $this->patchJson('/api/atendimentos/'.$this->atendimentoAuthId, [
        'solicitante' => 'Alteração indevida pelo financeiro',
    ])->assertForbidden();
});

it('não expõe exclusão física de Atendimentos', function () {
    $this->deleteJson('/api/atendimentos/'.$this->atendimentoAuthId)
        ->assertMethodNotAllowed();
});
