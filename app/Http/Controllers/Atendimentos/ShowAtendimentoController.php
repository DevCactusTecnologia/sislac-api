<?php

namespace App\Http\Controllers\Atendimentos;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Http\Resources\Atendimentos\AtendimentoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowAtendimentoController
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $atendimento = Atendimento::query()
            ->with(['exames', 'pagamentos'])
            ->findOrFail($id);

        return response()->json([
            'data' => (new AtendimentoResource($atendimento))->resolve($request),
        ]);
    }
}
