<?php

namespace App\Http\Middleware;

use App\Platform\Models\Tenant;
use App\Platform\Queries\ActiveTenantMemberships;
use App\Platform\Tenancy\Exceptions\NoActiveMembership;
use App\Platform\Tenancy\Exceptions\TenantNotAuthorized;
use App\Platform\Tenancy\Exceptions\TenantSelectionRequired;
use App\Platform\Tenancy\TenantSelection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureTenantContext
{
    public function __construct(
        private ActiveTenantMemberships $memberships,
        private TenantSelection $selection,
        private Tenancy $tenancy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (! is_string($userId)) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $requestedTenantId = $request->header('X-Tenant');

        if ($requestedTenantId !== null && ! is_string($requestedTenantId)) {
            return $this->forbidden();
        }

        try {
            $tenantId = $this->selection->select(
                $this->memberships->forUser($userId),
                $requestedTenantId,
            );
        } catch (NoActiveMembership|TenantNotAuthorized) {
            return $this->forbidden();
        } catch (TenantSelectionRequired) {
            return response()->json([
                'message' => 'Selecione um laboratório para continuar.',
            ], 409);
        }

        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('status', 'active')
            ->first();

        if ($tenant === null) {
            return $this->forbidden();
        }

        $this->tenancy->initialize($tenant);

        try {
            return $next($request);
        } finally {
            $this->tenancy->end();
        }
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Laboratório não autorizado.',
        ], 403);
    }
}
