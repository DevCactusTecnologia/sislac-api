<?php

it('preserva findings conhecidos da origem sem tratá-los como requisitos Laravel', function () {
    $path = base_path('docs/contracts/supabase-baseline.json');

    expect($path)->toBeFile();

    /** @var array<string, mixed> $baseline */
    $baseline = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($baseline['schema_inventory'])->toMatchArray([
        'tables' => 96,
        'views' => 2,
        'enums' => 6,
        'rls_enabled_tables' => 96,
        'rls_disabled_tables' => 0,
    ])
        ->and($baseline['known_findings']['security'])->toContain(
            'authenticated_security_definer_function_executable:create_atendimento_tx',
            'authenticated_security_definer_function_executable:update_atendimento_tx',
            'auth_compromised_credential_check_disabled',
        )
        ->and($baseline['known_findings']['performance'])->toContain(
            'auth_rls_initplan',
            'multiple_permissive_policies',
            'duplicate_index',
        );
});
