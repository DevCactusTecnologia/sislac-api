<?php

namespace App\Domain\Atendimentos\Queries;

use App\Domain\Atendimentos\Models\Atendimento;
use App\Domain\Atendimentos\Support\AtendimentoCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ListAtendimentos
{
    private const DEFAULT_PAGE_SIZE = 50;

    /**
     * @param array<string, mixed> $filters
     * @return array{data:Collection<int, Atendimento>,next_cursor:?string}
     */
    public function handle(array $filters): array
    {
        $pageSize = max(10, min(200, (int) ($filters['page_size'] ?? self::DEFAULT_PAGE_SIZE)));

        $query = Atendimento::query()->select([
            'id',
            'protocolo',
            'data',
            'paciente_id',
            'paciente_nome',
            'paciente_cpf',
            'paciente_nascimento',
            'solicitante',
            'convenio_id',
            'convenio_nome',
            'unidade_id',
            'status_atendimento',
            'status_pagamento',
            'origem_atendimento',
            'guia_numero',
            'subtotal',
            'desconto_total',
            'acrescimo_total',
            'total',
            'prioridade_clinica',
            'created_at',
            'updated_at',
        ]);

        $this->applyFilters($query, $filters);

        $cursor = $filters['cursor'] ?? null;
        if (is_string($cursor) && $cursor !== '') {
            $decoded = AtendimentoCursor::decode($cursor);
            $query->where(function (Builder $page) use ($decoded): void {
                $page->where('data', '<', $decoded['data'])
                    ->orWhere(function (Builder $sameDate) use ($decoded): void {
                        $sameDate->where('data', '=', $decoded['data'])
                            ->where('id', '<', $decoded['id']);
                    });
            });
        }

        /** @var Collection<int, Atendimento> $rows */
        $rows = $query
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->limit($pageSize + 1)
            ->get();

        $hasMore = $rows->count() > $pageSize;
        $data = $rows->take($pageSize)->values();
        $nextCursor = null;

        if ($hasMore && $data->isNotEmpty()) {
            /** @var Atendimento $last */
            $last = $data->last();
            $nextCursor = AtendimentoCursor::encode(
                $last->data->toISOString(),
                (int) $last->getKey(),
            );
        }

        return [
            'data' => $data,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @param Builder<Atendimento> $query
     * @param array<string, mixed> $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('status_atendimento', $status);
        }

        $pagamento = $filters['pagamento'] ?? null;
        if (is_string($pagamento) && $pagamento !== '') {
            $query->where('status_pagamento', $pagamento);
        }

        $unidadeId = $filters['unidade_id'] ?? null;
        if (is_string($unidadeId) && $unidadeId !== '') {
            $query->where('unidade_id', $unidadeId);
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $dataInicio = $filters['data_inicio'] ?? null;
        if (is_string($dataInicio) && $dataInicio !== '') {
            $query->where('data', '>=', CarbonImmutable::createFromFormat('Y-m-d', $dataInicio, $timezone)->startOfDay());
        }

        $dataFim = $filters['data_fim'] ?? null;
        if (is_string($dataFim) && $dataFim !== '') {
            $query->where('data', '<', CarbonImmutable::createFromFormat('Y-m-d', $dataFim, $timezone)->addDay()->startOfDay());
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $searchQuery) use ($like): void {
            $searchQuery->whereRaw('LOWER(paciente_nome) LIKE LOWER(?)', [$like])
                ->orWhere('paciente_cpf', 'like', $like)
                ->orWhere('protocolo', 'like', $like)
                ->orWhereRaw('LOWER(solicitante) LIKE LOWER(?)', [$like])
                ->orWhereRaw('LOWER(convenio_nome) LIKE LOWER(?)', [$like]);
        });
    }
}
