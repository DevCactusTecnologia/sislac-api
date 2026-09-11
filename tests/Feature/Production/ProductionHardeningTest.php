<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('não confia em X-Forwarded-Proto vindo diretamente de IP público', function () {
    Route::get('/__test/untrusted-proxy-scheme', fn (Request $request) => $request->getScheme());

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/__test/untrusted-proxy-scheme')
        ->assertOk()
        ->assertSeeText('http');
});

it('mantém produção baseada em Bearer Supabase e deploy nativo', function () {
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
    $env = (string) file_get_contents(base_path('.env.example'));
    $deploy = (string) file_get_contents(base_path('docs/DEPLOY.md'));
    $cors = (string) file_get_contents(base_path('config/cors.php'));
    $routes = (string) file_get_contents(base_path('routes/api.php'));

    expect($bootstrap)
        ->toContain('supabase.auth')
        ->not->toContain('statefulApi()')
        ->not->toContain('RequireSuperAdmin')
        ->not->toContain('EnsureTenantContext')
        ->and($env)
        ->toContain('SUPABASE_URL=')
        ->toContain('SUPABASE_PUBLISHABLE_KEY=')
        ->not->toContain('SANCTUM_STATEFUL_DOMAINS')
        ->not->toContain('DB_ROOT_')
        ->not->toContain('TENANT_DB_')
        ->and($cors)->not->toContain('sanctum/csrf-cookie')
        ->and($routes)->not->toContain('auth:sanctum')
        ->and(file_exists(config_path('sanctum.php')))->toBeFalse()
        ->and($deploy)
        ->toContain('PHP 8.4')
        ->toContain('PHP-FPM')
        ->toContain('Nginx')
        ->toContain('Supabase')
        ->not->toContain('Docker Compose')
        ->not->toContain('docker compose')
        ->not->toContain('PostgreSQL local')
        ->not->toContain('sislac_central');
});
