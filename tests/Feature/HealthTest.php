<?php

it('responde ao health check com o banco acessível', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('app', config('app.name'))
        ->assertJsonPath('database.connection', config('database.default'))
        ->assertJsonPath('database.status', 'ok');
});

it('não expõe versões do PHP ou do framework', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonMissingPath('php')
        ->assertJsonMissingPath('laravel');
});
