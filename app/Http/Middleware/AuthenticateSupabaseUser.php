<?php

namespace App\Http\Middleware;

use App\Platform\Supabase\SupabaseAuth;
use App\Platform\Supabase\SupabasePrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuthenticateSupabaseUser
{
    public const REQUEST_ATTRIBUTE = 'supabase_principal';

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

        $principal = new SupabasePrincipal($identity->id, $identity->email);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $principal);
        $request->setUserResolver(static fn (): SupabasePrincipal => $principal);

        return $next($request);
    }
}
