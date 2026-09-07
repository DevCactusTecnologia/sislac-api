<?php

namespace App\Domain\Atendimentos\Models;

use Illuminate\Database\Eloquent\Model;

final class Atendimento extends Model
{
    protected $table = 'atendimentos';

    protected $guarded = [
        'id',
        'protocolo',
        'status_atendimento',
        'status_pagamento',
        'assinatura_protocolo',
        'senha_consulta',
        'senha_consulta_expira_em',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'immutable_datetime',
            'paciente_nascimento' => 'date:Y-m-d',
            'guia_data' => 'date:Y-m-d',
            'jejum' => 'boolean',
            'tem_retificacao' => 'boolean',
            'senha_consulta_expira_em' => 'immutable_datetime',
            'subtotal' => 'decimal:2',
            'desconto_total' => 'decimal:2',
            'acrescimo_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
