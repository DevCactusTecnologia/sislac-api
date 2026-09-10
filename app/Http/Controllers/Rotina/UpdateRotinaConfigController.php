<?php

namespace App\Http\Controllers\Rotina;

use App\Domain\Atendimentos\Actions\UpdateRotinaConfig;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rotina\UpdateRotinaConfigRequest;
use Illuminate\Http\JsonResponse;

final class UpdateRotinaConfigController extends Controller
{
    public function __invoke(
        UpdateRotinaConfigRequest $request,
        UpdateRotinaConfig $action,
    ): JsonResponse {
        $payload = $request->validated();
        $mode = $payload['rotina_fluxo_modo'];

        return response()->json([
            'data' => $action->handle(is_string($mode) ? $mode : ''),
        ]);
    }
}
