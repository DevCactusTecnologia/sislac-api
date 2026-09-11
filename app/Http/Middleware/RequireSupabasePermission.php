<?php

namespace App\Http\Middleware;

use App\Platform\Supabase\SupabaseAuthUser;
use App\Platform\Supabase\SupabasePermissionAuthorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireSupabasePermission
{
    public function __construct(private SupabasePermissionAuthorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $principal = $request->attributes->get(AuthenticateSupabaseUser::REQUEST_ATTRIBUTE);

        if (! $principal instanceof SupabaseAuthUser) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        if (! $this->authorizer->allows($principal->getKey(), $permission)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        return $next($request);
    }
}
