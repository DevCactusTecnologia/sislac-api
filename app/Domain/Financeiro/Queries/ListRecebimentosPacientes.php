<?php

namespace App\Domain\Financeiro\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ListRecebimentosPacientes
{
    private const DEFAULT_LIMIT = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data:list<array<string, mixed>>,next_cursor:?array{data:string,id:int}}
     */
    public function handle(array $filters): array
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? self::DEFAULT_LIMIT)));

        $query = DB::connection('tenant')
            ->table('atendimento_pagamentos as p')
            ->join('atendimentos as a', 'a.id', '=', 'p.atendimento_id')
            ->where('a.status_atendimento', '<>', 'Cancelado')
            ->whereRaw("COALESCE(p.status_pagamento, 'efetuado') <> 'estornado'")
            ->whereNotExists(function (Builder $estornos): void {
                $estornos->selectRaw('1')
                    ->from('financeiro_estornos as fe')
                    ->where('fe.origem_tipo', 'pagamento')
                    ->whereColumn('fe.origem_id', 'p.id');
            })
            ->selectRaw(<<<'SQL'
                p.id AS pagamento_id,
                a.id AS atendimento_id,
                NULL::bigint AS fatura_id,
                'pagamento'::text AS origem,
                a.protocolo,
                p.data,
                a.paciente_nome AS cliente,
                COALESCE(NULLIF(a.convenio_nome, ''), 'Particular') AS convenio,
                p.tipo AS payment,
                p.valor::numeric(14,2) AS valor_total,
                p.valor::numeric(14,2) AS valor,
                p.observacao,
                a.unidade_id,
                a.status_pagamento
            SQL);

        $this->applyFilters($query, $filters);

        /** @var list<stdClass> $rows */
        $rows = $query
            ->orderByDesc('p.data')
            ->orderByDesc('p.id')
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
                ? ['data' => (string) $last->data, 'id' => (int) $last->pagamento_id]
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
                    ->orWhereRaw('LOWER(a.protocolo) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(p.tipo) LIKE LOWER(?)', [$like]);
            });
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $dateFrom = $filters['date_from'] ?? null;
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('p.data', '>=', CarbonImmutable::parse($dateFrom, $timezone));
        }

        $dateTo = $filters['date_to'] ?? null;
        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('p.data', '<=', CarbonImmutable::parse($dateTo, $timezone));
        }

        $cursorData = $filters['cursor_data'] ?? null;
        $cursorId = $filters['cursor_id'] ?? null;
        if (is_string($cursorData) && $cursorData !== '' && is_numeric($cursorId)) {
            $query->where(function (Builder $page) use ($cursorData, $cursorId): void {
                $page->where('p.data', '<', $cursorData)
                    ->orWhere(function (Builder $sameDate) use ($cursorData, $cursorId): void {
                        $sameDate->where('p.data', '=', $cursorData)
                            ->where('p.id', '<', (int) $cursorId);
                    });
            });
        }
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'pagamento_id' => (int) $row->pagamento_id,
            'atendimento_id' => (int) $row->atendimento_id,
            'fatura_id' => $row->fatura_id === null ? null : (int) $row->fatura_id,
            'origem' => (string) $row->origem,
            'protocolo' => (string) $row->protocolo,
            'data' => (string) $row->data,
            'cliente' => (string) $row->cliente,
            'convenio' => (string) $row->convenio,
            'payment' => (string) $row->payment,
            'valor_total' => $this->money($row->valor_total),
            'valor' => $this->money($row->valor),
            'observacao' => (string) $row->observacao,
            'unidade_id' => (string) $row->unidade_id,
            'status_pagamento' => (string) $row->status_pagamento,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
