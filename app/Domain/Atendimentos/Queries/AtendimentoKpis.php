<?php

namespace App\Domain\Atendimentos\Queries;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Models\AtendimentoExame;
use Illuminate\Database\Eloquent\Builder;

final class AtendimentoKpis
{
    private readonly ListAtendimentos $listAtendimentos;

    public function __construct(ListAtendimentos $listAtendimentos)
    {
        $this->listAtendimentos = $listAtendimentos;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total:int,aguardando_coleta:int,em_analise:int,pendentes:int,finalizados:int,receita_total:string}
     */
    public function handle(array $filters): array
    {
        $base = Atendimento::query();
        $this->listAtendimentos->applyFilters($base, $filters);

        $total = (clone $base)->count();

        $aguardandoColeta = (clone $base)
            ->whereHas('exames', fn (Builder $query): Builder => $query->where('status', 'pendente'))
            ->count();

        $emAnalise = (clone $base)
            ->whereHas('exames', fn (Builder $query): Builder => $query->whereIn('status', [
                'coletado',
                'em_bancada',
                'analisado',
                'em_analise',
                'digitado',
            ]))
            ->count();

        $pendentes = (clone $base)
            ->whereHas('exames', fn (Builder $query): Builder => $query->whereNotIn('status', ['finalizado', 'cancelado']))
            ->count();

        $finalizados = (clone $base)
            ->whereHas('exames', fn (Builder $query): Builder => $query->where('status', 'finalizado'))
            ->whereDoesntHave('exames', fn (Builder $query): Builder => $query->whereNotIn('status', ['finalizado', 'cancelado']))
            ->count();

        $receita = AtendimentoExame::query()
            ->where('status', '<>', 'cancelado')
            ->whereHas(
                'atendimento',
                fn (Builder $query) => $this->listAtendimentos->applyFilters($query, $filters),
            )
            ->sum('valor');

        return [
            'total' => $total,
            'aguardando_coleta' => $aguardandoColeta,
            'em_analise' => $emAnalise,
            'pendentes' => $pendentes,
            'finalizados' => $finalizados,
            'receita_total' => number_format((float) $receita, 2, '.', ''),
        ];
    }
}
