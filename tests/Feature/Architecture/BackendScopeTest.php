<?php

it('mantém somente a fundação Laravel sobre Supabase', function () {
    $composer = (string) file_get_contents(base_path('composer.json'));
    $providers = (string) file_get_contents(base_path('bootstrap/providers.php'));
    $database = (string) file_get_contents(config_path('database.php'));
    $routes = (string) file_get_contents(base_path('routes/web.php'));

    expect($composer)
        ->not->toContain('stancl/tenancy')
        ->not->toContain('laravel/sanctum')
        ->and(file_exists(config_path('tenancy.php')))->toBeFalse()
        ->and(file_exists(config_path('provisioning.php')))->toBeFalse()
        ->and(file_exists(config_path('sanctum.php')))->toBeFalse()
        ->and(file_exists(base_path('docker-compose.yml')))->toBeFalse()
        ->and(file_exists(base_path('docker')))->toBeFalse()
        ->and(file_exists(app_path('Platform/Provisioning')))->toBeFalse()
        ->and(file_exists(app_path('Platform/Tenancy')))->toBeFalse()
        ->and(file_exists(resource_path('views/admin')))->toBeFalse()
        ->and(file_exists(database_path('migrations/central')))->toBeFalse()
        ->and(file_exists(database_path('migrations/tenant')))->toBeFalse()
        ->and($providers)->not->toContain('PlatformServiceProvider')
        ->and($providers)->not->toContain('TenancyServiceProvider')
        ->and($database)->not->toContain('sislac_central')
        ->and($database)->not->toContain("'central' =>")
        ->and($database)->not->toContain("'tenant_template' =>")
        ->and($database)->not->toContain("'supabase_source' =>")
        ->and($routes)->not->toContain('/admin');
});

it('não mantém a infraestrutura paralela de comparação com o Supabase', function () {
    foreach ([
        app_path('Console/Commands/CheckSupabaseLiveContract.php'),
        app_path('Platform/Supabase/MigratedContractsLiveContract.php'),
        app_path('Platform/Supabase/PacientesLiveContract.php'),
        app_path('Platform/Supabase/SupabaseContractRegistry.php'),
        app_path('Platform/Supabase/SupabaseSource.php'),
        base_path('scripts/check-supabase-contract.php'),
    ] as $legacyPath) {
        expect(file_exists($legacyPath))->toBeFalse($legacyPath);
    }
});

it('não mantém variáveis nem aliases da arquitetura removida', function () {
    $env = (string) file_get_contents(base_path('.env.example'));
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
    $apiRoutes = (string) file_get_contents(base_path('routes/api.php'));

    foreach (['DB_ROOT_', 'TENANT_DB_', 'X-Tenant'] as $legacy) {
        expect($env.$bootstrap.$apiRoutes)->not->toContain($legacy);
    }

    expect($bootstrap)
        ->not->toContain('EnsureTenantContext')
        ->not->toContain('RequireTenantPermission')
        ->not->toContain('RequireSuperAdmin')
        ->and($apiRoutes)
        ->not->toContain("'tenant'")
        ->not->toContain('tenant.permission');
});

it('não provisiona infraestrutura sem consumidor', function () {
    $env = (string) file_get_contents(base_path('.env.example'));
    $cache = (string) file_get_contents(config_path('cache.php'));
    $queue = (string) file_get_contents(config_path('queue.php'));

    expect($env)
        ->not->toContain('REDIS_')
        ->not->toContain('REVERB_')
        ->not->toContain('HORIZON_')
        ->and($cache)->not->toContain("'redis' => [")
        ->and($queue)->not->toContain("'redis' => [");
});
