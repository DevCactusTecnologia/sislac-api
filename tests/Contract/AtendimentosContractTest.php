<?php

it('fixa o contrato aprovado do módulo de atendimentos', function () {
    $path = base_path('docs/contracts/atendimentos.json');

    expect(file_exists($path))->toBeTrue('Contrato docs/contracts/atendimentos.json ainda não existe');

    $contract = json_decode(
        (string) file_get_contents($path),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($contract['baseline']['frontend_sha'])->toBe('57cc9be96703a41b207d530088369da1cc23cd94')
        ->and($contract['baseline']['supabase_project_ref'])->toBe('eramenhnqcbyctyiqwlm')
        ->and($contract['aggregate']['tables'])->toBe([
            'atendimentos',
            'atendimento_exames',
            'atendimento_pagamentos',
            'atendimento_audit',
        ])
        ->and($contract['protocol']['server_generated'])->toBeTrue()
        ->and($contract['protocol']['immutable'])->toBeTrue()
        ->and($contract['protocol']['digits'])->toBe(7)
        ->and($contract['idempotency']['column'])->toBe('idempotency_key')
        ->and($contract['idempotency']['unique_when_present'])->toBeTrue()
        ->and($contract['pagination']['cursor'])->toBe(['data', 'id'])
        ->and($contract['pagination']['default_page_size'])->toBe(50)
        ->and($contract['pagination']['max_page_size'])->toBe(200)
        ->and($contract['filters'])->toBe([
            'status',
            'pagamento',
            'unidade_id',
            'data_inicio',
            'data_fim',
            'q',
        ])
        ->and($contract['permissions'])->toBe([
            'index' => 'visualizar_atendimentos',
            'show' => 'visualizar_atendimentos',
            'store' => 'criar_atendimento',
            'update' => 'editar_atendimento',
            'cancel' => 'cancelar_atendimento',
            'payment_only' => 'registrar_pagamento',
        ])
        ->and($contract['cutover']['frontend_blocked'])->toBeTrue();
});
