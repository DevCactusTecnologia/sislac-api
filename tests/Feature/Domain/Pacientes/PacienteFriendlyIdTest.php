<?php

use App\Domain\Pacientes\Models\Paciente;
use App\Domain\Pacientes\Services\PacienteFriendlyId;
use Illuminate\Database\QueryException;

beforeEach(function () {
    resetSupabaseFixture();
});

it('gera friendly ids sequenciais no formato canônico', function () {
    $generator = app(PacienteFriendlyId::class);

    expect($generator->next())->toBe('PAC-000001')
        ->and($generator->next())->toBe('PAC-000002');
});

it('mantém o contador atômico entre conexões independentes', function () {
    $pdoA = newSupabaseTestPdo();
    $pdoB = newSupabaseTestPdo();

    $sql = <<<'SQL'
        INSERT INTO friendly_id_counters (scope, next_value)
        VALUES ('paciente', 2)
        ON CONFLICT (scope)
        DO UPDATE SET next_value = friendly_id_counters.next_value + 1
        RETURNING next_value - 1 AS value
    SQL;

    $first = (int) $pdoA->query($sql)->fetchColumn();
    $second = (int) $pdoB->query($sql)->fetchColumn();

    expect([$first, $second])->toBe([1, 2]);
});

it('impede alteração do friendly id depois de persistido', function () {
    $paciente = new Paciente(['nome' => 'Paciente Imutável']);
    $paciente->forceFill(['friendly_id' => 'PAC-000001']);
    $paciente->save();

    expect(function () use ($paciente): void {
        $paciente->forceFill(['friendly_id' => 'PAC-999999'])->save();
    })->toThrow(QueryException::class, 'friendly_id de paciente é imutável');
});
