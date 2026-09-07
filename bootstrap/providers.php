<?php

use App\Providers\AppServiceProvider;
use App\Providers\PlatformServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    TenancyServiceProvider::class,
];
