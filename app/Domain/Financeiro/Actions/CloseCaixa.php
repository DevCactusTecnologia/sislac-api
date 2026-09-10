<?php

namespace App\Domain\Financeiro\Actions;

use App\Domain\Financeiro\Models\CaixaSessao;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CloseCaixa
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{session:CaixaSessao,entradas_dinheiro:string,entradas_pix:string,saidas:string,saldo_final:string}
     */
    public function handle(int $id, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($id, $payload, $userId): array {
            $session = CaixaSessao::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($session->getAttribute('status') !== 'aberta') {
                throw new DomainException('Caixa já está fechado ou cancelado.');
            }

            $valorAbertura = (string) $session->getAttribute('valor_abertura');
            $position = $this->position($id, $valorAbertura);
            $closedAt = now();

            $attributes = [
                'status' => 'fechada',
                'fechada_em' => $closedAt,
                'fechado_por' => $userId,
                'valor_fechamento' => $position['saldo_final'],
            ];

            if (array_key_exists('observacoes', $payload)) {
                $observacoes = $payload['observacoes'];
                $attributes['observacoes'] = is_string($observacoes)
                    ? Str::squish($observacoes)
                    : null;
            }

            $session->fill($attributes);
            $session->save();

            return [
                'session' => $session->refresh(),
                'entradas_dinheiro' => $position['entradas_dinheiro'],
                'entradas_pix' => $position['entradas_pix'],
                'saidas' => $position['saidas'],
                'saldo_final' => $position['saldo_final'],
            ];
        });
    }

    /** @return array{entradas_dinheiro:string,entradas_pix:string,saidas:string,saldo_final:string} */
    private function position(int $sessionId, string $valorAbertura): array
    {
        $row = DB::selectOne(<<<'SQL'
            WITH pagamentos AS (
                SELECT
                    COALESCE(SUM(p.valor) FILTER (WHERE p.tipo = 'Dinheiro'), 0)::numeric(14, 2) AS dinheiro,
                    COALESCE(SUM(p.valor) FILTER (WHERE p.tipo = 'PIX'), 0)::numeric(14, 2) AS pix
                FROM public.atendimento_pagamentos AS p
                WHERE p.caixa_sessao_id = ?
                  AND COALESCE(p.status_pagamento, 'efetuado') <> 'estornado'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM public.financeiro_estornos AS e
                      WHERE e.origem_tipo = 'pagamento'
                        AND e.origem_id = p.id
                  )
            ), saidas AS (
                SELECT COALESCE(SUM(s.valor), 0)::numeric(14, 2) AS total
                FROM public.financeiro_saidas AS s
                WHERE s.caixa_sessao_id = ?
                  AND s.foi_pago IS TRUE
                  AND NOT EXISTS (
                      SELECT 1
                      FROM public.financeiro_estornos AS e
                      WHERE e.origem_tipo = 'saida'
                        AND e.origem_id = s.id
                  )
            )
            SELECT
                pagamentos.dinheiro::text AS entradas_dinheiro,
                pagamentos.pix::text AS entradas_pix,
                saidas.total::text AS saidas,
                (CAST(? AS numeric(14, 2)) + pagamentos.dinheiro + pagamentos.pix - saidas.total)::numeric(14, 2)::text AS saldo_final
            FROM pagamentos
            CROSS JOIN saidas
        SQL, [$sessionId, $sessionId, $valorAbertura]);

        if ($row === null) {
            throw new RuntimeException('Não foi possível calcular o fechamento do caixa.');
        }

        return [
            'entradas_dinheiro' => (string) $row->entradas_dinheiro,
            'entradas_pix' => (string) $row->entradas_pix,
            'saidas' => (string) $row->saidas,
            'saldo_final' => (string) $row->saldo_final,
        ];
    }
}
