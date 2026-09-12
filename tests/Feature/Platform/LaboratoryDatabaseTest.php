<?php

use App\Models\Laboratory;
use App\Support\LaboratoryDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->laboratoryDatabases = [];
});

afterEach(function () {
    DB::purge('lab');

    foreach ($this->laboratoryDatabases as $database) {
        laboratoryControlPdo()->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }
});

function laboratoryControlPdo(): PDO
{
    $config = config('database.connections.central');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function createLaboratoryDatabase(string $database, string $marker): void
{
    if (preg_match('/^[a-z0-9_]+$/', $database) !== 1) {
        throw new InvalidArgumentException('Nome de banco de teste inválido.');
    }

    laboratoryControlPdo()->exec('CREATE DATABASE "'.$database.'"');

    $config = config('database.connections.central');
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $database),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('CREATE TABLE laboratory_marker (value text NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO laboratory_marker (value) VALUES (?)');
    $statement->execute([$marker]);
}

function laboratoryDatabaseUrl(string $database): string
{
    $config = config('database.connections.central');

    return sprintf(
        'postgresql://%s:%s@%s:%s/%s',
        rawurlencode((string) $config['username']),
        rawurlencode((string) $config['password']),
        $config['host'],
        $config['port'],
        $database,
    );
}

it('troca a conexão lab sem reutilizar o banco do laboratório anterior', function () {
    $databaseA = 'sislac_lab_a_'.Str::lower(Str::random(10));
    $databaseB = 'sislac_lab_b_'.Str::lower(Str::random(10));
    $this->laboratoryDatabases = [$databaseA, $databaseB];

    createLaboratoryDatabase($databaseA, 'LAB_A');
    createLaboratoryDatabase($databaseB, 'LAB_B');

    $laboratoryA = Laboratory::query()->create([
        'name' => 'Laboratório A',
        'code' => 'lab-a-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_url' => laboratoryDatabaseUrl($databaseA),
    ]);
    $laboratoryB = Laboratory::query()->create([
        'name' => 'Laboratório B',
        'code' => 'lab-b-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_url' => laboratoryDatabaseUrl($databaseB),
    ]);

    $database = app(LaboratoryDatabase::class);

    $database->connect($laboratoryA);
    expect(DB::connection('lab')->table('laboratory_marker')->value('value'))->toBe('LAB_A');

    $database->connect($laboratoryB);
    expect(DB::connection('lab')->table('laboratory_marker')->value('value'))->toBe('LAB_B');
});
