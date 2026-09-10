<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\UpdateFinanceiroSaida;
use App\Http\Requests\Financeiro\UpdateFinanceiroSaidaRequest;
use App\Http\Resources\Financeiro\FinanceiroSaidaResource;
use DomainException;
use Illuminate\Http\JsonResponse;

final class UpdateFinanceiroSaidaController
{
    public function __invoke(
        UpdateFinanceiroSaidaRequest $request,
        UpdateFinanceiroSaida $action,
        int $id,
    ): JsonResponse {
        try {
            $saida = $action->handle($id, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => (new FinanceiroSaidaResource($saida))->resolve($request),
        ]);
    }
}
