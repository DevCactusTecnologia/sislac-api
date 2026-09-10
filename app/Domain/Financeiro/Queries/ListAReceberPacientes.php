<?php

namespace App\Domain\Financeiro\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ListAReceberPacientes
{
    private const DEFAULT_LIMIT = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data:list<array<string, mixed>>,next_cursor:?array{data:string,id:int}}
     */
    public function handle(array $filters): array
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? self::DEFAULT_LIMIT)));
        $connection = DB::connection('tenant');

        $exames = $connection->table('atendimento_exames')
            ->selectRaw('atendimento_id, SUM(valor)::numeric(14,2) AS valor_total')
            ->where('status', '<>', 'cancelado')
            ->whereRaw("COALESCE(cobranca_destino, 'paciente') <> 'convenio'")
            ->groupBy('atendimento_id');

        $pagamentos = $connection->table('atendimento_pagamentos')
            ->selectRaw('atendimento_id, SUM(valor)::numeric(14,2) AS valor_pago')
            ->whereRaw("COALESCE(status_pagamento, 'efetuado') <> 'estornado'")
            ->groupBy('atendimento_id');

        $query = $connection->table('atendimentos as a')
            ->leftJoinSub($exames, 'e', 'e.atendimento_id', '=', 'a.id')
            ->leftJoinSub($pagamentos, 'p', 'p.atendimento_id', '=', 'a.id')
            ->where('a.status_atendimento', '<>', 'Cancelado')
            ->whereRaw('(COALESCE(e.valor_total, 0) - COALESCE(p.valor_pago, 0)) > 0.009')
            ->selectRaw(<<<'SQL'
                a.id,
                a.id AS ref_id,
                'paciente'::text AS tipo,
                a.protocolo,
                a.data,
                a.data AS desde,
                a.paciente_nome,
                a.paciente_nome AS quem,
                a.paciente_cpf,
                a.convenio_nome,
                a.unidade_id,
                COALESCE(e.valor_total, 0)::numeric(14,2) AS valor_total,
                COALESCE(p.valor_pago, 0)::numeric(14,2) AS valor_pago,
                (COALESCE(e.valor_total, 0) - COALESCE(p.valor_pago, 0))::numeric(14,2) AS saldo,
                CASE WHEN COALESCE(p.valor_pago, 0) > 0 THEN 'parcial' ELSE 'pendente' END AS status,
                0::int AS qtd_exames,
                1::int AS qtd_pacientes
            SQL);

        $this->applyFilters($query, $filters);

        /** @var list<stdClass> $rows */
        $rows = $query
            ->orderByDesc('a.data')
            ->orderByDesc('a.id')
            ->limit($limit + 1)
            ->get()
            ->all();

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $data = array_map(fn (stdClass $row): array => $this->serialize($row), $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'data' => $data,
            'next_cursor' => $hasMore && $last instanceof stdClass
                ? ['data' => (string) $last->data, 'id' => (int) $last->id]
                : null,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $nested) use ($like): void {
                $nested->whereRaw('LOWER(a.paciente_nome) LIKE LOWER(?)', [$like])
                    ->orWhere('a.paciente_cpf', 'like', $like)
                    ->orWhereRaw('LOWER(a.protocolo) LIKE LOWER(?)', [$like]);
            });
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $dateFrom = $filters['date_from'] ?? null;
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('a.data', '>=', CarbonImmutable::parse($dateFrom, $timezone));
        }

        $dateTo = $filters['date_to'] ?? null;
        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('a.data', '<=', CarbonImmutable::parse($dateTo, $timezone));
        }

        $status = $filters['status'] ?? null;
        if ($status === 'pendente') {
            $query->whereRaw('COALESCE(p.valor_pago, 0) = 0');
        } elseif ($status === 'parcial') {
            $query->whereRaw('COALESCE(p.valor_pago, 0) > 0');
        }

        $cursorData = $filters['cursor_data'] ?? null;
        $cursorId = $filters['cursor_id'] ?? null;
        if (is_string($cursorData) && $cursorData !== '' && is_numeric($cursorId)) {
            $query->where(function (Builder $page) use ($cursorData, $cursorId): void {
                $page->where('a.data', '<', $cursorData)
                    ->orWhere(function (Builder $sameDate) use ($cursorData, $cursorId): void {
                        $sameDate->where('a.data', '=', $cursorData)
                            ->where('a.id', '<', (int) $cursorId);
                    });
            });
        }
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'ref_id' => (int) $row->ref_id,
            'tipo' => (string) $row->tipo,
            'protocolo' => (string) $row->protocolo,
            'data' => (string) $row->data,
            'desde' => (string) $row->desde,
            'paciente_nome' => (string) $row->paciente_nome,
            'quem' => (string) $row->quem,
            'paciente_cpf' => (string) $row->paciente_cpf,
            'convenio_nome' => (string) $row->convenio_nome,
            'unidade_id' => (string) $row->unidade_id,
            'valor_total' => $this->money($row->valor_total),
            'valor_pago' => $this->money($row->valor_pago),
            'saldo' => $this->money($row->saldo),
            'status' => (string) $row->status,
            'qtd_exames' => (int) $row->qtd_exames,
            'qtd_pacientes' => (int) $row->qtd_pacientes,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
