# Financeiro — Convênios/Faturas Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Portar o núcleo de Convênios/Faturas para o PostgreSQL tenant e Laravel, com elegibilidade canônica, faturamento transacional, pagamento, cancelamento e estorno sem apagar histórico.

**Architecture:** O PostgreSQL tenant mantém as invariantes, numeração e totais; Laravel orquestra transações e autorização com Controllers finos. O Supabase live é baseline somente leitura. A onda não inclui Glosa/Reapresentação nem Competência.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL 17, Pest, Larastan nível 8, stancl/tenancy.

**Spec:** `docs/superpowers/specs/2026-09-10-financeiro-convenios-faturas-core-design.md`

## Global Constraints

- Base exata: `a951b3f093a2ae088a18f98802b034597e359d1d`.
- Branch: `fase-financeiro-convenios-faturas-core`.
- Supabase live: somente SELECT/read-only; nenhuma DDL ou escrita.
- Banco clínico/financeiro: somente conexão tenant; nunca central.
- Sem Redis, filas, cache financeiro, workers, WebSocket, CQRS ou event bus.
- Sem Glosa/Reapresentação, Competência, TISS/XML, ERP ou frontend cutover.
- TDD obrigatório: RED observado antes de cada produção nova.
- Funções PostgreSQL novas: `SECURITY INVOKER`, `SET search_path = ''`, nomes schema-qualified.
- Não fazer merge desta branch.

---

### Task 1: Schema canônico e invariantes PostgreSQL

**Files:**
- Create: `database/migrations/tenant/2026_09_10_000900_add_convenios_faturas_core.php`
- Create: `tests/Feature/Domain/Financeiro/ConveniosFaturasSchemaTest.php`
- Modify: `tests/Feature/Provisioning/TenantProvisionerTest.php`

**Interfaces:**
- Consumes: `protocolo_sequence`, `atendimentos`, `atendimento_exames`, `financeiro_estornos`.
- Produces: `convenios`, `convenio_faturas`, `convenio_fatura_itens` e invariantes de protocolo, totais, elegibilidade, imutabilidade e DELETE.

- [ ] **Step 1: escrever RED de schema**

Cobrir em Postgres real:

```php
it('cria as tabelas de convênios e faturas sem tenant_id');
it('semeia e protege o convênio Particular id zero');
it('gera código FAT sequencial e imutável');
it('rejeita período invertido e valores negativos');
it('calcula subtotal e total a partir dos itens');
it('rejeita item cujo exame não é finalizado ou não pertence ao convênio');
it('rejeita segundo vínculo ativo do mesmo exame');
it('permite refaturar exame cujo vínculo anterior pertence a fatura cancelada');
it('bloqueia update e delete de item de fatura');
it('bloqueia delete físico de fatura');
it('protege fatura paga ou cancelada contra reescrita');
```

- [ ] **Step 2: executar RED no CI**

Commit apenas do teste e confirmar falha porque `convenios`/`convenio_faturas` ainda não existem.

- [ ] **Step 3: implementar migration mínima**

A migration deve:

```text
convenios
convenio_faturas
convenio_fatura_itens
```

Criar `Particular` id=0; protocolo FAT via `protocolo_sequence`; snapshot do item pelo banco; trigger de elegibilidade com lock em `atendimento_exames`; recálculo de totais; proteção terminal e bloqueio de DELETE.

- [ ] **Step 4: provisionamento**

Atualizar a expectativa de última migration tenant para `2026_09_10_000900_add_convenios_faturas_core`.

- [ ] **Step 5: executar GREEN completo**

Esperado: novo schema test verde e toda a suíte anterior verde.

- [ ] **Step 6: commit**

```bash
git add database/migrations/tenant/2026_09_10_000900_add_convenios_faturas_core.php tests/Feature/Domain/Financeiro/ConveniosFaturasSchemaTest.php tests/Feature/Provisioning/TenantProvisionerTest.php
git commit -m "feat: adiciona core de convênios e faturas"
```

---

### Task 2: Leituras canônicas de convênios e faturáveis

