<?php

namespace App\Domain\Pacientes\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PacienteFriendlyId
{
    public function next(): string
    {
        $row = DB::selectOne(<<<'SQL'
            INSERT INTO friendly_id_counters (scope, next_value)
            VALUES ('paciente', 2)
            ON CONFLICT (scope)
            DO UPDATE SET next_value = friendly_id_counters.next_value + 1
            RETURNING next_value - 1 AS value
        SQL);

        $value = $row->value;

        if (! is_numeric($value)) {
            throw new RuntimeException('Não foi possível gerar o friendly_id do paciente.');
        }

        return sprintf('PAC-%06d', (int) $value);
    }
}
