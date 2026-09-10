# Atendimentos Laravel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o agregado de Atendimentos no backend Laravel definitivo com schema tenant fiel, APIs transacionais, autorização, auditoria, paginação/KPIs e gates de regressão, mantendo o cutover do frontend bloqueado até Rotina e Financeiro cobrirem suas dependências.

**Architecture:** O domínio vive exclusivamente no PostgreSQL físico do laboratório selecionado pelo middleware de tenancy. `atendimentos`, `atendimento_exames` e `atendimento_pagamentos` formam um único agregado transacional; protocolo e campos derivados são invariantes de banco, enquanto HTTP usa controllers/requests/queries/actions pequenos e explícitos. Nenhum consumidor escreve no Supabase.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL 17, stancl/tenancy, Supabase Auth via Bearer, Pest, Larastan nível 8.

**Spec:** `docs/superpowers/specs/2026-09-08-atendimentos-laravel-design.md`

## Global Constraints

- Laravel é o backend definitivo; Supabase é baseline/source e origem de identidade clínica durante a transição.
- A API clínica usa `supabase.auth`/Bearer antes de tenancy e autorização; sessão Laravel permanece exclusiva do Super Admin web.
- Um PostgreSQL físico por laboratório; nenhum domínio clínico no banco central.
- Nenhuma alteração no frontend React/Vite nesta onda.
- Nenhuma escrita ou DDL no Supabase nesta onda.
- Sem Redis, filas, WebSocket, CQRS, event bus, repository layer ou DTOs genéricos.
- Nenhum `DELETE /api/atendimentos`; cancelamento é evento de negócio.
- Cutover do frontend permanece bloqueado até Rotina e Financeiro/Convênios/Caixa cobrirem as invariantes dependentes.
- Composer Validate/Audit, Pint, Larastan nível 8, Pest e guards são gates obrigatórios.

---

### Task 1: Contrato e schema tenant do agregado

**Files:**
- Create: `docs/contracts/atendimentos.json`
- Create: `tests/Contract/AtendimentosContractTest.php`
- Create: `tests/Feature/Domain/Atendimentos/AtendimentoSchemaTest.php`
- Create: `database/migrations/tenant/2026_09_08_000200_create_atendimentos_tables.php`
- Create: `app/Domain/Atendimentos/Models/Atendimento.php`
- Create: `app/Domain/Atendimentos/Models/AtendimentoExame.php`
- Create: `app/Domain/Atendimentos/Models/AtendimentoPagamento.php`

**Interfaces:**
- Produces: tenant tables `protocolo_sequence`, `atendimentos`, `atendimento_exames`, `atendimento_pagamentos`, `atendimento_audit` and Eloquent models on the request-selected tenant connection.

- [ ] **Step 1: Write failing schema/contract tests**

Tests must assert exact critical columns/defaults/types, three child relationships, no `tenant_id`, PostgreSQL-only JSONB/UUID types and that the contract fixture includes the Supabase baseline columns required by the approved spec.

- [ ] **Step 2: Run RED**

Run: `vendor/bin/pest tests/Contract/AtendimentosContractTest.php tests/Feature/Domain/Atendimentos/AtendimentoSchemaTest.php`
Expected: FAIL because contract, migrations and models do not exist.

- [ ] **Step 3: Implement minimal schema/models/contract**

Create the five tenant tables. `atendimentos` must contain immutable server fields as guarded attributes; child models must define `belongsTo`, parent `hasMany`. Use numeric precision `14,2` consistently for financial totals and values.

- [ ] **Step 4: Run GREEN**

