<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\ReversePacientePayment;
use App\Http\Requests\Financeiro\ReversePacientePaymentRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

final class ReversePacientePaymentController
{
    public function __invoke(
        ReversePacientePaymentRequest $request,
        ReversePacientePayment $action,
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

        $pagamento = $result['pagamento'];
        $estorno = $result['estorno'];

        return response()->json([
            'data' => [
                'pagamento_id' => (int) $pagamento->getKey(),
                'atendimento_id' => (int) $pagamento->getAttribute('atendimento_id'),
                'status_pagamento' => (string) $pagamento->getAttribute('status_pagamento'),
                'valor' => (string) $pagamento->getAttribute('valor'),
                'motivo' => (string) $estorno->getAttribute('motivo'),
                'estorno_id' => (int) $estorno->getKey(),
                'criado_em' => $estorno->getAttribute('criado_em')?->toISOString(),
            ],
        ]);
    }
}
