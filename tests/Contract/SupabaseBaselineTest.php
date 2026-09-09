<?php

it('mantém íntegro o manifesto Supabase observado pelo Laravel', function () {
    $command = sprintf('php %s 2>&1', escapeshellarg(base_path('scripts/check-supabase-contract.php')));
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->toContain('manifesto Supabase íntegro e determinístico');
});

it('descreve o gate do CI como integridade offline, não conformidade live', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain('Integridade do manifesto Supabase')
        ->not->toContain('Contrato Supabase ↔ Laravel');
});

it('fixa a baseline na main atual e somente nos contratos já migrados', function () {
    $manifest = json_decode(
        (string) file_get_contents(base_path('docs/contracts/supabase-baseline.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['version'])->toBe(2)
        ->and($manifest['frontend']['sha'])->toBe('57cc9be96703a41b207d530088369da1cc23cd94')
        ->and($manifest['supabase']['postgres_major'])->toBe(17)
        ->and($manifest['supabase']['runtime'])->toBe('single-tenant')
        ->and($manifest['migrated_contracts'])->toBe(['pacientes'])
        ->and($manifest)->not->toHaveKeys([
            'runtime_contract',
            'storage_inventory',
            'edge_function_inventory',
        ]);
});
