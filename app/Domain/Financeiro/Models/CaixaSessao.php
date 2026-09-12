<?php

namespace App\Domain\Financeiro\Models;

use App\Domain\LabModel;

final class CaixaSessao extends LabModel
{
    protected $table = 'caixa_sessoes';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'aberta_em' => 'immutable_datetime',
            'fechada_em' => 'immutable_datetime',
            'valor_abertura' => 'decimal:2',
            'valor_fechamento' => 'decimal:2',
        ];
    }
}
