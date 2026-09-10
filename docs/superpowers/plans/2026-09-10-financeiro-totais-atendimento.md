# Financeiro — Totais Canônicos do Atendimento Implementation Plan

**Goal:** Fechar a autoridade financeira de `subtotal`, `desconto_total`, `acrescimo_total` e `total` no backend Laravel/PostgreSQL, reutilizando o cálculo canônico já existente e eliminando qualquer possibilidade de o cliente atuar como fonte desses valores.

**Architecture:** Não criar nova calculadora, tabela ou camada de abstração. O trigger existente `recompute_atendimento_completo` continua sendo a única rotina de derivação: `subtotal` soma `valor_original` dos exames ativos; `total` soma `valor`; `desconto_total = max(subtotal-total, 0)`; `acrescimo_total = max(total-subtotal, 0)`. O Laravel apenas impede totais derivados no input e adiciona a proteção de `valor_original` que já existe no Supabase live. O Supabase permanece baseline read-only nesta fase.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, stancl/tenancy, Pest.

## Invariantes

- `subtotal`, `desconto_total`, `acrescimo_total` e `total` são somente leitura para clientes HTTP.
- O backend não aceita esses quatro campos em criação ou atualização de atendimento.
- `valor_original` de um exame pode ser definido na criação do item, mas, depois de não nulo, não pode ser alterado.
- A proteção de `valor_original` deve reproduzir o comportamento do Supabase live: conservar silenciosamente `OLD.valor_original` em UPDATE divergente.
- Exames `cancelado` não entram em subtotal nem total.
- Não duplicar `recompute_atendimento_completo` nem criar cálculo equivalente em PHP.
- Nenhuma dependência nova, cache, fila, Redis ou event bus.
- Nenhum dado financeiro clínico na conexão `central`.
- TDD obrigatório: RED → mudança mínima → GREEN.
- Supabase live é somente leitura durante esta fase.

## Task 1 — Contrato executável

- Criar testes de API para rejeição dos quatro totais derivados em POST e PATCH.
- Criar testes de banco para desconto, acréscimo, compensação líquida e exclusão de exame cancelado.
- Criar teste de paridade para imutabilidade de `valor_original`.
- Criar teste de leitura comprovando que `AtendimentoResource` expõe os valores derivados.
- Rodar CI e confirmar RED somente nas lacunas de hardening, sem erro de infraestrutura/sintaxe.

## Task 2 — Barreira HTTP

- Adicionar `missing` para `subtotal`, `desconto_total`, `acrescimo_total` e `total` em `StoreAtendimentoRequest`.
- Adicionar as mesmas regras em `UpdateAtendimentoRequest`.
- Não alterar controllers/actions para recalcular totais.

## Task 3 — Paridade de `valor_original`

- Criar migration tenant `2026_09_10_000600_harden_atendimento_totals.php`.
- Criar função PostgreSQL invoker que preserva `OLD.valor_original` quando o valor anterior é não nulo e o novo diverge.
- Criar trigger BEFORE UPDATE em `atendimento_exames`.
- Não alterar o trigger canônico de recomputação salvo se um teste demonstrar necessidade real.

## Task 4 — Provisionamento e documentação

- Atualizar teste de schema version do provisionamento para a migration `000600`.
- Documentar autoridade, fórmulas e compatibilidade com o Supabase live.
- Registrar explicitamente que o Laravel reutiliza o cálculo já existente, evitando duplicação.

## Task 5 — Gate final

Executar no SHA final:
- Composer Validate
- Composer Audit
- manifesto Supabase
- Pint
- Larastan nível 8
- Pest paralelo
- guards do repositório

Somente concluir a fase quando `Qualidade PHP` e `Guards de repositório` estiverem verdes no mesmo SHA final.
