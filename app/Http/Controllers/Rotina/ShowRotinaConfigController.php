<?php

namespace App\Http\Controllers\Rotina;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ShowRotinaConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $mode = DB::table('lab_config')->value('rotina_fluxo_modo');

        return response()->json([
            'data' => [
                'rotina_fluxo_modo' => is_string($mode) ? $mode : 'completo',
            ],
        ]);
    }
}
