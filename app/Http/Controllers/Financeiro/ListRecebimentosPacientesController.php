<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Queries\ListRecebimentosPacientes;
use App\Http\Requests\Financeiro\ListRecebimentosPacientesRequest;
use Illuminate\Http\JsonResponse;

final class ListRecebimentosPacientesController
{
    public function __invoke(ListRecebimentosPacientesRequest $request, ListRecebimentosPacientes $query): JsonResponse
    {
        $result = $query->handle($request->validated());

        return response()->json([
            'data' => $result['data'],
            'meta' => [
                'nextCursor' => $result['next_cursor'],
            ],
        ]);
    }
}
