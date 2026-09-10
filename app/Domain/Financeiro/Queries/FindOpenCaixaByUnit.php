<?php

namespace App\Domain\Financeiro\Queries;

use App\Domain\Financeiro\Models\CaixaSessao;

final class FindOpenCaixaByUnit
{
    public function handle(string $unidadeId): ?CaixaSessao
    {
        return CaixaSessao::query()
            ->where('unidade_id', $unidadeId)
            ->where('status', 'aberta')
            ->latest('aberta_em')
            ->latest('id')
            ->first();
    }
}
