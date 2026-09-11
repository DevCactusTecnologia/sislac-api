<?php

namespace App\Http\Middleware;

use App\Platform\Supabase\SupabaseAuthUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ApplySupabaseDatabaseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->attributes->get(AuthenticateSupabaseUser::REQUEST_ATTRIBUTE);

        if (! $principal instanceof SupabaseAuthUser) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $claims = json_encode(array_filter([
            'sub' => $principal->getKey(),
            'role' => 'authenticated',
            'email' => $principal->getAttribute('email'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''), JSON_THROW_ON_ERROR);

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $connection->selectOne("select set_config('request.jwt.claims', ?, true)", [$claims]);
            $connection->statement('SET LOCAL ROLE authenticated');

            $response = $next($request);

            if ($response->getStatusCode() >= 400) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }

            return $response;
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }
}
