<?php

namespace App\Http\Controllers\Admin\Tenants;

use Illuminate\Contracts\View\View;

final class CreateTenantController
{
    public function __invoke(): View
    {
        return view('admin.tenants.create');
    }
}