**Files:**
- Create: `app/Domain/Financeiro/Models/Convenio.php`
- Create: `app/Domain/Financeiro/Queries/ListConvenios.php`
- Create: `app/Domain/Financeiro/Queries/ListAReceberConvenios.php`
- Create: `app/Domain/Financeiro/Queries/ListConvenioItensFaturaveis.php`
- Create: `app/Http/Controllers/Financeiro/ListConveniosController.php`
- Create: `app/Http/Controllers/Financeiro/ListAReceberConveniosController.php`
- Create: `app/Http/Controllers/Financeiro/ListConvenioItensFaturaveisController.php`
- Create: `app/Http/Requests/Financeiro/ListAReceberConveniosRequest.php`
- Create: `app/Http/Requests/Financeiro/ListConvenioItensFaturaveisRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Financeiro/ConveniosFaturaveisApiTest.php`

**Interfaces:**
- Consumes: schema da Task 1 e `visualizar_financeiro`.
- Produces: três endpoints de leitura com um único predicado de elegibilidade.

- [ ] **Step 1: escrever RED da leitura**

Cobrir:

```text
GET /api/financeiro/convenios
GET /api/financeiro/a-receber/convenios
GET /api/financeiro/convenios/{id}/itens-faturaveis?periodo_inicio=YYYY-MM-DD&periodo_fim=YYYY-MM-DD
```

Casos obrigatórios: sem autenticação, sem membership, sem permissão, tenant correto, apenas finalizados, destino convênio, convenio_cobranca_id correto, período inclusivo, fatura ativa exclui item, fatura cancelada devolve item ao faturável, agregação A Receber igual ao conjunto faturável.

- [ ] **Step 2: executar RED**

Esperado: 404 nas rotas novas; testes anteriores permanecem verdes.

- [ ] **Step 3: implementar Queries/Controllers mínimos**

Centralizar o predicado SQL compartilhado para evitar drift entre A Receber e itens faturáveis. Nenhuma tabela de saldo.

- [ ] **Step 4: executar GREEN**

Esperado: novas leituras e suíte completa verdes.

- [ ] **Step 5: commit**

```bash
git add app/Domain/Financeiro app/Http/Controllers/Financeiro app/Http/Requests/Financeiro routes/api.php tests/Feature/Domain/Financeiro/ConveniosFaturaveisApiTest.php
git commit -m "feat: adiciona leituras canônicas de convênios"
```

---

### Task 3: Criação, listagem e detalhe de fatura

**Files:**
- Create: `app/Domain/Financeiro/Models/ConvenioFatura.php`
- Create: `app/Domain/Financeiro/Models/ConvenioFaturaItem.php`
- Create: `app/Domain/Financeiro/Actions/CreateConvenioFatura.php`
- Create: `app/Domain/Financeiro/Queries/ListConvenioFaturas.php`
- Create: `app/Http/Controllers/Financeiro/CreateConvenioFaturaController.php`
- Create: `app/Http/Controllers/Financeiro/ListConvenioFaturasController.php`
- Create: `app/Http/Controllers/Financeiro/ShowConvenioFaturaController.php`
- Create: `app/Http/Requests/Financeiro/CreateConvenioFaturaRequest.php`
- Create: `app/Http/Requests/Financeiro/ListConvenioFaturasRequest.php`
- Create: `app/Http/Resources/Financeiro/ConvenioFaturaResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Financeiro/ConvenioFaturasApiTest.php`

**Interfaces:**
- Consumes: elegibilidade da Task 2 e `gestao_financeira`.
- Produces: criação transacional e leituras de fatura.

- [ ] **Step 1: escrever RED**

Cobrir criação com `convenio_id`, período, `exame_ids`, desconto e observação; rejeitar campos server-owned; rejeitar Particular/inativo; rejeitar lista vazia/duplicada; rollback integral se um exame for inelegível; garantir protocolo FAT; garantir snapshot e totais do banco; garantir concorrência sem dupla fatura ativa.

Cobrir:

```text
POST /api/financeiro/faturas
GET  /api/financeiro/faturas
GET  /api/financeiro/faturas/{id}
```

Listagem com cursor `created_at DESC, id DESC` e resposta monetária em duas casas.

- [ ] **Step 2: executar RED**

Esperado: rotas inexistentes.

- [ ] **Step 3: implementar produção mínima**

`CreateConvenioFatura` usa transação, lock do convênio e exames ordenados; insere IDs e deixa o banco definir protocolo, snapshots e totais. Controller não calcula dinheiro.

- [ ] **Step 4: executar GREEN**

- [ ] **Step 5: commit**

