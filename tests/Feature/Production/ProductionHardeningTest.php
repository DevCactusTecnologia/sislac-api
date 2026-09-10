<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('respeita HTTPS encaminhado apenas a partir do proxy Docker confiável', function () {
    Route::get('/__test/proxy-scheme', fn (Request $request) => $request->getScheme());

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.10'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/__test/proxy-scheme')
        ->assertOk()
        ->assertSeeText('https');
});

it('não confia em X-Forwarded-Proto vindo diretamente de IP público', function () {
    Route::get('/__test/untrusted-proxy-scheme', fn (Request $request) => $request->getScheme());

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/__test/untrusted-proxy-scheme')
        ->assertOk()
        ->assertSeeText('http');
});

it('não expõe a rota csrf do Sanctum sem consumidor clínico', function () {
    $this->get('/sanctum/csrf-cookie')->assertNotFound();
});

it('mantém o contrato de produção para bearer clínico e otimização', function () {
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
    $env = (string) file_get_contents(base_path('.env.example'));
    $deploy = (string) file_get_contents(base_path('docs/DEPLOY.md'));
    $cors = (string) file_get_contents(base_path('config/cors.php'));
    $routes = (string) file_get_contents(base_path('routes/api.php'));
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));
    $sanctum = (string) file_get_contents(base_path('config/sanctum.php'));

    expect($bootstrap)->toContain('trustProxies')
        ->and($bootstrap)->not->toContain("trustProxies(at: '*'")
        ->and($bootstrap)->not->toContain('statefulApi()')
        ->and($env)->not->toContain('VITE_APP_NAME')
        ->and($env)->not->toContain('SANCTUM_STATEFUL_DOMAINS')
        ->and($cors)->not->toContain('sanctum/csrf-cookie')
        ->and($routes)->not->toContain('auth:sanctum')
        ->and($phpunit)->not->toContain('SANCTUM_STATEFUL_DOMAINS')
        ->and($sanctum)->toContain("'routes' => false")
        ->and($deploy)->toContain('SUPABASE_URL=')
        ->and($deploy)->toContain('SUPABASE_PUBLISHABLE_KEY=')
        ->and($deploy)->toContain('proxy_set_header X-Forwarded-For $remote_addr;')
        ->and($deploy)->not->toContain('$proxy_add_x_forwarded_for')
        ->and($deploy)->toContain('php artisan optimize')
        ->and($deploy)->not->toContain('php artisan config:cache')
        ->and($deploy)->not->toContain('php artisan route:cache')
        ->and($deploy)->not->toContain('php artisan event:cache');
});
