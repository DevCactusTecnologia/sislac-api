<?php

namespace App\Http\Controllers\Atendimentos;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Http\Resources\Atendimentos\AtendimentoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowAtendimentoByProtocoloController
{
    public function __invoke(Request $request, string $protocolo): JsonResponse
    {
        $atendimento = Atendimento::query()
            ->with(['exames', 'pagamentos'])
            ->where('protocolo', $protocolo)
            ->firstOrFail();

        return response()->json([
            'data' => (new AtendimentoResource($atendimento))->resolve($request),
        ]);
    }
}