Run the same Pest command; expected PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: adiciona schema tenant de Atendimentos`

---

### Task 2: Protocolo, idempotência, recomputação e auditoria

**Files:**
- Create: `tests/Feature/Domain/Atendimentos/AtendimentoInvariantsTest.php`
- Create: `database/migrations/tenant/2026_09_08_000300_add_atendimento_invariants.php`
- Create: `app/Domain/Atendimentos/Support/AtendimentoProtocolo.php`

**Interfaces:**
- Produces: `AtendimentoProtocolo::next(): string`; database invariants for protocol assignment/protection, unique idempotency, aggregate recompute and append-only audit.

- [ ] **Step 1: Write failing invariant tests**

Cover: protocol auto-generation as 7 digits, client protocol ignored, protocol update rejected, repeated idempotency key cannot create a second parent, child exam insert/update/delete recalculates status/totals, payment insert/update changes payment status, and audit rows cannot be updated/deleted.

- [ ] **Step 2: Run RED**

Run: `vendor/bin/pest tests/Feature/Domain/Atendimentos/AtendimentoInvariantsTest.php`
Expected: FAIL because functions/triggers do not exist.

- [ ] **Step 3: Implement invariants**

Use `protocolo_sequence` with `INSERT ... ON CONFLICT ... RETURNING` for concurrency-safe numbering. Add BEFORE INSERT assignment, BEFORE UPDATE protection, recompute function/triggers with no-op guard, and audit trigger/functions. Do not add Rotina, Caixa, Estoque, Convênios or Resultados rules.

- [ ] **Step 4: Run GREEN**

Run invariant tests; expected PASS on PostgreSQL 17 CI.

- [ ] **Step 5: Commit**

Commit message: `feat: protege invariantes de Atendimentos`

---

### Task 3: Leitura, cursor, filtros e KPIs

**Files:**
- Create: `tests/Feature/Domain/Atendimentos/AtendimentoReadApiTest.php`
- Create: `tests/Feature/Performance/AtendimentoQueryPerformanceTest.php`
- Create: `app/Domain/Atendimentos/Queries/ListAtendimentos.php`
- Create: `app/Domain/Atendimentos/Queries/AtendimentoKpis.php`
- Create: `app/Domain/Atendimentos/Support/AtendimentoCursor.php`
- Create: `app/Http/Requests/Atendimentos/ListAtendimentosRequest.php`
- Create: `app/Http/Controllers/Atendimentos/ListAtendimentosController.php`
- Create: `app/Http/Controllers/Atendimentos/AtendimentoKpisController.php`
- Create: `app/Http/Controllers/Atendimentos/ShowAtendimentoController.php`
- Create: `app/Http/Controllers/Atendimentos/ShowAtendimentoByProtocoloController.php`
- Create: `app/Http/Resources/Atendimentos/AtendimentoResource.php`
- Modify: `routes/api.php`

**Interfaces:**
- `ListAtendimentos::handle(array $filters): array{data: array, next_cursor: ?string}`
- `AtendimentoKpis::handle(array $filters): array{total:int, aguardando_coleta:int, em_analise:int, pendentes:int, finalizados:int, receita_total:string}`
- `AtendimentoCursor::encode(string $data, int $id): string`
- `AtendimentoCursor::decode(string $cursor): array{data:string,id:int}`

- [ ] **Step 1: Write failing read API tests**

Cover authentication/permission, cursor `(data,id)`, stable descending order, page-size clamp 10..200, filters `status`, `pagamento`, `unidade_id`, `data_inicio`, `data_fim`, `q`, details by id/protocol and KPI status semantics. Include a test proving the date filters work even though the current Supabase RPC signature lacks them.

- [ ] **Step 2: Run RED**

Run: `vendor/bin/pest tests/Feature/Domain/Atendimentos/AtendimentoReadApiTest.php tests/Feature/Performance/AtendimentoQueryPerformanceTest.php`
Expected: FAIL with missing routes/classes.

- [ ] **Step 3: Implement minimal read path**

Use query builder/Eloquent with explicit selected columns, composite cursor predicate `(data,id) < (?,?)`, indexed filters, and no N+1 on detail. Use `supabase.auth` first and reuse existing `tenant.permission:visualizar_atendimentos` after tenant selection.

- [ ] **Step 4: Run GREEN**

Run the same tests; expected PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: adiciona leitura paginada de Atendimentos`

---

### Task 4: Criação transacional e idempotente

**Files:**
- Create: `tests/Feature/Domain/Atendimentos/AtendimentoCreateApiTest.php`
- Create: `app/Domain/Atendimentos/Actions/CreateAtendimento.php`
- Create: `app/Http/Requests/Atendimentos/StoreAtendimentoRequest.php`
- Create: `app/Http/Controllers/Atendimentos/StoreAtendimentoController.php`
- Modify: `routes/api.php`

**Interfaces:**
- `CreateAtendimento::handle(array $payload): array{ok:bool, duplicate:bool, protocolo:string, atendimento_id:int, guia_numero:?string}`

- [ ] **Step 1: Write failing creation tests**

Cover: `criar_atendimento` authorization, server protocol overriding client input, parent+exams+payments atomicity, rollback on invalid child, idempotent retry returning same id/protocol, internal exam default `pendente`, outsourced default `digitado`, `valor_original` fallback and derived totals/status after commit.

- [ ] **Step 2: Run RED**

Run: `vendor/bin/pest tests/Feature/Domain/Atendimentos/AtendimentoCreateApiTest.php`
Expected: FAIL with missing route/action.

