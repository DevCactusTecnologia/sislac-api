# Hardening Integração Supabase Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar divergências comprovadas entre SISLAC Laravel, Supabase live e `sislacprivado`, mantendo Supabase Auth durante a transição e sem dual-write.

**Architecture:** O frontend continua autenticando no Supabase, mas cada módulo migrado passa a ter o Laravel como autoridade operacional. Enquanto o cutover não ocorre, o Supabase live recebe apenas correções necessárias para manter o comportamento legado compatível com as invariantes já canônicas no Laravel. Guards offline/live devem provar o contrato migrado; nenhuma divergência é escondida por hash ou lista manual desatualizada.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 17 / React 18 / TypeScript / Supabase Postgres + Auth / Pest / Vitest / SQL regression tests.

**Spec:** `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`

## Global Constraints

- Sem dual-write Supabase + Laravel.
- Mudanças em produção Supabase somente depois de replay/testes da migration versionada.
- RLS + grants explícitos de menor privilégio nos objetos expostos, conforme documentação oficial Supabase.
- Funções públicas preferem `SECURITY INVOKER`; `SECURITY DEFINER` somente em boundary privada e com `search_path` controlado.
- Laravel usa validação server-side, transações e autorização existentes; não duplicar regra de domínio no frontend.
- Código enxuto: nenhum Redis, fila, event bus, cache novo ou abstração preventiva.
- Nenhum merge em `main` nesta execução.

---

### Task 1: Corrigir A Receber/Resumo no Supabase sem alterar dados

**Files:**
- Modify: `DevCactusTecnologia/sislacprivado:supabase/tests/schema_contract_regressions.sql`
- Create: `DevCactusTecnologia/sislacprivado:supabase/migrations/20260910213000_fix_financeiro_cancelled_exam_totals.sql`

**Interfaces:**
- Consumes: `public.atendimento_exames.status`, `cobranca_destino`, `financeiro_a_receber_v2`, `financeiro_a_receber_totais`, `financeiro_resumo`.
- Produces: as três funções ignoram exame `status='cancelado'` em cobrança de paciente.

- [ ] **Step 1: Write the failing regression test**

Adicionar assertions que inspecionem os três corpos de função e exijam filtro explícito de exame cancelado no ramo paciente.

- [ ] **Step 2: Run replay and verify RED**

Run: CI `database-replay` da branch `hardening-supabase-bridge-p0`.
Expected: FAIL somente na nova regressão financeira.

- [ ] **Step 3: Write minimal migration**

`CREATE OR REPLACE FUNCTION` preservando assinatura, retorno, ACL e semântica atual, alterando apenas os CTEs de paciente para incluir `AND e.status <> 'cancelado'`.

- [ ] **Step 4: Run full CI and verify GREEN**

Expected: replay, lint, SQL regressions, TypeScript, Vitest, ESLint, build e Playwright verdes.

- [ ] **Step 5: Apply the same migration to Supabase live**

Usar migration versionada, não SQL ad-hoc. Depois comparar os corpos live e medir novamente os três atendimentos afetados sem alterar registros clínicos.

### Task 2: Remover caminho destrutivo de Saídas e reduzir privilégio

**Files:**
- Modify: `DevCactusTecnologia/sislacprivado:supabase/tests/rls_policy_regressions.sql`
- Modify: `DevCactusTecnologia/sislacprivado:src/data/financeiroStore.ts`
- Create: `DevCactusTecnologia/sislacprivado:supabase/migrations/20260910214500_harden_financeiro_saidas_delete.sql`

**Interfaces:**
- Consumes: estorno formal `financeiro_estornar('saida', ...)`.
- Produces: DELETE físico de `financeiro_saidas` indisponível para browser roles; UI continua usando estorno formal.

- [ ] **Step 1: Add RED SQL assertion**

Exigir `has_table_privilege('authenticated','public.financeiro_saidas','DELETE') = false` e o mesmo para `anon`.

- [ ] **Step 2: Verify RED in database replay**

Expected: FAIL porque o projeto live/replay ainda herda DELETE.

- [ ] **Step 3: Apply least-privilege migration**

Revogar DELETE de `anon` e `authenticated`; substituir policy permissiva de delete por bloqueio explícito ou removê-la quando grant já bloqueia a operação. Não revogar SELECT/INSERT/UPDATE necessários ao frontend legado nesta fase.

- [ ] **Step 4: Remove dead `removeSaida()`**

Remover somente a função órfã; não alterar API pública usada pela tela de estorno.

- [ ] **Step 5: Full CI GREEN, then apply live migration**

Verificar `financeiro_estornar` funcionando por contrato e confirmar grants live após migration.

### Task 3: Tornar o gate Laravel realmente representativo

**Files:**
- Modify: `docs/contracts/supabase-baseline.json`
- Modify: `scripts/check-supabase-contract.php`
- Modify: `app/Console/Commands/CheckSupabaseLiveContract.php`
- Create/Modify: classes em `app/Platform/Supabase/` somente quando necessárias para contratos migrados.
- Test: `tests/Feature/Platform/Supabase/*`

**Interfaces:**
- Consumes: contratos versionados de Pacientes, Atendimentos, Rotina, Financeiro Core, Totais, Caixa e Saídas.
- Produces: um registro único de contratos migrados usado tanto pelo check offline quanto pelo gate live.

- [ ] **Step 1: RED test proving current gate sees only Pacientes**
- [ ] **Step 2: Introduce a minimal contract registry**
- [ ] **Step 3: Make offline and live checks consume the same registry**
- [ ] **Step 4: Run Pest PostgreSQL + Pint + Larastan level 8 + guards**

### Task 4: Corrigir observabilidade de conformidade sem mascarar falhas

**Files:**
- Inspect only: `.github/workflows/production-conformance.yml`
- No code change unless workflow mapping itself is wrong.

**Interfaces:**
- Consumes: `SUPABASE_DB_URL` de role dedicada read-only.
- Produces: monitor que realmente compara replay/snapshot com produção.

- [ ] **Step 1: Keep fail-closed behavior**
- [ ] **Step 2: Verify the missing input is credential configuration, not workflow logic**
- [ ] **Step 3: Do not commit any database credential**

### Task 5: Compatibilidade pré-cutover

**Files:**
- Future implementation after Tasks 1-4: adapter HTTP Laravel no `sislacprivado`, import/correlation de `central.users`/memberships e Financeiro aggregate endpoints.

**Interfaces:**
- Consumes: Bearer Supabase + membership Laravel.
- Produces: cutover modular sem dual-write.

- [ ] **Step 1: Port `financeiro_resumo`, `financeiro_a_receber_totais` e A Receber Convênios para Laravel**
- [ ] **Step 2: Add deterministic user/membership import before switching consumers**
- [ ] **Step 3: Switch modules one at a time; Supabase remains Auth provider**

## Self-review

- P0 cálculo incorreto: Task 1.
- DELETE físico Saídas/grants: Task 2.
- Guards incompletos: Task 3.
- Monitor sem credencial: Task 4.
- Pré-requisitos de cutover: Task 5.
- Nenhuma tarefa introduz dual-write ou infraestrutura não solicitada.
