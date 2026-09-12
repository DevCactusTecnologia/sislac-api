<?php

namespace App\Platform\Authorization;

use App\Platform\Models\User;

final class UserAuthorizer
{
    public function allows(User $user, TenantPermission $permission): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        $revoked = $this->permissions($user->permissions_revoked);

        if (in_array($permission->value, $revoked, true)) {
            return false;
        }

        $extra = $this->permissions($user->permissions_extra);

        if (in_array($permission->value, $extra, true)) {
            return true;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return in_array($permission, $this->defaultsFor($user->role), true);
    }

    /** @return list<TenantPermission> */
    private function defaultsFor(mixed $role): array
    {
        return match ($role) {
            'analista' => [
                TenantPermission::ViewPatients,
                TenantPermission::ViewAppointments,
                TenantPermission::RegisterCollection,
                TenantPermission::AnalyzeSample,
            ],
            'recepcionista' => [
                TenantPermission::ViewPatients,
                TenantPermission::CreatePatient,
                TenantPermission::EditPatient,
                TenantPermission::ViewAppointments,
                TenantPermission::CreateAppointment,
                TenantPermission::EditAppointment,
                TenantPermission::RegisterPayment,
                TenantPermission::RegisterCollection,
            ],
            'financeiro' => [
                TenantPermission::ViewPatients,
                TenantPermission::ViewAppointments,
                TenantPermission::RegisterPayment,
                TenantPermission::ViewFinance,
                TenantPermission::FinancialManagement,
            ],
            default => [],
        };
    }

    /** @return list<string> */
    private function permissions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
