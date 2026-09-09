<?php

namespace App\Http\Middleware;

use App\Platform\Models\User;
use App\Platform\Supabase\SupabaseAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuthenticateSupabaseUser
{
    public function __construct(private SupabaseAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->bearerToken();

        if (! is_string($accessToken) || $accessToken === '') {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        try {
            $identity = $this->auth->user($accessToken);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Serviço de autenticação indisponível.',
            ], 503);
        }

        if ($identity === null) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $user = User::query()->find($identity->id);

        if ($user === null) {
            return response()->json([
                'message' => 'Usuário ainda não provisionado no Laravel.',
            ], 403);
        }

        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
