<?php

namespace App\Domain\Atendimentos\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ListRotinaAnalise
{
    /** @return array{enabled:bool,data:Collection<int, stdClass>} */
    public function handle(): array
    {
        if ($this->mode() !== 'completo') {
            return [
                'enabled' => false,
                'data' => collect(),
            ];
        }

        $rows = DB::table('atendimento_exames as exame')
            ->join('atendimentos as atendimento', 'atendimento.id', '=', 'exame.atendimento_id')
            ->select([
                'exame.id',
                'exame.atendimento_id',
                'atendimento.protocolo',
                'atendimento.data as atendimento_data',
                'atendimento.paciente_id',
                'atendimento.paciente_nome',
                'exame.nome_exame',
                'exame.status',
                'exame.ordem',
                'exame.amostra_seq',
                'exame.data_coleta',
                'exame.coletor',
                'exame.data_analise',
                'exame.analista',
            ])
            ->where('exame.tipo_processo', 'INTERNO')
            ->whereIn('exame.status', ['coletado', 'em_bancada'])
            ->orderByDesc('atendimento.data')
            ->orderBy('exame.id')
            ->get();

        return [
            'enabled' => true,
            'data' => $rows,
        ];
    }

    private function mode(): string
    {
        $mode = DB::table('lab_config')
            ->where('singleton_key', 1)
            ->value('rotina_fluxo_modo');

        return is_string($mode) && $mode !== '' ? $mode : 'completo';
    }
}
