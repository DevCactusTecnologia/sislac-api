<?php

namespace App\Http\Middleware;

use App\Platform\Supabase\SupabasePrincipal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class UseSupabaseDatabaseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get(AuthenticateSupabaseUser::REQUEST_ATTRIBUTE);

        if (! $user instanceof SupabasePrincipal) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $userId = $user->getKey();
        $email = $user->getAttribute('email');
        $claims = json_encode(array_filter([
            'sub' => $userId,
            'role' => 'authenticated',
            'email' => is_string($email) && $email !== '' ? $email : null,
        ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $connection->selectOne("select set_config('request.jwt.claim.sub', ?, true)", [$userId]);
            $connection->selectOne("select set_config('request.jwt.claim.role', 'authenticated', true)");
            $connection->selectOne("select set_config('request.jwt.claims', ?, true)", [$claims]);
            $connection->statement('set local role authenticated');

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
