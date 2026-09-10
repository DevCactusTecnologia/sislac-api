<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Queries\ListFinanceiroSaidas;
use App\Http\Requests\Financeiro\ListFinanceiroSaidasRequest;
use Illuminate\Http\JsonResponse;

final class ListFinanceiroSaidasController
{
    public function __invoke(
        ListFinanceiroSaidasRequest $request,
        ListFinanceiroSaidas $query,
    ): JsonResponse {
        $result = $query->handle($request->validated());

        return response()->json([
            'data' => $result['data'],
            'meta' => [
                'nextCursor' => $result['next_cursor'],
            ],
        ]);
    }
}