```bash
git add app/Domain/Financeiro app/Http/Controllers/Financeiro app/Http/Requests/Financeiro app/Http/Resources/Financeiro routes/api.php tests/Feature/Domain/Financeiro/ConvenioFaturasApiTest.php
git commit -m "feat: implementa faturamento de convênios"
```

---

### Task 4: Pagamento, cancelamento e estorno formal

**Files:**
- Create: `app/Domain/Financeiro/Actions/PayConvenioFatura.php`
- Create: `app/Domain/Financeiro/Actions/CancelConvenioFatura.php`
- Create: `app/Domain/Financeiro/Actions/ReverseConvenioFatura.php`
- Create: `app/Http/Controllers/Financeiro/PayConvenioFaturaController.php`
- Create: `app/Http/Controllers/Financeiro/CancelConvenioFaturaController.php`
- Create: `app/Http/Controllers/Financeiro/ReverseConvenioFaturaController.php`
- Create: `app/Http/Requests/Financeiro/PayConvenioFaturaRequest.php`
- Create: `app/Http/Requests/Financeiro/CancelConvenioFaturaRequest.php`
- Create: `app/Http/Requests/Financeiro/ReverseConvenioFaturaRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Financeiro/ConvenioFaturasLifecycleApiTest.php`

**Interfaces:**
- Consumes: `financeiro_estornos` já existente com origem `fatura`.
- Produces: lifecycle terminal auditável.

- [ ] **Step 1: escrever RED do pagamento**

Cobrir `aberta → paga`, forma obrigatória, data default server-side, total canônico, repetição/estado terminal bloqueado e nenhuma criação em `atendimento_pagamentos`/Caixa.

- [ ] **Step 2: executar RED**

- [ ] **Step 3: implementar pagamento e obter GREEN**

- [ ] **Step 4: escrever RED do cancelamento**

Cobrir motivo obrigatório, apenas aberta, preservação dos itens e retorno destes ao conjunto faturável; paga deve responder conflito indicando estorno.

- [ ] **Step 5: implementar cancelamento e obter GREEN**

- [ ] **Step 6: escrever RED do estorno**

Cobrir somente paga, `financeiro_estornos.origem_tipo='fatura'`, valor igual ao total, ator/motivo, status final cancelada, itens intactos, segundo estorno bloqueado e rollback integral em falha.

- [ ] **Step 7: implementar estorno e obter GREEN**

- [ ] **Step 8: commit**

```bash
git add app/Domain/Financeiro app/Http/Controllers/Financeiro app/Http/Requests/Financeiro routes/api.php tests/Feature/Domain/Financeiro/ConvenioFaturasLifecycleApiTest.php
git commit -m "feat: fecha ciclo financeiro de faturas"
```

---

### Task 5: Contrato, arquitetura e verificação final

**Files:**
- Create: `docs/contracts/financeiro-convenios-faturas-core.json`
- Create: `docs/financeiro-convenios-faturas-core.md`
- Modify: `docs/ARCHITECTURE.md`
- Create: `tests/Feature/Domain/Financeiro/ConvenioFaturasRouteContractTest.php`

**Interfaces:**
- Consumes: todas as Tasks anteriores.
- Produces: contrato executável/documentado e prova final da onda.

- [ ] **Step 1: fixar contrato de rotas e ausência de DELETE**

O teste deve verificar os nove endpoints aprovados e garantir que nenhuma rota DELETE de convênio/fatura existe.

- [ ] **Step 2: documentar campos client-writable/server-owned e invariantes**

O JSON deve listar explicitamente inputs, permissões, estados, fórmula de total, regra de elegibilidade, estorno e fora de escopo.

- [ ] **Step 3: atualizar arquitetura**

Adicionar somente a seção Convênios/Faturas Core e manter Glosa/Reapresentação/Competência como próximas ondas.

- [ ] **Step 4: executar verificação final no mesmo SHA**

Obrigatório:

```text
composer validate --strict
composer audit --locked
manifesto Supabase
Pint --test
Larastan nível 8
Pest completo em PostgreSQL 17
repository guards
```

- [ ] **Step 5: comparar branch**

Comparar com `a951b3f093a2ae088a18f98802b034597e359d1d` e exigir `behind_by = 0`.

- [ ] **Step 6: registrar SHA e run final**

Só declarar a fase concluída quando o CI do HEAD final terminar `success`. Não fazer merge.
