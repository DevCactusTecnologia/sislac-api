<?php

namespace App\Domain\Atendimentos\Models;

use Illuminate\Database\Eloquent\Model;

final class AtendimentoExame extends Model
{
    protected $table = 'atendimento_exames';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'data_coleta' => 'immutable_datetime',
            'data_analise' => 'immutable_datetime',
            'data_liberacao' => 'immutable_datetime',
            'resultados' => 'array',
            'integracao_ativa' => 'boolean',
            'data_envio' => 'immutable_datetime',
            'data_retorno' => 'immutable_datetime',
            'resultado_importado' => 'boolean',
            'is_reutilizacao' => 'boolean',
            'pdf_override_uploaded_at' => 'immutable_datetime',
            'retificado' => 'boolean',
            'retificado_at' => 'immutable_datetime',
            'valor' => 'decimal:2',
            'valor_original' => 'decimal:2',
        ];
    }
}
