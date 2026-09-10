<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\CreateFinanceiroSaida;
use App\Http\Requests\Financeiro\CreateFinanceiroSaidaRequest;
use App\Http\Resources\Financeiro\FinanceiroSaidaResource;
use Illuminate\Http\JsonResponse;

final class CreateFinanceiroSaidaController
{
    public function __invoke(CreateFinanceiroSaidaRequest $request, CreateFinanceiroSaida $action): JsonResponse
    {
        $saida = $action->handle($request->validated());

        return (new FinanceiroSaidaResource($saida))
            ->response()
            ->setStatusCode(201);
    }
}
