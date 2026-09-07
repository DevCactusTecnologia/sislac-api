<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;

final class AdminLoginController
{
    public function __invoke(): View
    {
        return view('admin.login');
    }
}
