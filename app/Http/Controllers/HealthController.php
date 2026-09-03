<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Verificação de saúde da API (GET /api/health).
 *
 * Responde 200 quando a aplicação sobe e o banco central atende; 503 quando o
 * banco não responde. Não expõe versões nem detalhes internos — é o endpoint
 * que o balanceador, o monitor e o smoke test do deploy consultam.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $connection = config('database.default');

        try {
            DB::connection($connection)->getPdo();
            $database = 'ok';
        } catch (Throwable) {
            $database = 'error';
        }

        return response()->json([
            'app' => config('app.name'),
            'env' => app()->environment(),
            'time' => now()->toIso8601String(),
            'database' => [
                'connection' => $connection,
                'status' => $database,
            ],
        ], $database === 'ok' ? 200 : 503);
    }
}
