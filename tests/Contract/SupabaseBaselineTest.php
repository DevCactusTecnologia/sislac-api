<?php

it('mantém íntegro o manifesto Supabase observado pelo Laravel', function () {
    $command = sprintf('php %s 2>&1', escapeshellarg(base_path('scripts/check-supabase-contract.php')));
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->toContain('manifesto Supabase íntegro e determinístico');
});

it('fixa a superfície necessária para as próximas ondas de migração', function () {
    $manifest = json_decode(
        (string) file_get_contents(base_path('docs/contracts/supabase-baseline.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['frontend']['sha'])->toBe('0760c6123f3062842eaff5f7304b6460c00c058d')
        ->and($manifest['supabase']['postgres_major'])->toBe(17)
        ->and($manifest['runtime_contract']['tables_views_consumed'])->toBe(77)
        ->and($manifest['runtime_contract']['rpcs_consumed'])->toBe(52)
        ->and($manifest['runtime_contract']['edge_functions_consumed'])->toBe(23)
        ->and($manifest['runtime_contract']['buckets_consumed'])->toBe(4)
        ->and($manifest['runtime_contract']['realtime_tables'])->toBe([
            'atendimento_exames',
            'atendimento_pagamentos',
            'atendimentos',
        ])
        ->and($manifest['storage_inventory'])->toHaveCount(7)
        ->and($manifest['edge_function_inventory'])->toHaveCount(33);
});
