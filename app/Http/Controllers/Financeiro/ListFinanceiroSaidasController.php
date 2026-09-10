<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Queries\ListFinanceiroSaidas;
use App\Http\Requests\Financeiro\ListFinanceiroSaidasRequest;
use App\Http\Resources\Financeiro\FinanceiroSaidaResource;
use Illuminate\Http\JsonResponse;

final class ListFinanceiroSaidasController
{
    public function __invoke(ListFinanceiroSaidasRequest $request, ListFinanceiroSaidas $query): JsonResponse
    {
        $result = $query->handle($request->validated());

        return response()->json([
            'data' => array_map(
                static fn ($saida): array => (new FinanceiroSaidaResource($saida))->resolve($request),
                $result['data'],
            ),
            'meta' => [
                'next_cursor' => $result['next_cursor'],
            ],
        ]);
    }
}
