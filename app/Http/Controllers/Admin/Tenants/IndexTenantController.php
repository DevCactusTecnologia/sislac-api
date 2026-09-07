<?php

namespace App\Http\Controllers\Admin\Tenants;

use App\Platform\Models\Tenant;
use Illuminate\Contracts\View\View;

final class IndexTenantController
{
    public function __invoke(): View
    {
        return view('admin.tenants.index', [
            'tenants' => Tenant::query()->orderBy('name')->get(),
        ]);
    }
}
