<?php

namespace App\Http\Controllers\Admin;

use App\Platform\Models\Tenant;
use Illuminate\Contracts\View\View;

final class AdminDashboardController
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'totalTenants' => Tenant::query()->count(),
            'activeTenants' => Tenant::query()->where('status', 'active')->count(),
        ]);
    }
}
