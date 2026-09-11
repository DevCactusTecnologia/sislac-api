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

        $userId = $principal->getKey();
        $email = $principal->getAttribute('email');
        $claims = json_encode(array_filter([
            'sub' => $userId,
            'role' => 'authenticated',
            'email' => is_string($email) && $email !== '' ? $email : null,
        ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $connection->statement('SET LOCAL ROLE authenticated');
            $connection->selectOne("select set_config('request.jwt.claim.sub', ?, true)", [$userId]);
            $connection->selectOne("select set_config('request.jwt.claim.role', 'authenticated', true)");
            $connection->selectOne("select set_config('request.jwt.claims', ?, true)", [$claims]);

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