- [ ] **Step 3: Implement minimal transaction**

Use `DB::transaction`. Whitelist parent scalar fields. Never accept protocol/status/totals from request. Persist exams/payments in the same transaction. On idempotency race, re-read by key and return the already-created row.

- [ ] **Step 4: Run GREEN**

Run create tests; expected PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: cria Atendimentos atomicamente`

---

### Task 5: Edição, preservação clínica e cancelamento

**Files:**
- Create: `tests/Feature/Domain/Atendimentos/AtendimentoUpdateApiTest.php`
- Create: `tests/Feature/Security/AtendimentoAuthorizationTest.php`
- Create: `app/Domain/Atendimentos/Actions/UpdateAtendimento.php`
- Create: `app/Http/Requests/Atendimentos/UpdateAtendimentoRequest.php`
- Create: `app/Http/Controllers/Atendimentos/UpdateAtendimentoController.php`
- Modify: `routes/api.php`

**Interfaces:**
- `UpdateAtendimento::handle(int $id, array $payload, ?string $justificativa): Atendimento`

- [ ] **Step 1: Write failing update/security tests**

Cover: scalar patch, derived fields/protocol ignored, exam matching by identity + `amostra_seq`, same occurrence preserves status/order/`valor_original`, new sample does not inherit prior status, replacing exam list is atomic, cancellation requires `cancelar_atendimento`, pure payments require `registrar_pagamento`, normal edit requires `editar_atendimento`, and no physical delete route exists.

- [ ] **Step 2: Run RED**

Run: `vendor/bin/pest tests/Feature/Domain/Atendimentos/AtendimentoUpdateApiTest.php tests/Feature/Security/AtendimentoAuthorizationTest.php`
Expected: FAIL with missing route/action.

- [ ] **Step 3: Implement update action**

Use one `DB::transaction`. Build existing occurrence map keyed by `(exame_id ?: normalized_name)#amostra_seq`; preserve protected clinical fields. For cancellation mark child exams `cancelado` and parent reason; do not implement Caixa/Estorno/Fatura behavior in this task. Explicitly reject/return conflict for update shapes that would require an unimplemented financial/convênio invariant rather than silently producing partial equivalence.

- [ ] **Step 4: Run GREEN**

Run update/security tests; expected PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: edita e cancela Atendimentos com segurança`

---

### Task 6: Provisionamento, documentação e gates finais

**Files:**
- Modify: `tests/Feature/Provisioning/TenantProvisionerTest.php`
- Modify: `docs/ARCHITECTURE.md`
- Modify: `README.md`
- Modify: `scripts/check-backend-scope.sh` only if a new guard is required by this feature

**Interfaces:**
- New tenants provisioned by existing `TenantProvisioner` must contain the Atendimentos tables/invariants without special-case provisioning code.

- [ ] **Step 1: Write failing provisioning/cutover assertions**

Add tests/assertions proving a newly provisioned tenant receives Atendimentos migrations and documentation states that backend readiness does not authorize frontend cutover before Rotina and Financeiro dependency coverage.

- [ ] **Step 2: Run RED where applicable**

Run: `vendor/bin/pest tests/Feature/Provisioning/TenantProvisionerTest.php`
Expected: fail before tenant migrations are complete; after previous tasks it may already pass for schema presence, in which case add only assertions that distinguish the new invariant behavior.

- [ ] **Step 3: Final documentation/guard cleanup**

Document exact routes, permissões, `supabase.auth`/Bearer, aggregate boundary and cutover gate. Do not add deploy/runtime dependencies.

- [ ] **Step 4: Run complete verification**

Run:

```bash
composer validate --strict
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
bash scripts/check-backend-scope.sh
bash scripts/check-database-contract.sh
bash scripts/check-no-central-in-tenant.sh
bash scripts/check-postgres-bootstrap.sh
bash scripts/check-file-size.sh
```

Expected: all green on the same SHA.

- [ ] **Step 5: Review diff and commit**

Confirm no frontend/Supabase/runtime-infra drift. Commit message: `docs: fecha onda Atendimentos Laravel`.

- [ ] **Step 6: Maintain draft PR on the current foundation**

Enquanto a Fase 0 não estiver em `main`, o PR `feat/atendimentos-laravel` deve permanecer baseado em `chore/fase-0-foundation-hardening`, com SHA/CI exatos e bloqueio de cutover explícito. Depois que a Fase 0 for integrada em `main`, retargetar o PR #7 para `main`, executar novamente todos os gates no novo contexto e não mesclar sem aprovação explícita do usuário.
