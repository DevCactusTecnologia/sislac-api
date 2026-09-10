<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Financeiro\Models\CaixaSessao;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OpenCaixa
{
    /** @param array<string, mixed> $payload */
    public function handle(array $payload, string $userId): CaixaSessao
    {
        try {
            return DB::transaction(function () use ($payload, $userId): CaixaSessao {
                $session = new CaixaSessao;
                $session->fill([
                    'unidade_id' => Str::squish((string) $payload['unidade_id']),
                    'valor_abertura' => (string) ($payload['valor_abertura'] ?? '0.00'),
                    'observacoes' => isset($payload['observacoes'])
                        ? Str::squish((string) $payload['observacoes'])
                        : null,
                    'responsavel_id' => $userId,
                    'status' => 'aberta',
                    'aberta_em' => now(),
                ]);
                $session->save();

                return $session->refresh();
            });
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;

            if ($sqlState === '23505'
                && str_contains($exception->getMessage(), 'uq_caixa_sessao_aberta_por_unidade')) {
                throw new DomainException('Já existe caixa aberto para esta unidade.', previous: $exception);
            }

            throw $exception;
        }
    }
}
