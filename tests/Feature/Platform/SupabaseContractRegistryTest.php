<?php

use App\Platform\Supabase\SupabaseContractRegistry;

it('registra todos os contratos já concluídos sem antecipar convênios', function () {
    $registry = app(SupabaseContractRegistry::class);

    expect($registry->names())
        ->toBe([
            'pacientes',
            'atendimentos',
            'rotina',
            'financeiro-core',
            'financeiro-totais-atendimento',
            'financeiro-caixa-operacional',
            'financeiro-saidas',
        ])
        ->not->toContain('financeiro-convenios-faturas-core');
});
