<?php

use App\Http\Middleware\UseLaboratoryDatabase;
use App\Models\Laboratory;
use App\Platform\Models\User;
use App\Support\LaboratoryDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

it('resolve o banco exclusivamente pelo usuário autenticado e limpa a conexão ao final', function () {
    $databaseName = 'sislac_lab_req_'.Str::lower(Str::random(10));
    $this->laboratoryDatabases = [$databaseName];
    createLaboratoryDatabase($databaseName, 'LAB_USER');

    $laboratory = Laboratory::query()->create([
        'name' => 'Laboratório do usuário',
        'code' => 'lab-user-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_url' => laboratoryDatabaseUrl($databaseName),
    ]);
    $user = User::factory()->create(['laboratory_id' => $laboratory->getKey()]);

    $request = Request::create('/clinico', 'GET', server: ['HTTP_X_TENANT' => (string) Str::uuid()]);
    $request->setUserResolver(fn () => $user->fresh());

    $response = app(UseLaboratoryDatabase::class)->handle(
        $request,
        fn () => response()->json([
            'marker' => DB::connection('lab')->table('laboratory_marker')->value('value'),
        ]),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('LAB_USER')
        ->and(config('database.connections.lab'))->toBeNull();
});

it('bloqueia usuário operacional sem laboratório ativo antes do domínio', function (string $status, bool $withLaboratory) {
    $laboratory = $withLaboratory
        ? Laboratory::query()->create([
            'name' => 'Laboratório indisponível',
            'code' => 'lab-off-'.Str::lower(Str::random(8)),
            'status' => $status,
            'database_url' => 'postgresql://invalid.invalid/never-used',
        ])
        : null;

    $user = User::factory()->create([
        'laboratory_id' => $laboratory?->getKey(),
        'is_super_admin' => ! $withLaboratory,
    ]);
    $request = Request::create('/clinico');
    $request->setUserResolver(fn () => $user->fresh());
    $called = false;

    $response = app(UseLaboratoryDatabase::class)->handle($request, function () use (&$called) {
        $called = true;

        return response('não deve executar');
    });

    expect($response->getStatusCode())->toBe(403)
        ->and($called)->toBeFalse();
})->with([
    'laboratório suspenso' => ['suspended', true],
    'super admin sem laboratório' => ['active', false],
]);
