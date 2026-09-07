<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

it('mantém as rotas de sessão no pipeline stateful do Sanctum', function () {
    $sessionRoute = Route::getRoutes()->getByName('auth.session');

    expect($sessionRoute)->not->toBeNull()
        ->and($sessionRoute?->middleware())->toContain('api')
        ->and($sessionRoute?->middleware())->toContain('auth:sanctum')
        ->and(app('router')->getMiddlewareGroups()['api'])
        ->toContain(EnsureFrontendRequestsAreStateful::class);
});

it('expõe o endpoint oficial de inicialização CSRF do Sanctum', function () {
    $this->get('/sanctum/csrf-cookie')->assertNoContent();
});
