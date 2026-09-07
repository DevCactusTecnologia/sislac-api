<?php

use Illuminate\Support\Str;

it('mantém o backend no escopo database-per-lab com Super Admin Laravel', function () {
    $composer = file_get_contents(base_path('composer.json'));
    $tenancy = file_get_contents(config_path('tenancy.php'));
    $readme = file_get_contents(base_path('README.md'));
    $architecture = file_get_contents(base_path('docs/ARCHITECTURE.md'));

    expect($composer)->toContain('stancl/tenancy')
        ->and($tenancy)->toContain('DatabaseTenancyBootstrapper::class')
        ->and(substr_count($tenancy, "        DatabaseTenancyBootstrapper::class,\n"))->toBe(1)
        ->and(str_contains($tenancy, 'CacheTenancyBootstrapper'))->toBeFalse()
        ->and(str_contains($tenancy, 'FilesystemTenancyBootstrapper'))->toBeFalse()
        ->and(str_contains($tenancy, 'QueueTenancyBootstrapper'))->toBeFalse()
        ->and($readme)->toContain('Super Admin')
        ->and(Str::lower($readme))->toContain('um banco postgresql por laboratório')
        ->and($architecture)->toContain('Super Admin')
        ->and(Str::lower($architecture))->toContain('supabase')
        ->and(file_exists(base_path('database/migrations/tenant')))->toBeTrue()
        ->and(file_exists(base_path('docs/superpowers/specs/2026-09-07-supabase-integration-only-remediation-design.md')))->toBeFalse();
});

it('não provisiona Redis Horizon ou Reverb sem consumidor real', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));
    $env = file_get_contents(base_path('.env.example'));
    $database = file_get_contents(config_path('database.php'));
    $cache = file_get_contents(config_path('cache.php'));
    $queue = file_get_contents(config_path('queue.php'));
    $dockerfile = file_get_contents(base_path('docker/php/Dockerfile'));
    $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($compose)->not->toContain('redis:')
        ->and($compose)->not->toContain('horizon:')
        ->and($compose)->not->toContain('reverb:')
        ->and($env)->not->toContain('REDIS_')
        ->and($env)->not->toContain('REVERB_')
        ->and($database)->not->toContain("'redis' => [")
        ->and($cache)->not->toContain("'redis' => [")
        ->and($queue)->not->toContain("'redis' => [")
        ->and($dockerfile)->not->toContain('pecl install redis')
        ->and($ci)->not->toContain('redis:7-alpine')
        ->and($ci)->not->toContain('extensions: pdo_pgsql, pgsql, redis');
});

it('não mantém configuração futura sem consumidor na fundação', function () {
    $env = file_get_contents(base_path('.env.example'));
    $filesystems = file_get_contents(config_path('filesystems.php'));
    $services = file_get_contents(config_path('services.php'));
    $deploy = file_get_contents(base_path('docs/DEPLOY.md'));

    foreach ([
        'TENANT_DB_NAME_PREFIX',
        'WHATSAPP_META_',
        'PDF_SHARE_SECRET',
        'INTERNAL_WEBHOOK_SECRET',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_BUCKET',
        'AWS_ENDPOINT',
        'AWS_USE_PATH_STYLE_ENDPOINT',
    ] as $orphan) {
        expect($env)->not->toContain($orphan);
    }

    expect($filesystems)->not->toContain("'s3' => [")
        ->and($services)->not->toContain("'ses' => [")
        ->and($deploy)->not->toContain('Redis')
        ->and($deploy)->not->toContain('Horizon')
        ->and($deploy)->not->toContain('Reverb')
        ->and($deploy)->not->toContain('PDF_SHARE_SECRET')
        ->and($deploy)->not->toContain('INTERNAL_WEBHOOK_SECRET');
});

it('injeta a senha do usuário da aplicação no bootstrap PostgreSQL sem fallback nulo', function () {
    $bootstrap = file_get_contents(base_path('docker/postgres/init/01-create-central.sql'));

    expect($bootstrap)->toContain('\\set app_password')
        ->and($bootstrap)->toContain(":'app_password'")
        ->and($bootstrap)->not->toContain("current_setting('SISLAC_APP_PASSWORD'");
});

it('não mantém arquivos ou comandos de scaffold sem função no backend', function () {
    $console = file_get_contents(base_path('routes/console.php'));

    expect($console)->not->toContain('Inspiring')
        ->and($console)->not->toContain("Artisan::command('inspire'")
        ->and(file_exists(base_path('tests/Unit/ExampleTest.php')))->toBeFalse()
        ->and(file_exists(base_path('database/migrations/tenant/.gitkeep')))->toBeFalse()
        ->and(file_exists(public_path('favicon.ico')))->toBeFalse();
});

it('não mantém pipeline frontend sem consumidor no Super Admin Blade', function () {
    $composer = file_get_contents(base_path('composer.json'));
    $layout = file_get_contents(resource_path('views/admin/layout.blade.php'));

    expect($layout)->not->toContain('@vite')
        ->and($composer)->not->toContain('npm install')
        ->and($composer)->not->toContain('npm run build')
        ->and(file_exists(base_path('package.json')))->toBeFalse()
        ->and(file_exists(base_path('vite.config.js')))->toBeFalse()
        ->and(file_exists(resource_path('css/app.css')))->toBeFalse();
});
