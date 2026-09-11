<?php

namespace App\Http\Middleware;

use App\Platform\Supabase\SupabasePrincipal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

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

        return DB::connection()->transaction(function () use ($request, $next, $userId, $claims): Response {
            DB::selectOne("select set_config('request.jwt.claim.sub', ?, true)", [$userId]);
            DB::selectOne("select set_config('request.jwt.claim.role', 'authenticated', true)");
            DB::selectOne("select set_config('request.jwt.claims', ?, true)", [$claims]);
            DB::statement('set local role authenticated');

            return $next($request);
        });
    }
}
