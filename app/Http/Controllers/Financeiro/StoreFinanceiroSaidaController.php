<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\CreateFinanceiroSaida;
use App\Http\Requests\Financeiro\StoreFinanceiroSaidaRequest;
use App\Http\Resources\Financeiro\FinanceiroSaidaResource;
use Illuminate\Http\JsonResponse;

final class StoreFinanceiroSaidaController
{
    public function __invoke(
        StoreFinanceiroSaidaRequest $request,
        CreateFinanceiroSaida $action,
    ): JsonResponse {
        $saida = $action->handle($request->validated());

        return response()->json([
            'data' => (new FinanceiroSaidaResource($saida))->resolve($request),
        ], 201);
    }
}
