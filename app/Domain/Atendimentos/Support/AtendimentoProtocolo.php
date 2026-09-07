<?php

namespace App\Domain\Atendimentos\Support;

use Illuminate\Support\Facades\DB;

final class AtendimentoProtocolo
{
    public function next(): string
    {
        do {
            $row = DB::selectOne(<<<'SQL'
                INSERT INTO protocolo_sequence (prefixo, ano, ultimo_numero)
                VALUES ('ATD', 0, 1)
                ON CONFLICT (prefixo, ano)
                DO UPDATE SET ultimo_numero = protocolo_sequence.ultimo_numero + 1,
                              updated_at = now()
                RETURNING ultimo_numero
            SQL);

            $number = (int) $row->ultimo_numero;
            $protocol = str_pad((string) $number, 7, '0', STR_PAD_LEFT);
        } while (DB::table('atendimentos')->where('protocolo', $protocol)->exists());

        return $protocol;
    }
}
