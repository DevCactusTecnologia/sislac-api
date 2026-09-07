<?php

it('mantém o contrato versionado de atendimentos íntegro', function () {
    $path = base_path('docs/contracts/atendimentos.json');

    expect(is_file($path))->toBeTrue();

    /** @var array<string, mixed> $contract */
    $contract = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($contract['schema_version'])->toBe(1)
        ->and($contract['frontend']['repository'])->toBe('DevCactusTecnologia/sislacprivado')
        ->and($contract['frontend']['commit'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($contract['supabase']['project_ref'])->toBe('eramenhnqcbyctyiqwlm')
        ->and($contract['supabase']['postgres_major'])->toBe(17)
        ->and($contract['supabase']['tables'])->toBe([
            'public.atendimentos',
            'public.atendimento_exames',
            'public.atendimento_pagamentos',
        ])
        ->and($contract['create_contract']['atomic'])->toBeTrue()
        ->and($contract['create_contract']['idempotency']['duplicate_returns_existing'])->toBeTrue()
        ->and($contract['create_contract']['protocol']['generated_server_side'])->toBeTrue()
        ->and($contract['create_contract']['protocol']['immutable'])->toBeTrue()
        ->and($contract['safety']['contains_production_rows'])->toBeFalse()
        ->and($contract['safety']['contains_credentials'])->toBeFalse();

    expect($contract['supabase']['functions'])->toContain(
        'public.create_atendimento_tx',
        'public.update_atendimento_tx',
        'public.generate_protocolo_curto',
        'public.recompute_atendimento_completo',
    );

    expect($contract['authorization'])->toMatchArray([
        'create' => 'criar_atendimento',
        'edit' => 'editar_atendimento',
        'cancel' => 'cancelar_atendimento',
        'payment' => 'registrar_pagamento',
    ]);

    expect($contract['derived_fields'])->toBe([
        'status_atendimento',
        'status_pagamento',
        'subtotal',
        'desconto_total',
        'acrescimo_total',
        'total',
    ]);
});
