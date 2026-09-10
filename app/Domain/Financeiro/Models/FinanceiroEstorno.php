<?php

namespace App\Domain\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;

final class FinanceiroEstorno extends Model
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
