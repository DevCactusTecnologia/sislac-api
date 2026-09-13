<?php

namespace App\Domain\Financeiro\Models;

use App\Domain\LabModel;

final class FinanceiroEstorno extends LabModel
{
    protected $table = 'financeiro_estornos';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'criado_em' => 'immutable_datetime',
        ];
    }
}
