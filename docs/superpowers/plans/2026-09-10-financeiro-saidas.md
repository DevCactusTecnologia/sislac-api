# Financeiro — Saídas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o domínio operacional mínimo de Saídas/Despesas no backend Laravel, com máquina de estados `aberta -> paga -> cancelada`, estorno formal, protocolo server-side, integração segura com Caixa e API tenant.

**Architecture:** `financeiro_saidas` permanece SSOT. Controllers finos delegam a Actions/Queries; Requests controlam o contrato HTTP; invariantes críticas ficam no PostgreSQL tenant. Saídas abertas são editáveis, Saídas pagas/canceladas são históricas e imutáveis, e `cancelada` só nasce do fluxo formal de estorno.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, Pest, stancl/tenancy, Supabase Auth apenas para identidade/baseline read-only.

**Spec:** `docs/superpowers/specs/2026-09-10-financeiro-saidas-design.md`

## Global Constraints

- Base obrigatória: `077c9a36893b896554e53fab7c04dd88f20a7799`.
- Branch: `fase-financeiro-saidas`; não fazer merge nesta fase.
- Supabase live é somente leitura; nenhuma DDL/DML remota.
- `financeiro_saidas` é a única fonte de verdade de despesas.
- `status` é canônico; `foi_pago` é derivado.
- `cancelada` só pode ser produzida por estorno formal com motivo.
- Saída paga e Saída cancelada são estados terminais para edição normal.
- Não introduzir Redis, fila, worker, event bus, plano de contas, DRE, centro de custo, recorrência ou ERP.
- Funções PostgreSQL novas/substituídas: `SECURITY INVOKER`, `SET search_path = ''`, referências schema-qualified.
- TDD obrigatório: RED observado antes de qualquer código de produção para cada comportamento.

---

### Task 1: Invariantes PostgreSQL e modelo canônico

**Files:**
- Create: `tests/Feature/Domain/Financeiro/FinanceiroSaidasSchemaTest.php`
- Create: `database/migrations/tenant/2026_09_10_000800_harden_financeiro_saidas.php`
- Create: `app/Domain/Financeiro/Models/FinanceiroSaida.php`

**Interfaces:**
- Produces: `FinanceiroSaida` Eloquent model sobre `financeiro_saidas`.
- Produces DB invariants: protocolo SAI server-side, valor > 0, status/foi_pago/data_pagamento coerentes, imutabilidade terminal, cancelamento somente com estorno, DELETE bloqueado, updated_at server-side.

- [ ] **Step 1: Write the failing schema tests**

Cobrir explicitamente: protocolo automático `SAI-AAAA-NNNNNNN`; cliente não controla protocolo; `valor <= 0` falha; `status='paga'` produz `foi_pago=true` e `data_pagamento`; `aberta` mantém `foi_pago=false` e `data_pagamento=NULL`; criação `cancelada` falha; update de paga falha; reabertura de cancelada falha; DELETE falha; cancelamento sem `financeiro_estornos` falha.

- [ ] **Step 2: Run CI and verify RED**

Expected: Pest falha porque os invariantes e o modelo ainda não existem; demais gates permanecem verdes.

- [ ] **Step 3: Implement minimal migration/model**

A migration deve alterar a tabela existente sem recriá-la. Gerar protocolo via `protocolo_sequence` com prefixo `SAI` e ano de `NEW.data`. O trigger de normalização deve derivar `foi_pago`/`data_pagamento`, bloquear criação direta como cancelada e proteger transições terminais. A transição para cancelada só é aceita quando já existe `financeiro_estornos(origem_tipo='saida', origem_id=OLD.id)` na mesma transação.

`FinanceiroSaida` deve expor casts para `valor`, `data`, `data_vencimento`, `data_pagamento`, `foi_pago`, `created_at`, `updated_at` e proteger campos server-side via `$guarded`.

- [ ] **Step 4: Run CI and verify GREEN**

Expected: schema tests + suíte existente passam.

- [ ] **Step 5: Commit**

`feat: endurece saídas financeiras no banco`

---

### Task 2: Listagem e criação de Saídas

**Files:**
- Create: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Create: `app/Domain/Financeiro/Queries/ListFinanceiroSaidas.php`
- Create: `app/Domain/Financeiro/Actions/CreateFinanceiroSaida.php`
- Create: `app/Http/Requests/Financeiro/ListFinanceiroSaidasRequest.php`
- Create: `app/Http/Requests/Financeiro/CreateFinanceiroSaidaRequest.php`
- Create: `app/Http/Resources/Financeiro/FinanceiroSaidaResource.php`
- Create: `app/Http/Controllers/Financeiro/ListFinanceiroSaidasController.php`
- Create: `app/Http/Controllers/Financeiro/CreateFinanceiroSaidaController.php`
- Modify: `routes/api.php`

**Interfaces:**
- `ListFinanceiroSaidas::handle(array $filters): array{data:list<FinanceiroSaida>,next_cursor:?string}`
- `CreateFinanceiroSaida::handle(array $payload): FinanceiroSaida`
- GET `/api/financeiro/saidas` requires `visualizar_financeiro`.
- POST `/api/financeiro/saidas` requires `gestao_financeira`.

- [ ] **Step 1: Write failing API tests**

Testar lista vazia, filtros `search/status/date_from/date_to`, paginação `(data,id)`, criação aberta, criação paga, data_pagamento default server-side, rejeição de `cancelada`, rejeição de `id/protocolo/assinatura_protocolo/foi_pago/caixa_sessao_id/created_at/updated_at`, valor inválido e RBAC.

- [ ] **Step 2: Run CI and verify RED**

