<?php

use Illuminate\Support\Str;

beforeEach(function () {
    resetSupabaseFixture();
    configureSupabaseTestUser($this, ['criar_atendimento']);
});

it('não permite contornar registrar_pagamento pela criação de atendimento', function () {
    $idempotencyKey = (string) Str::uuid();

    $this->postJson('/api/atendimentos', [
        'paciente_nome' => 'Paciente Fronteira',
        'paciente_cpf' => '',
        'idempotency_key' => $idempotencyKey,
        'exames' => [[
            'nome_exame' => 'Glicose',
            'valor' => '50.00',
            'tipo_processo' => 'INTERNO',
            'amostra_seq' => 1,
        ]],
        'pagamentos' => [[
            'tipo' => 'PIX',
            'valor' => '50.00',
        ]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('pagamentos');

    $statement = supabaseTestPdo()->prepare('SELECT count(*) FROM atendimentos WHERE idempotency_key = ?');
    $statement->execute([$idempotencyKey]);

    expect((int) $statement->fetchColumn())->toBe(0);
});
