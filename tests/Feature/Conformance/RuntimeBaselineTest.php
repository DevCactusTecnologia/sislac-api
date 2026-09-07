<?php

it('mantém um baseline versionado da superfície Supabase usada na migração', function () {
    $path = base_path('docs/conformance/supabase-runtime-baseline.json');

    expect($path)->toBeFile();

    /** @var array<string, mixed> $baseline */
    $baseline = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($baseline))->toBe([
        'captured_at',
        'frontend',
        'supabase',
        'inventory',
        'security_findings',
        'performance_findings',
        'classification',
    ]);

    expect($baseline['frontend'])->toHaveKeys(['repository', 'sha'])
        ->and($baseline['supabase'])->toHaveKeys(['project_ref', 'postgres_major'])
        ->and($baseline['inventory'])->toMatchArray([
            'tables' => 96,
            'views' => 2,
            'enums' => 6,
            'rls_enabled_tables' => 96,
            'rls_disabled_tables' => 0,
        ])
        ->and($baseline['classification'])->toHaveKeys([
            'required-runtime',
            'required-compat',
            'platform-specific',
            'dead-or-legacy',
        ]);

    expect($baseline['frontend']['repository'])->toBe('DevCactusTecnologia/sislacprivado')
        ->and($baseline['frontend']['sha'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($baseline['supabase']['project_ref'])->toBe('eramenhnqcbyctyiqwlm')
        ->and($baseline['supabase']['postgres_major'])->toBe(17);
});
