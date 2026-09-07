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
