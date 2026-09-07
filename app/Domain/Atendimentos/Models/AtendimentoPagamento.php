<?php

namespace App\Domain\Atendimentos\Models;

use Illuminate\Database\Eloquent\Model;

final class AtendimentoPagamento extends Model
{
    protected $table = 'atendimento_pagamentos';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'immutable_datetime',
            'valor' => 'decimal:2',
        ];
    }
}
