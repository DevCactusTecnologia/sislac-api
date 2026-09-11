<?php

namespace App\Platform\Supabase;

use Illuminate\Support\Facades\DB;

final readonly class SupabasePermissionAuthorizer
{
    public function allows(string $userId, string $permission): bool
    {
        $row = DB::selectOne(
            'select public.has_permission(?::uuid, ?::text) as allowed',
            [$userId, $permission],
        );

        $allowed = $row->allowed ?? false;

        return in_array($allowed, [true, 1, '1', 't', 'true'], true);
    }
}