Expected: rotas/classes inexistentes.

- [ ] **Step 3: Implement minimal list/create API**

Listagem ordenada `data DESC, id DESC`; `limit` com teto 100; cursor opaco contendo data+id. Search usa `ILIKE` em protocolo, descrição, tipo e destino. POST normaliza strings com `Str::squish`, aceita apenas `status=aberta|paga`, e deixa protocolo/foi_pago/caixa para o banco.

- [ ] **Step 4: Run CI and verify GREEN**

Expected: API tests e suíte completa verdes.

- [ ] **Step 5: Commit**

`feat: adiciona listagem e criação de saídas`

---

### Task 3: Correção de Saída aberta e efetivação de pagamento

**Files:**
- Extend: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Create: `app/Domain/Financeiro/Actions/UpdateFinanceiroSaida.php`
- Create: `app/Http/Requests/Financeiro/UpdateFinanceiroSaidaRequest.php`
- Create: `app/Http/Controllers/Financeiro/UpdateFinanceiroSaidaController.php`
- Modify: `routes/api.php`

**Interfaces:**
- `UpdateFinanceiroSaida::handle(int $id, array $payload): FinanceiroSaida`
- PATCH `/api/financeiro/saidas/{id}` requires `gestao_financeira`.

- [ ] **Step 1: Write failing transition tests**

Cobrir edição de `aberta`; `aberta -> paga`; pagamento com `forma_pagamento`; Dinheiro/PIX pago vinculando ao único Caixa aberto; outras formas sem vínculo; PATCH para `cancelada` rejeitado; edição de `paga` rejeitada com 409; edição/reabertura de `cancelada` rejeitada; campos server-side rejeitados.

- [ ] **Step 2: Run CI and verify RED**

Expected: endpoint/action ausentes.

- [ ] **Step 3: Implement minimal update action**

Usar `DB::transaction` + `lockForUpdate()->findOrFail($id)`. Após lock, exigir estado `aberta`. Aplicar somente campos permitidos. Se `status='paga'`, preencher/normalizar data_pagamento e persistir uma única vez; trigger do Caixa decide vínculo. Mapear conflito de estado conhecido para `DomainException` -> HTTP 409.

- [ ] **Step 4: Run CI and verify GREEN**

Expected: transições e integração Caixa verdes.

- [ ] **Step 5: Commit**

`feat: implementa pagamento e edição de saídas abertas`

---

### Task 4: Estorno formal de Saída

**Files:**
- Extend: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Create: `app/Domain/Financeiro/Actions/ReverseFinanceiroSaida.php`
- Create: `app/Http/Requests/Financeiro/ReverseFinanceiroSaidaRequest.php`
- Create: `app/Http/Controllers/Financeiro/ReverseFinanceiroSaidaController.php`
- Modify: `routes/api.php`

**Interfaces:**
- `ReverseFinanceiroSaida::handle(int $id, string $motivo, string $userId): array{saida:FinanceiroSaida,estorno:FinanceiroEstorno}`
- POST `/api/financeiro/saidas/{id}/estorno` requires `gestao_financeira`.

- [ ] **Step 1: Write failing reversal tests**

Cobrir estorno de aberta e paga; motivo obrigatório; criação de `financeiro_estornos` com origem `saida`; saída vira `cancelada`/`foi_pago=false`; preserva valor/protocolo/data_pagamento/caixa_sessao_id; segundo estorno retorna 409 sem duplicar; saída inexistente 404; RBAC.

- [ ] **Step 2: Run CI and verify RED**

Expected: endpoint/action ausentes.

- [ ] **Step 3: Implement minimal reversal action**

Usar transação + `FOR UPDATE`; normalizar motivo com `Str::squish`; detectar estorno existente antes de escrever; criar primeiro `FinanceiroEstorno`, depois mudar a Saída para `cancelada` dentro da mesma transação, permitindo que o trigger físico valide a existência do estorno. Preservar `caixa_sessao_id` e data_pagamento.

- [ ] **Step 4: Run CI and verify GREEN**

Expected: estorno formal e suíte completa verdes.

- [ ] **Step 5: Commit**

`feat: adiciona estorno formal de saídas`

---

### Task 5: Contrato, provisionamento e verificação final

**Files:**
- Create: `docs/contracts/financeiro-saidas.json`
- Create: `docs/financeiro-saidas.md`
- Modify if required: `docs/ARCHITECTURE.md`
- Extend if required: `tests/Feature/Provisioning/TenantProvisionerTest.php`

**Interfaces:**
- Contract JSON version 1 documents accepted/server-owned fields, state transitions, RBAC, Caixa and reversal authority.

- [ ] **Step 1: Add contract/provisioning assertions first where behavior is executable**

Provar que novo tenant recebe migration/invariantes e que rotas não incluem DELETE.

- [ ] **Step 2: Run CI and verify any RED**

Expected: somente gaps reais falham.

- [ ] **Step 3: Add minimal docs/contract/provisioning changes**

Documentar: `Frontend lê. Backend calcula. Nada é destrutivo.`, máquina de estados, protocolo server-side, imutabilidade terminal, estorno e vínculo Caixa.

- [ ] **Step 4: Run final verification on one SHA**

Required green gates on the exact final SHA: Composer validate, Composer audit, Supabase manifest, Pint, Larastan level 8, Pest full suite, repository guards.

- [ ] **Step 5: Compare branch against base**

Expected: `behind_by=0`; only files of Financeiro Saídas plus required shared route/docs changes.

- [ ] **Step 6: Commit**

`docs: fecha contrato de saídas financeiras`
