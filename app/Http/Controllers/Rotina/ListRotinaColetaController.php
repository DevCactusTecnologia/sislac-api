<?php

namespace App\Http\Controllers\Rotina;

use App\Domain\Atendimentos\Queries\ListRotinaColeta;
use Illuminate\Http\JsonResponse;

final class ListRotinaColetaController
{
    public function __invoke(ListRotinaColeta $query): JsonResponse
    {
        $result = $query->handle();

        return response()->json([
            'enabled' => $result['enabled'],
            'data' => $result['data']->values(),
        ]);
    }
}
