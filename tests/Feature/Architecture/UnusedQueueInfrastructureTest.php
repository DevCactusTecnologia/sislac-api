<?php

it('não mantém fila persistente sem consumidor runtime', function () {
    expect(file_exists(base_path('database/migrations/0001_01_01_000002_create_jobs_table.php')))->toBeFalse()
        ->and(file_get_contents(base_path('.env.example')))->toContain('QUEUE_CONNECTION=sync');
});
