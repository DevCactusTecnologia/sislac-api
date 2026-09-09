<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('audita tabelas preventivas sem executar cleanup destrutivo', function () {
    $this->artisan('platform:audit-unused-tables')
        ->expectsOutput('plans: ausente')
        ->expectsOutput('subscriptions: ausente')
        ->assertSuccessful();
});
