# Financeiro — Totais Canônicos do Atendimento

## Objetivo

Esta subfase fecha a autoridade de `subtotal`, `desconto_total`, `acrescimo_total` e `total` no backend Laravel/PostgreSQL sem criar uma segunda calculadora financeira.

O cálculo canônico já existente em `public.recompute_atendimento_completo` permanece a única fonte de derivação dos totais do atendimento. O Laravel não recalcula esses valores em PHP e o cliente não pode enviá-los como autoridade.

## Fonte de verdade

Os totais são derivados exclusivamente dos exames persistidos no banco tenant:

```text
subtotal        = Σ COALESCE(valor_original, valor) dos exames não cancelados
total           = Σ valor dos exames não cancelados
desconto_total  = MAX(subtotal - total, 0)
acrescimo_total = MAX(total - subtotal, 0)
```

A fórmula é líquida no nível do atendimento. Assim, desconto em um exame e acréscimo equivalente em outro podem se compensar, resultando em `desconto_total = 0` e `acrescimo_total = 0` quando `subtotal = total`.

Exames com `status = 'cancelado'` não entram em `subtotal` nem em `total`.

## Barreira HTTP

Os campos abaixo precisam estar ausentes tanto em `POST /api/atendimentos` quanto em `PATCH /api/atendimentos/{id}`:

- `subtotal`;
- `desconto_total`;
- `acrescimo_total`;
- `total`;
- `pagamentos`.

A validação utiliza `missing`, pois o contrato é de ausência do campo, e não apenas de proibição de valores não vazios.

O endpoint de Atendimento cria/altera dados clínicos e os exames permitidos. Pagamentos continuam exclusivamente nos comandos do Financeiro Core.

## `valor_original`

`atendimento_exames.valor_original` pode ser definido quando o exame é criado. Depois que possui valor não nulo, uma atualização divergente não o substitui.

A migration `2026_09_10_000600_harden_atendimento_totals.php` adiciona a mesma semântica observada no Supabase live: trigger `BEFORE UPDATE OF valor_original` preserva silenciosamente `OLD.valor_original` quando uma tentativa posterior envia valor diferente.

A função usa `SECURITY INVOKER`, `search_path = ''` e referências schema-qualified. Não existe `SECURITY DEFINER` nesta subfase.

## Decisão arquitetural

Nenhum novo service, calculator, event bus, queue, cache ou tabela foi criado. Duplicar `recompute_atendimento_completo` em PHP criaria duas fontes de verdade e foi explicitamente evitado.

`AtendimentoResource` apenas expõe os quatro valores já persistidos pelo banco.

## Concordância com o Supabase

O Supabase live foi utilizado somente em leitura para confirmar:

- existência dos quatro totais em `public.atendimentos`;
- fórmula de `public.recompute_atendimento_completo`;
- comportamento de `public.guard_atendimento_exames_valor_original`.

Nenhuma escrita, migration ou alteração foi aplicada ao Supabase live nesta subfase.

O Laravel utiliza `numeric(14,2)` para os totais já existentes, enquanto o baseline Supabase observado usa `numeric(12,2)`. A largura maior do Laravel não altera a semântica financeira e evita reduzir capacidade durante a migração.

## Testes de contrato

`AtendimentoTotalsContractTest` prova em PostgreSQL tenant real:

- desconto derivado;
- acréscimo derivado;
- compensação líquida de ajustes opostos;
- exclusão de exames cancelados;
- imutabilidade de `valor_original`;
- rejeição HTTP dos totais derivados em criação e atualização;
- leitura dos totais persistidos pelo backend.

O provisionamento também passa a registrar `2026_09_10_000600_harden_atendimento_totals` como schema atual.

## Fora do escopo

Convênios/faturas, Saídas, Caixa, resumo financeiro e cutover do frontend permanecem em subfases próprias.
