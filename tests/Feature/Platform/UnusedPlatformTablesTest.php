<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('não cria plans ou subscriptions na baseline central sem consumidor runtime', function () {
    $schema = Schema::connection('central');

    expect($schema->hasTable('plans'))->toBeFalse()
        ->and($schema->hasTable('subscriptions'))->toBeFalse();
});
