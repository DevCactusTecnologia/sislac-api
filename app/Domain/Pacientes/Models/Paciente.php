<?php

namespace App\Domain\Pacientes\Models;

use App\Domain\LabModel;

final class Paciente extends LabModel
{
    protected $table = 'pacientes';

    protected $guarded = [
        'id',
        'friendly_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date:Y-m-d',
            'consentimento_lgpd' => 'boolean',
            'consentimento_em' => 'immutable_datetime',
        ];
    }
}
