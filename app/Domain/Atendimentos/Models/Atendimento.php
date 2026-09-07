<?php

namespace App\Domain\Atendimentos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Atendimento extends Model
{
    protected $guarded = [
        'id',
        'protocolo',
        'status_atendimento',
        'status_pagamento',
        'subtotal',
        'desconto_total',
        'acrescimo_total',
        'total',
        'assinatura_protocolo',
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

    /** @return HasMany<AtendimentoExame, $this> */
    public function exames(): HasMany
    {
        return $this->hasMany(AtendimentoExame::class);
    }

    /** @return HasMany<AtendimentoPagamento, $this> */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(AtendimentoPagamento::class);
    }
}
