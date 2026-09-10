<?php

namespace App\Http\Controllers\Financeiro;

use App\Domain\Financeiro\Actions\CloseCaixa;
use App\Http\Requests\Financeiro\CloseCaixaRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

final class CloseCaixaController
{
    public function __invoke(
        CloseCaixaRequest $request,
        CloseCaixa $action,
        int $id,
    ): JsonResponse {
        $userId = $request->user()?->getAuthIdentifier();
        if (! is_string($userId) || $userId === '') {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        try {
            $result = $action->handle($id, $request->validated(), $userId);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $session = $result['session'];

        return response()->json([
            'data' => [
                'sessao_id' => (int) $session->getKey(),
                'unidade_id' => (string) $session->getAttribute('unidade_id'),
                'valor_abertura' => (string) $session->getAttribute('valor_abertura'),
                'entradas_dinheiro' => $result['entradas_dinheiro'],
                'entradas_pix' => $result['entradas_pix'],
                'saidas' => $result['saidas'],
                'saldo_final' => $result['saldo_final'],
                'fechada_em' => $session->getAttribute('fechada_em')?->toISOString(),
            ],
        ]);
    }
}
