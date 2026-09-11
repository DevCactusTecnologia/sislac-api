<?php

namespace App\Domain\Pacientes\Queries;

use App\Domain\Pacientes\Models\Paciente;
use App\Domain\Pacientes\Support\PacienteCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            $decoded = PacienteCursor::decode($cursor);
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
            $nextCursor = PacienteCursor::encode((string) $last->getAttribute('updated_at'), (int) $last->getKey());
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
}
