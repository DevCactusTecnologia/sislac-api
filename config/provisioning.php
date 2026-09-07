<?php

return [
    'database' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'username' => env('DB_ROOT_USER'),
        'password' => env('DB_ROOT_PASSWORD'),
        'maintenance_database' => env('DB_ROOT_DATABASE', 'postgres'),
        'application_role' => env('TENANT_DB_USERNAME', env('DB_USERNAME', 'sislac_app')),
    ],
];
