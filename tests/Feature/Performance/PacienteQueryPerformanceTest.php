<?php

beforeEach(function () {
    resetSupabaseFixture();
    seedPacientePerformanceRows(1000);
});

function seedPacientePerformanceRows(int $count): void
{
    $pdo = supabaseTestPdo();
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO pacientes (nome, cpf, status, friendly_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)
    SQL);

    for ($index = 1; $index <= $count; $index++) {
        $timestamp = sprintf('2026-09-%02d 12:%02d:%02d+00', (($index - 1) % 7) + 1, $index % 60, $index % 60);
        $name = $index % 20 === 0 ? sprintf('Paciente Alvo %04d', $index) : sprintf('Paciente Controle %04d', $index);

        $statement->execute([
            $name,
            sprintf('%011d', 10000000000 + $index),
            $index % 3 === 0 ? 'Inativo' : 'Ativo',
            sprintf('PAC-%06d', $index),
            $timestamp,
            $timestamp,
        ]);
    }

    $pdo->exec('ANALYZE pacientes');
}

/** @param array<string, mixed> $plan */
function pacientePlanUsesIndex(array $plan, string $indexName): bool
{
    if (($plan['Index Name'] ?? null) === $indexName) {
        return true;
    }

    foreach (($plan['Plans'] ?? []) as $child) {
        if (is_array($child) && pacientePlanUsesIndex($child, $indexName)) {
            return true;
        }
    }

    return false;
}

it('permite ao planner usar o índice composto da paginação keyset', function () {
    $pdo = supabaseTestPdo();
    $pdo->exec('SET enable_seqscan = off');

    try {
        $statement = $pdo->query(<<<'SQL'
            EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
            SELECT id, updated_at
            FROM pacientes
            WHERE updated_at < '2026-09-05 12:45:45+00'
               OR (updated_at = '2026-09-05 12:45:45+00' AND id < 500)
            ORDER BY updated_at DESC, id DESC
            LIMIT 51
        SQL);

        $raw = $statement?->fetchColumn();
    } finally {
        $pdo->exec('RESET enable_seqscan');
    }

    $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    $plan = $decoded[0]['Plan'];

    expect(pacientePlanUsesIndex($plan, 'idx_pacientes_cursor'))->toBeTrue()
        ->and($plan['Actual Rows'])->toBeLessThanOrEqual(51);
});
