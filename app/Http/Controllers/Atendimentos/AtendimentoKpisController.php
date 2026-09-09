<?php

namespace App\Http\Controllers\Atendimentos;

use App\Domain\Atendimentos\Queries\AtendimentoKpis;
use App\Http\Requests\Atendimentos\ListAtendimentosRequest;
use Illuminate\Http\JsonResponse;

final class AtendimentoKpisController
{
    public function __invoke(ListAtendimentosRequest $request, AtendimentoKpis $query): JsonResponse
    {
        return response()->json($query->handle($request->validated()));
    }
}
