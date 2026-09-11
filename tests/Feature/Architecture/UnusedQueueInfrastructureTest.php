<?php

it('mantém fila sync sem infraestrutura persistente', function () {
    $queue = (string) file_get_contents(config_path('queue.php'));
    $env = (string) file_get_contents(base_path('.env.example'));

    expect(file_exists(base_path('database/migrations/0001_01_01_000002_create_jobs_table.php')))->toBeFalse()
        ->and($env)->toContain('QUEUE_CONNECTION=sync')
        ->and($queue)->toContain("'sync' => [")
        ->and($queue)->not->toContain("'database' => [")
        ->and($queue)->not->toContain("'failed' => [")
        ->and($queue)->not->toContain("'batching' => [");
});
