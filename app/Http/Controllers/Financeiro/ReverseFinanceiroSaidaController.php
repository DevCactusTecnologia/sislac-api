<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\ReverseFinanceiroSaida;
use App\Http\Requests\Financeiro\ReverseFinanceiroSaidaRequest;
use App\Http\Resources\Financeiro\FinanceiroSaidaResource;
use DomainException;
use Illuminate\Http\JsonResponse;

final class ReverseFinanceiroSaidaController
{
    public function __invoke(
        ReverseFinanceiroSaidaRequest $request,
        ReverseFinanceiroSaida $action,
        int $id,
    ): JsonResponse {
        $userId = $request->user()?->getAuthIdentifier();
        if (! is_string($userId) || $userId === '') {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        try {
            $result = $action->handle($id, (string) $request->validated('motivo'), $userId);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $saida = $result['saida'];
        $estorno = $result['estorno'];

        return response()->json([
            'data' => [
                'saida' => (new FinanceiroSaidaResource($saida))->resolve($request),
                'estorno' => [
                    'id' => (int) $estorno->getKey(),
                    'origem_tipo' => (string) $estorno->getAttribute('origem_tipo'),
                    'origem_id' => (int) $estorno->getAttribute('origem_id'),
                    'motivo' => (string) $estorno->getAttribute('motivo'),
                    'valor' => (string) $estorno->getAttribute('valor'),
                    'criado_por' => (string) $estorno->getAttribute('criado_por'),
                    'criado_em' => $estorno->getAttribute('criado_em')?->toISOString(),
                ],
            ],
        ]);
    }
}
