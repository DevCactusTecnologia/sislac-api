<?php

use App\Platform\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\UUIDGenerator;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UUIDGenerator::class,
    'domain_model' => Domain::class,
    'central_domains' => ['127.0.0.1', 'localhost'],

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'central',
        'template_tenant_connection' => 'tenant_template',
        'prefix' => '',
        'suffix' => '',
        'managers' => [
            'pgsql' => PostgreSQLDatabaseManager::class,
        ],
    ],

    'features' => [],
    'routes' => false,

    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],
];
