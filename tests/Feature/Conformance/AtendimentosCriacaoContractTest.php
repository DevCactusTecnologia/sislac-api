<?php

it('mantem o contrato versionado da criacao de atendimentos', function () {
    $path = base_path('docs/contracts/atendimentos-criacao.json');

    expect($path)->toBeFile();

    $contract = json_decode(
        (string) file_get_contents($path),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($contract['baseline']['frontend_repository'])
        ->toBe('DevCactusTecnologia/sislacprivado')
        ->and($contract['baseline']['frontend_sha'])
        ->toBe('0760c6123f3062842eaff5f7304b6460c00c058d')
        ->and($contract['baseline']['supabase_project_ref'])
        ->toBe('eramenhnqcbyctyiqwlm')
        ->and($contract['baseline']['postgres_major'])
        ->toBe(17)
        ->and($contract['tables'])
        ->toHaveKeys(['atendimentos', 'atendimento_exames', 'atendimento_pagamentos'])
        ->and($contract['rpc']['create_atendimento_tx']['permission'])
        ->toBe('criar_atendimento')
        ->and($contract['rpc']['create_atendimento_tx']['atomic'])
        ->toBeTrue()
        ->and($contract['rpc']['create_atendimento_tx']['idempotency_key'])
        ->toBe('atendimentos.idempotency_key');
});
