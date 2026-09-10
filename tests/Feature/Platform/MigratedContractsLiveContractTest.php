<?php

use App\Platform\Supabase\MigratedContractsLiveContract;

it('aponta relações e rotinas ausentes por contrato', function () {
    $contract = app(MigratedContractsLiveContract::class);

    $differences = $contract->compare(
        relations: ['public.pacientes'],
        routines: [],
    );

    expect($differences)
        ->toContain('atendimentos: relação ausente: public.atendimentos')
        ->toContain('financeiro-core: rotina ausente: public.financeiro_estornar(text,bigint,text)')
        ->not->toContain('pacientes: relação ausente: public.pacientes');
});
