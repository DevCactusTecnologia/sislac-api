<?php

use Illuminate\Routing\Route;

it('não expõe DELETE para saídas financeiras', function () {
    $hasDelete = collect(app('router')->getRoutes()->getRoutes())
        ->contains(function (Route $route): bool {
            return str_starts_with($route->uri(), 'api/financeiro/saidas')
                && in_array('DELETE', $route->methods(), true);
        });

    expect($hasDelete)->toBeFalse();
});
