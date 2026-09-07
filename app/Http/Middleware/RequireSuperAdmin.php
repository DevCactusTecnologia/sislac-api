<?php

namespace App\Http\Middleware;

use App\Platform\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! (bool) $user->getAttribute('is_super_admin')) {
            abort(403);
        }

        return $next($request);
    }
}
