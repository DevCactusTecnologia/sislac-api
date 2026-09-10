<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\UpdateFinanceiroSaida;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financeiro\UpdateFinanceiroSaidaRequest;
use App\Http\Resources\Financeiro\FinanceiroSaidaResource;
use DomainException;
use Illuminate\Http\JsonResponse;

final class UpdateFinanceiroSaidaController extends Controller
{
    public function __invoke(
        UpdateFinanceiroSaidaRequest $request,
        UpdateFinanceiroSaida $updateFinanceiroSaida,
        int $id,
    ): JsonResponse {
        try {
            $saida = $updateFinanceiroSaida->handle($id, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => (new FinanceiroSaidaResource($saida))->resolve($request),
        ]);
    }
}
