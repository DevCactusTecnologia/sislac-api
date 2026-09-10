<?php

namespace App\Domain\Financeiro\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ListFinanceiroSaidas
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
            ->table('financeiro_saidas as s')
            ->select([
                's.id',
                's.protocolo',
                's.data',
                's.descricao',
                's.valor',
                's.tipo_despesa',
                's.destino_pagamento',
                's.data_vencimento',
                's.status',
                's.foi_pago',
                's.data_pagamento',
                's.forma_pagamento',
                's.caixa_sessao_id',
                's.created_at',
                's.updated_at',
            ]);

        $this->applyFilters($query, $filters);

        /** @var list<stdClass> $rows */
        $rows = $query
            ->orderByDesc('s.data')
            ->orderByDesc('s.id')
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
                ? [
                    'data' => (string) CarbonImmutable::parse((string) $last->data)->toISOString(),
                    'id' => (int) $last->id,
                ]
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
                $nested->whereRaw('LOWER(s.protocolo) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(s.descricao) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(s.tipo_despesa) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(s.destino_pagamento) LIKE LOWER(?)', [$like]);
            });
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $dateFrom = $filters['date_from'] ?? null;
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('s.data', '>=', CarbonImmutable::parse($dateFrom, $timezone));
        }

        $dateTo = $filters['date_to'] ?? null;
        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('s.data', '<=', CarbonImmutable::parse($dateTo, $timezone));
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('s.status', $status);
        }

        $cursorData = $filters['cursor_data'] ?? null;
        $cursorId = $filters['cursor_id'] ?? null;
        if (is_string($cursorData) && $cursorData !== '' && is_numeric($cursorId)) {
            $cursor = CarbonImmutable::parse($cursorData, $timezone);
            $query->where(function (Builder $page) use ($cursor, $cursorId): void {
                $page->where('s.data', '<', $cursor)
                    ->orWhere(function (Builder $sameDate) use ($cursor, $cursorId): void {
                        $sameDate->where('s.data', '=', $cursor)
                            ->where('s.id', '<', (int) $cursorId);
                    });
            });
        }
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'protocolo' => (string) $row->protocolo,
            'data' => (string) CarbonImmutable::parse((string) $row->data)->toISOString(),
            'descricao' => (string) $row->descricao,
            'valor' => $this->money($row->valor),
            'tipo_despesa' => (string) $row->tipo_despesa,
            'destino_pagamento' => (string) $row->destino_pagamento,
            'data_vencimento' => $row->data_vencimento === null ? null : (string) $row->data_vencimento,
            'status' => (string) $row->status,
            'foi_pago' => (bool) $row->foi_pago,
            'data_pagamento' => $row->data_pagamento === null ? null : (string) $row->data_pagamento,
            'forma_pagamento' => $row->forma_pagamento === null ? null : (string) $row->forma_pagamento,
            'caixa_sessao_id' => $row->caixa_sessao_id === null ? null : (int) $row->caixa_sessao_id,
            'created_at' => (string) CarbonImmutable::parse((string) $row->created_at)->toISOString(),
            'updated_at' => (string) CarbonImmutable::parse((string) $row->updated_at)->toISOString(),
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
