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

it('mantém o contrato de produção para sessão stateful e otimização', function () {
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
    $env = file_get_contents(base_path('.env.example'));
    $deploy = file_get_contents(base_path('docs/DEPLOY.md'));

    expect($bootstrap)->toContain('trustProxies')
        ->and($env)->not->toContain('VITE_APP_NAME')
        ->and($deploy)->toContain('SESSION_DOMAIN=.sislac.com.br')
        ->and($deploy)->toContain('SANCTUM_STATEFUL_DOMAINS=sislac.com.br,www.sislac.com.br')
        ->and($deploy)->toContain('php artisan optimize')
        ->and($deploy)->not->toContain('php artisan config:cache')
        ->and($deploy)->not->toContain('php artisan route:cache')
        ->and($deploy)->not->toContain('php artisan event:cache');
});
