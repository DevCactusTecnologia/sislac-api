<?php

namespace App\Http\Controllers\Rotina;

use App\Domain\Atendimentos\Queries\ListRotinaAnalise;
use Illuminate\Http\JsonResponse;

final class ListRotinaAnaliseController
{
    public function __invoke(ListRotinaAnalise $query): JsonResponse
    {
        $result = $query->handle();

        return response()->json([
            'enabled' => $result['enabled'],
            'data' => $result['data']->values(),
        ]);
    }
}
