<?php

namespace App\Http\Middleware;

use App\Platform\Models\User;
use App\Support\LaboratoryDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UseLaboratoryDatabase
{
    public function __construct(
        private LaboratoryDatabase $database,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $laboratory = $user->laboratory;

        if ($laboratory === null || $laboratory->status !== 'active') {
            return response()->json(['message' => 'Laboratório indisponível.'], 403);
        }

        $this->database->connect($laboratory);

        try {
            return $next($request);
        } finally {
            $this->database->disconnect();
        }
    }
}
