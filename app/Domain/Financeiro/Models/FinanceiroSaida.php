<?php

namespace App\Domain\Financeiro\Models;

use App\Domain\LabModel;

final class FinanceiroSaida extends LabModel
{
    protected $table = 'financeiro_saidas';

    protected $guarded = [
        'id',
        'protocolo',
        'assinatura_protocolo',
        'foi_pago',
        'caixa_sessao_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'immutable_datetime',
            'valor' => 'decimal:2',
            'data_vencimento' => 'immutable_date',
            'foi_pago' => 'boolean',
            'data_pagamento' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
