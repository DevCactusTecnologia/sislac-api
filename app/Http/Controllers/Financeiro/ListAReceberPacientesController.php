<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Queries\ListAReceberPacientes;
use App\Http\Requests\Financeiro\ListAReceberPacientesRequest;
use Illuminate\Http\JsonResponse;

final class ListAReceberPacientesController
{
    public function __invoke(ListAReceberPacientesRequest $request, ListAReceberPacientes $query): JsonResponse
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
