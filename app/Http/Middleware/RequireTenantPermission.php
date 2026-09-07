<?php

namespace App\Http\Middleware;

use App\Platform\Authorization\MembershipAuthorizer;
use App\Platform\Authorization\TenantPermission;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireTenantPermission
{
    public function __construct(private MembershipAuthorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $userId = $request->user()?->getAuthIdentifier();
        $tenantId = $request->attributes->get('tenant_id');
        $required = TenantPermission::tryFrom($permission);

        if ($required === null) {
            throw new LogicException('Permissão tenant não registrada: '.$permission);
        }

        if (! is_string($userId) || ! is_string($tenantId)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        if (! $this->authorizer->allows($userId, $tenantId, $required)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        return $next($request);
    }
}
