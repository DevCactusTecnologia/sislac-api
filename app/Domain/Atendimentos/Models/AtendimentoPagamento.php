<?php

namespace App\Domain\Atendimentos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AtendimentoPagamento extends Model
{
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

    /** @return BelongsTo<Atendimento, $this> */
    public function atendimento(): BelongsTo
    {
        return $this->belongsTo(Atendimento::class);
    }
}
