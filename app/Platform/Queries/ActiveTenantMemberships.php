<?php

namespace App\Platform\Queries;

use Illuminate\Support\Facades\DB;

final class ActiveTenantMemberships
{
    /**
     * @return list<string>
     */
    public function forUser(string $userId): array
    {
        $tenantIds = DB::connection('central')
            ->table('memberships')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->where('memberships.user_id', $userId)
            ->where('memberships.status', 'active')
            ->where('tenants.status', 'active')
            ->pluck('memberships.tenant_id')
            ->map(static fn ($tenantId): string => (string) $tenantId)
            ->all();

        return array_values($tenantIds);
    }
}
