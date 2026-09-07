<?php

namespace App\Domain\Pacientes\Queries;

use App\Domain\Pacientes\Models\Paciente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ListPacientes
{
    private const PAGE_SIZE = 50;

    /**
     * @return array{data: Collection<int, Paciente>, counts: array{todos:int, ativos:int, inativos:int}, nextCursor:?string}
     */
    public function execute(?string $status, ?string $search, ?string $cursor): array
    {
        $base = Paciente::query();
        $this->applySearch($base, $search);

        $todos = (clone $base)->count();
        $ativos = (clone $base)->where('status', 'Ativo')->count();

        $page = clone $base;

        if ($status !== null && $status !== 'Todos') {
            $page->where('status', $status);
        }

        if ($cursor !== null && $cursor !== '') {
            $decoded = $this->decodeCursor($cursor);
            $page->where(function (Builder $query) use ($decoded): void {
                $query->where('updated_at', '<', $decoded['updated_at'])
                    ->orWhere(function (Builder $sameTimestamp) use ($decoded): void {
                        $sameTimestamp->where('updated_at', '=', $decoded['updated_at'])
                            ->where('id', '<', $decoded['id']);
                    });
            });
        }

        /** @var Collection<int, Paciente> $rows */
        $rows = $page
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $rows->count() > self::PAGE_SIZE;
        $data = $rows->take(self::PAGE_SIZE)->values();
        $nextCursor = null;

        if ($hasMore && $data->isNotEmpty()) {
            /** @var Paciente $last */
            $last = $data->last();
            $nextCursor = $this->encodeCursor((string) $last->updated_at, (int) $last->getKey());
        }

        return [
            'data' => $data,
            'counts' => [
                'todos' => $todos,
                'ativos' => $ativos,
                'inativos' => $todos - $ativos,
            ],
            'nextCursor' => $nextCursor,
        ];
    }

    /** @param Builder<Paciente> $query */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', $search) ?? '';

        if (strlen($digits) >= 3) {
            $query->where('cpf', 'like', '%'.$digits.'%');

            return;
        }

        $query->whereRaw('LOWER(nome) LIKE LOWER(?)', ['%'.$search.'%']);
    }

    /** @return array{updated_at:string,id:int} */
    private function decodeCursor(string $cursor): array
    {
        $normalized = strtr($cursor, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($normalized, true);
        $decoded = $json === false ? null : json_decode($json, true);

        if (! is_array($decoded)
            || ! isset($decoded['updated_at'], $decoded['id'])
            || ! is_string($decoded['updated_at'])
            || ! is_int($decoded['id'])
            || $decoded['id'] < 1) {
            throw new InvalidArgumentException('Cursor de pacientes inválido.');
        }

        return [
            'updated_at' => $decoded['updated_at'],
            'id' => $decoded['id'],
        ];
    }

    private function encodeCursor(string $updatedAt, int $id): string
    {
        $json = json_encode([
            'updated_at' => $updatedAt,
            'id' => $id,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
