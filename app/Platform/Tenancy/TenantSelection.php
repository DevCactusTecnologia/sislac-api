<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Exceptions\NoActiveMembership;
use App\Platform\Tenancy\Exceptions\TenantNotAuthorized;
use App\Platform\Tenancy\Exceptions\TenantSelectionRequired;

final class TenantSelection
{
    /**
     * @param  list<string>  $activeTenantIds
     */
    public function select(array $activeTenantIds, ?string $requestedTenantId): string
    {
        $authorizedTenantIds = array_values(array_unique($activeTenantIds));

        if ($authorizedTenantIds === []) {
            throw new NoActiveMembership;
        }

        if ($requestedTenantId !== null) {
            if (! in_array($requestedTenantId, $authorizedTenantIds, true)) {
                throw new TenantNotAuthorized;
            }

            return $requestedTenantId;
        }

        if (count($authorizedTenantIds) === 1) {
            return $authorizedTenantIds[0];
        }

        throw new TenantSelectionRequired;
    }
}
