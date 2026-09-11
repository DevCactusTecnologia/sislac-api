<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => null,
        ],
    ],

    'providers' => [],
    'passwords' => [],
    'password_timeout' => 10800,
];
