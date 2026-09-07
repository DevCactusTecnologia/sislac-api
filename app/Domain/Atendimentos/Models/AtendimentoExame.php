<?php

namespace App\Domain\Atendimentos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AtendimentoExame extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'resultados' => 'array',
            'data_coleta' => 'immutable_datetime',
            'data_analise' => 'immutable_datetime',
            'data_liberacao' => 'immutable_datetime',
            'data_envio' => 'immutable_datetime',
            'data_retorno' => 'immutable_datetime',
            'integracao_ativa' => 'boolean',
            'resultado_importado' => 'boolean',
            'is_reutilizacao' => 'boolean',
            'retificado' => 'boolean',
            'retificado_at' => 'immutable_datetime',
            'pdf_override_uploaded_at' => 'immutable_datetime',
            'valor' => 'decimal:2',
            'valor_original' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Atendimento, $this> */
    public function atendimento(): BelongsTo
    {
        return $this->belongsTo(Atendimento::class);
    }
}
