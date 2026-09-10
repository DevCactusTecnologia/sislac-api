<?php

it('preserva somente invariantes atuais e migradas da origem', function () {
    $path = base_path('docs/contracts/supabase-baseline.json');

    expect($path)->toBeFile();

    /** @var array<string, mixed> $baseline */
    $baseline = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($baseline['version'])->toBe(3)
        ->and($baseline['frontend']['sha'])->toBe('5f3adbab91631e2b459bb83e20ae6eef79b5f8b6')
        ->and($baseline['supabase'])->toMatchArray([
            'project_ref' => 'eramenhnqcbyctyiqwlm',
            'postgres_major' => 17,
            'runtime' => 'single-tenant',
        ])
        ->and(array_column($baseline['migrated_contracts'], 'name'))->toBe([
            'pacientes',
            'atendimentos',
            'rotina',
            'financeiro-core',
            'financeiro-totais-atendimento',
            'financeiro-caixa-operacional',
            'financeiro-saidas',
        ])
        ->and($baseline)->not->toHaveKeys([
            'schema_inventory',
            'known_findings',
            'runtime_contract',
        ]);
});
