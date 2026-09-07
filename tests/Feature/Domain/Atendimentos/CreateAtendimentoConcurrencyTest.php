<?php

use App\Domain\Atendimentos\Actions\CreateAtendimento;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->previousDefaultConnection = config('database.default');
    $this->concurrencyConnection = 'atendimento_concurrency';
    $this->concurrencyDatabase = 'sislac_t_atd_race_'.Str::lower(Str::random(10));

    atendimentoConcurrencyControlConnection()->exec('CREATE DATABASE "'.$this->concurrencyDatabase.'"');

    $connection = config('database.connections.central');
    if (! is_array($connection)) {
        throw new RuntimeException('Conexão central PostgreSQL indisponível para o teste concorrente.');
    }

    $connection['database'] = $this->concurrencyDatabase;

    config([
        'database.default' => $this->concurrencyConnection,
        'database.connections.'.$this->concurrencyConnection => $connection,
    ]);

    DB::purge($this->concurrencyConnection);
    DB::setDefaultConnection($this->concurrencyConnection);

    Artisan::call('migrate', [
        '--database' => $this->concurrencyConnection,
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    DB::statement(<<<'SQL'
        CREATE FUNCTION test_delay_atendimento_insert()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            PERFORM pg_sleep(0.35);
            RETURN NEW;
        END;
        $$
    SQL);

    DB::statement(<<<'SQL'
        CREATE TRIGGER test_delay_atendimento_insert_trigger
        BEFORE INSERT ON atendimentos
        FOR EACH ROW
        EXECUTE FUNCTION test_delay_atendimento_insert()
    SQL);

    DB::purge($this->concurrencyConnection);
});

afterEach(function () {
    DB::purge($this->concurrencyConnection);
    DB::setDefaultConnection((string) $this->previousDefaultConnection);
    config(['database.default' => $this->previousDefaultConnection]);

    atendimentoConcurrencyControlConnection()->exec(
        'DROP DATABASE IF EXISTS "'.$this->concurrencyDatabase.'" WITH (FORCE)',
    );
});

function atendimentoConcurrencyControlConnection(): PDO
{
    $config = config('database.connections.central');

    if (! is_array($config)) {
        throw new RuntimeException('Conexão central PostgreSQL indisponível.');
    }

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/** @return array<string, mixed> */
function atendimentoConcurrencyPayload(string $key): array
{
    return [
        'atendimento' => [
            'paciente_nome' => 'Paciente Sintético Concorrente',
            'paciente_cpf' => '',
            'idempotency_key' => $key,
        ],
        'exames' => [
            ['nome_exame' => 'Exame Sintético Concorrente', 'valor' => 10],
        ],
        'pagamentos' => [
            ['tipo' => 'PIX', 'valor' => 10],
        ],
    ];
}

it('retorna o mesmo atendimento quando a mesma idempotency key chega em concorrencia', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $key = (string) Str::uuid();
    $payload = atendimentoConcurrencyPayload($key);
    $barrier = tempnam(sys_get_temp_dir(), 'sislac-atd-start-');
    $resultA = tempnam(sys_get_temp_dir(), 'sislac-atd-a-');
    $resultB = tempnam(sys_get_temp_dir(), 'sislac-atd-b-');

    if ($barrier === false || $resultA === false || $resultB === false) {
        throw new RuntimeException('Não foi possível criar arquivos temporários do teste concorrente.');
    }

    unlink($barrier);
    $connectionName = $this->concurrencyConnection;

    $spawn = static function (string $resultFile) use ($barrier, $payload, $connectionName): int {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        while (! file_exists($barrier)) {
            usleep(1_000);
        }

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);

        try {
            $result = app(CreateAtendimento::class)->handle($payload);
            file_put_contents($resultFile, json_encode(['result' => $result], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            file_put_contents($resultFile, json_encode([
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));
        } finally {
            DB::purge($connectionName);
        }

        exit(0);
    };

    $pidA = $spawn($resultA);
    $pidB = $spawn($resultB);
    touch($barrier);

    pcntl_waitpid($pidA, $statusA);
    pcntl_waitpid($pidB, $statusB);

    $decodedA = json_decode((string) file_get_contents($resultA), true, flags: JSON_THROW_ON_ERROR);
    $decodedB = json_decode((string) file_get_contents($resultB), true, flags: JSON_THROW_ON_ERROR);

    @unlink($barrier);
    @unlink($resultA);
    @unlink($resultB);

    expect(pcntl_wexitstatus($statusA))->toBe(0)
        ->and(pcntl_wexitstatus($statusB))->toBe(0)
        ->and($decodedA)->not->toHaveKey('error_class')
        ->and($decodedB)->not->toHaveKey('error_class')
        ->and($decodedA['result']['atendimento_id'])->toBe($decodedB['result']['atendimento_id'])
        ->and([$decodedA['result']['duplicate'], $decodedB['result']['duplicate']])
        ->toContain(false)
        ->toContain(true);

    DB::purge($connectionName);
    DB::setDefaultConnection($connectionName);

    expect(DB::table('atendimentos')->where('idempotency_key', $key)->count())->toBe(1)
        ->and(DB::table('atendimento_exames')->count())->toBe(1)
        ->and(DB::table('atendimento_pagamentos')->count())->toBe(1);
});
