<?php

it('preserva somente invariantes atuais e migradas da origem', function () {
    $path = base_path('docs/contracts/supabase-baseline.json');

    expect($path)->toBeFile();

    /** @var array<string, mixed> $baseline */
    $baseline = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($baseline['version'])->toBe(2)
        ->and($baseline['frontend']['sha'])->toBe('57cc9be96703a41b207d530088369da1cc23cd94')
        ->and($baseline['supabase'])->toMatchArray([
            'project_ref' => 'eramenhnqcbyctyiqwlm',
            'postgres_major' => 17,
            'runtime' => 'single-tenant',
        ])
        ->and($baseline['migrated_contracts'])->toBe(['pacientes'])
        ->and($baseline)->not->toHaveKeys([
            'schema_inventory',
            'known_findings',
            'runtime_contract',
        ]);
});
