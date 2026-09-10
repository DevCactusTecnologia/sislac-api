<?php

namespace App\Domain\Financeiro\Queries;

use App\Domain\Financeiro\Models\FinanceiroSaida;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;

final class ListFinanceiroSaidas
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data:list<FinanceiroSaida>,next_cursor:?string}
     */
    public function handle(array $filters): array
    {
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 50;
        $query = FinanceiroSaida::query();

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('protocolo', 'ILIKE', $term)
                    ->orWhere('descricao', 'ILIKE', $term)
                    ->orWhere('tipo_despesa', 'ILIKE', $term)
                    ->orWhere('destino_pagamento', 'ILIKE', $term);
            });
        }

        if (isset($filters['status']) && is_string($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from']) && is_string($filters['date_from'])) {
            $query->where('data', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (isset($filters['date_to']) && is_string($filters['date_to'])) {
            $query->where('data', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        if (isset($filters['cursor']) && is_string($filters['cursor']) && $filters['cursor'] !== '') {
            $cursor = $this->decodeCursor($filters['cursor']);
            $query->whereRaw('(data, id) < (?, ?)', [$cursor['data'], $cursor['id']]);
        }

        $rows = $query
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $hasMore ? $items->last() : null;

        return [
            'data' => $items->all(),
            'next_cursor' => $last instanceof FinanceiroSaida ? $this->encodeCursor($last) : null,
        ];
    }

    private function encodeCursor(FinanceiroSaida $saida): string
    {
        $data = $saida->getAttribute('data');
        if (! $data instanceof Carbon) {
            throw new InvalidArgumentException('Saída sem data válida para paginação.');
        }

        $json = json_encode([
            'data' => $data->toISOString(),
            'id' => (int) $saida->getKey(),
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{data:string,id:int} */
    private function decodeCursor(string $cursor): array
    {
        $normalized = strtr($cursor, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Cursor inválido.');
        }

        try {
            $payload = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Cursor inválido.');
        }

        if (! is_array($payload)
            || ! isset($payload['data'], $payload['id'])
            || ! is_string($payload['data'])
            || ! is_int($payload['id'])
            || $payload['id'] < 1) {
            throw new InvalidArgumentException('Cursor inválido.');
        }

        return [
            'data' => $payload['data'],
            'id' => $payload['id'],
        ];
    }
}
