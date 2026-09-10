<?php

it('mantém íntegro o manifesto Supabase observado pelo Laravel', function () {
    $command = sprintf('php %s 2>&1', escapeshellarg(base_path('scripts/check-supabase-contract.php')));
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->toContain('manifesto Supabase íntegro');
});

it('descreve o gate do CI como integridade offline, não conformidade live', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain('Integridade do manifesto Supabase')
        ->not->toContain('Contrato Supabase ↔ Laravel');
});

it('fixa a baseline no frontend validado e somente nos contratos já migrados', function () {
    $manifest = json_decode(
        (string) file_get_contents(base_path('docs/contracts/supabase-baseline.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['version'])->toBe(3)
        ->and($manifest['frontend']['sha'])->toBe('5f3adbab91631e2b459bb83e20ae6eef79b5f8b6')
        ->and($manifest['supabase']['postgres_major'])->toBe(17)
        ->and($manifest['supabase']['runtime'])->toBe('single-tenant')
        ->and(array_column($manifest['migrated_contracts'], 'name'))->toBe([
            'pacientes',
            'atendimentos',
            'rotina',
            'financeiro-core',
            'financeiro-totais-atendimento',
            'financeiro-caixa-operacional',
            'financeiro-saidas',
        ])
        ->and($manifest)->not->toHaveKeys([
            'runtime_contract',
            'storage_inventory',
            'edge_function_inventory',
        ]);
});
