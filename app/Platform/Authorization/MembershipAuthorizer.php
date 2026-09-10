<?php

namespace App\Platform\Authorization;

use Illuminate\Support\Facades\DB;

final class MembershipAuthorizer
{
    public function allows(string $userId, string $tenantId, TenantPermission $permission): bool
    {
        $membership = DB::connection('central')
            ->table('memberships')
            ->select(['role', 'permissions_extra', 'permissions_revoked'])
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            return false;
        }

        $revoked = $this->decodePermissions($membership->permissions_revoked);
        $extra = $this->decodePermissions($membership->permissions_extra);

        if (in_array($permission->value, $revoked, true)) {
            return false;
        }

        if (in_array($permission->value, $extra, true)) {
            return true;
        }

        $role = is_string($membership->role) ? $membership->role : '';

        if ($role === 'admin') {
            return true;
        }

        return match ($role) {
            'recepcionista' => in_array($permission, [
                TenantPermission::ViewPatients,
                TenantPermission::CreatePatient,
                TenantPermission::EditPatient,
                TenantPermission::ViewAppointments,
                TenantPermission::CreateAppointment,
                TenantPermission::EditAppointment,
                TenantPermission::CancelAppointment,
                TenantPermission::RegisterPayment,
            ], true),
            'analista' => in_array($permission, [
                TenantPermission::ViewPatients,
                TenantPermission::ViewAppointments,
            ], true),
            'financeiro' => in_array($permission, [
                TenantPermission::ViewPatients,
                TenantPermission::ViewAppointments,
                TenantPermission::RegisterPayment,
                TenantPermission::ViewFinance,
                TenantPermission::FinancialManagement,
            ], true),
            default => false,
        };
    }

    /** @return list<string> */
    private function decodePermissions(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }
}
