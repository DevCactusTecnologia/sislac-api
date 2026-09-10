# Financeiro Core — Recebimentos de pacientes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrar para o Laravel o núcleo financeiro de pacientes — A Receber, Recebimentos, registro aditivo de pagamento e estorno formal — sem cálculo financeiro no cliente e sem exclusão destrutiva de pagamentos.

**Architecture:** O agregado `atendimentos` continua sendo a origem clínica/financeira da cobrança do paciente: o valor devido é a soma dos exames ativos cobrados do paciente e o valor recebido é a soma de `atendimento_pagamentos` não estornados. O Laravel expõe consultas derivadas e comandos transacionais; pagamento é append-only, estorno preserva o pagamento original, marca-o como `estornado` e grava uma reversão imutável em `financeiro_estornos`. O Supabase atual permanece baseline de concordância e não recebe escrita nesta subfase.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, stancl/tenancy, Pest, Supabase Auth como autenticação transitória.

**Spec:** `docs/ARCHITECTURE.md`, baseline Supabase live `eramenhnqcbyctyiqwlm`, frontend `DevCactusTecnologia/sislacprivado@57cc9be96703a41b207d530088369da1cc23cd94`.

## Global Constraints

- Laravel é o backend definitivo; Supabase atual é baseline de leitura/concordância durante a migração.
- Database-per-lab: nenhum dado financeiro clínico deve usar a conexão `central`.
- Frontend lê; backend calcula. Não aceitar `saldo`, `valor_pago`, `status_pagamento` ou totais derivados enviados pelo cliente como autoridade.
- Pagamento efetivo é histórico: não atualizar/excluir para “corrigir”. Correção é estorno formal.
- Estorno de pagamento exige motivo não vazio, permissão `gestao_financeira`, transação, lock pessimista e unicidade por origem.
- Registro de pagamento exige `registrar_pagamento`, valor estritamente positivo e não pode ultrapassar o saldo atual do paciente.
- Exame `cancelado` não compõe dívida do paciente; exame cobrado de `convenio` não compõe dívida do paciente.
- Atendimento `Cancelado` não pode receber novo pagamento e não aparece em A Receber/Recebimentos ativos.
- `financeiro_estornos` é append-only.
- Nenhuma dependência nova, Redis, fila, event bus, cache ou abstração genérica nesta subfase.
- TDD obrigatório: RED → implementação mínima → GREEN.
- Gates obrigatórios: `composer validate --strict`, `composer audit --locked`, Pint, Larastan nível 8, Pest paralelo e guards do repositório.

---

### Task 1: Contrato executável do Financeiro Core

**Files:**
- Create: `tests/Feature/Domain/Financeiro/FinanceiroCoreApiTest.php`
- Create: `tests/Feature/Domain/Financeiro/FinanceiroCoreSchemaTest.php`

**Interfaces:**
- Produces: contrato HTTP e invariantes de persistência para as tarefas seguintes.

- [ ] **Step 1: Write the failing tests**

Cobrir, em banco tenant real: A Receber exclui exame cancelado/convênio e pagamentos estornados; registro parcial/total; rejeição de valor zero/negativo e sobrepagamento; recebimentos listam somente pagamentos efetivos; estorno exige motivo e `gestao_financeira`; estorno preserva pagamento, reabre saldo/status; segundo estorno é rejeitado; `financeiro_estornos` bloqueia UPDATE/DELETE; `atendimento_pagamentos` bloqueia DELETE.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Domain/Financeiro --colors=always`
Expected: FAIL porque rotas, tabela de estorno e comandos Financeiro ainda não existem.

- [ ] **Step 3: Commit RED**

Commit somente os testes e observar o CI da branch `fase-financeiro-core` falhar pelas funcionalidades ausentes, não por erro de sintaxe/setup.

### Task 2: Integridade financeira no PostgreSQL tenant

**Files:**
- Create: `database/migrations/tenant/2026_09_09_000500_add_financeiro_core.php`
- Create: `app/Domain/Financeiro/Models/FinanceiroEstorno.php`

**Interfaces:**
- Produces: `financeiro_estornos`; UNIQUE `(origem_tipo, origem_id)`; motivo obrigatório; bloqueio de UPDATE/DELETE em estorno; bloqueio de DELETE em pagamento.

- [ ] **Step 1: Implement minimal schema**

Criar apenas o necessário para estorno de pagamentos, mantendo colunas compatíveis com o baseline Supabase: `id`, `origem_tipo`, `origem_id`, `motivo`, `valor`, `criado_por`, `criado_em`, `created_at`, `updated_at`. Não criar FK de `criado_por` para o central/auth, pois o tenant DB não pode ter FK cross-database.

- [ ] **Step 2: Enforce database invariants**

CHECK de motivo não vazio, CHECK de origem compatível, UNIQUE por origem, função/trigger append-only para estorno e função/trigger que rejeita DELETE de `atendimento_pagamentos` com instrução de usar estorno.

- [ ] **Step 3: Run schema tests**

Run: `vendor/bin/pest tests/Feature/Domain/Financeiro/FinanceiroCoreSchemaTest.php --colors=always`
Expected: PASS.

### Task 3: Consultas SSOT — A Receber e Recebimentos

**Files:**
- Create: `app/Domain/Financeiro/Queries/ListAReceberPacientes.php`
- Create: `app/Domain/Financeiro/Queries/ListRecebimentosPacientes.php`
- Create: `app/Http/Requests/Financeiro/ListAReceberPacientesRequest.php`
- Create: `app/Http/Requests/Financeiro/ListRecebimentosPacientesRequest.php`
- Create: `app/Http/Controllers/Financeiro/ListAReceberPacientesController.php`
- Create: `app/Http/Controllers/Financeiro/ListRecebimentosPacientesController.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces: `GET /api/financeiro/a-receber/pacientes` e `GET /api/financeiro/recebimentos/pacientes`.

- [ ] **Step 1: Implement A Receber from authoritative rows**

Calcular no PostgreSQL: `valor_total = SUM(atendimento_exames.valor)` apenas para exames não cancelados com cobrança ao paciente; `valor_pago = SUM(atendimento_pagamentos.valor)` apenas para status diferente de `estornado`; `saldo = valor_total - valor_pago`; listar somente saldo positivo. Preservar busca, intervalo de datas, status `pendente|parcial`, cursor `(data,id)` e limite máximo seguro.

- [ ] **Step 2: Implement Recebimentos**

Uma linha por pagamento efetivo, somente atendimentos não cancelados, ordenada por data/id decrescente; não incluir pagamentos estornados. Nenhum cálculo no controller.

- [ ] **Step 3: Run API read tests**

Run: `vendor/bin/pest tests/Feature/Domain/Financeiro/FinanceiroCoreApiTest.php --filter='A Receber|Recebimentos' --colors=always`
Expected: PASS.

### Task 4: Registro aditivo de recebimento e estorno transacional

**Files:**
- Create: `app/Domain/Financeiro/Actions/RegisterPacientePayment.php`
- Create: `app/Domain/Financeiro/Actions/ReversePacientePayment.php`
- Create: `app/Http/Requests/Financeiro/RegisterPacientePaymentRequest.php`
- Create: `app/Http/Requests/Financeiro/ReversePacientePaymentRequest.php`
- Create: `app/Http/Controllers/Financeiro/RegisterPacientePaymentController.php`
- Create: `app/Http/Controllers/Financeiro/ReversePacientePaymentController.php`
- Modify: `app/Platform/Authorization/TenantPermission.php`
- Modify: `app/Platform/Authorization/MembershipAuthorizer.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces: `POST /api/financeiro/atendimentos/{id}/pagamentos` e `POST /api/financeiro/pagamentos/{id}/estorno`.

- [ ] **Step 1: Register payment inside one transaction**

Lock do atendimento com `FOR UPDATE`; recalcular saldo no banco dentro da mesma transação; rejeitar atendimento cancelado, valor <= 0 e valor maior que saldo; inserir uma nova linha `efetuado`; deixar o trigger já existente recomputar `status_pagamento` do atendimento.

- [ ] **Step 2: Reverse payment inside one transaction**

Lock da linha de pagamento com `FOR UPDATE`; rejeitar inexistente ou já estornado; atualizar somente `status_pagamento` para `estornado`; inserir `financeiro_estornos` com `origem_tipo='pagamento'`, valor original, motivo normalizado e usuário autenticado; confiar também no UNIQUE do banco para corrida concorrente.

- [ ] **Step 3: Authorize separately**

`registrar_pagamento` autoriza registro; nova enum `gestao_financeira` autoriza estorno. `admin` continua autorizado por regra existente; perfil financeiro recebe `gestao_financeira`; recepcionista não recebe automaticamente permissão de estorno.

- [ ] **Step 4: Run command/security tests**

Run: `vendor/bin/pest tests/Feature/Domain/Financeiro tests/Feature/Security --colors=always`
Expected: PASS.

### Task 5: Remover caminho destrutivo legado do endpoint de Atendimentos

**Files:**
- Modify: `app/Http/Requests/Atendimentos/UpdateAtendimentoRequest.php`
- Modify: `app/Http/Controllers/Atendimentos/UpdateAtendimentoController.php`
- Modify: `app/Domain/Atendimentos/Actions/UpdateAtendimento.php`
- Modify: `tests/Feature/Domain/Atendimentos/AtendimentoUpdateApiTest.php`

**Interfaces:**
- Consumes: endpoints Financeiro da Task 4.
- Produces: PATCH de atendimento não substitui mais `atendimento_pagamentos`.

- [ ] **Step 1: Make old destructive payment replacement fail explicitly**

Adicionar teste que prova que `PATCH /api/atendimentos/{id}` não aceita `pagamentos` como mecanismo de substituição após a entrada do Financeiro Core.

- [ ] **Step 2: Remove `replacePagamentos`**

Remover validação/autorização/ação de substituição destrutiva. Pagamentos existentes permanecem acessíveis na representação do atendimento, mas mutações passam exclusivamente pelos endpoints Financeiro.

- [ ] **Step 3: Run Atendimentos + Financeiro regression suite**

Run: `vendor/bin/pest tests/Feature/Domain/Atendimentos tests/Feature/Domain/Financeiro --colors=always`
Expected: PASS.

### Task 6: Concordância, documentação e gate final

**Files:**
- Create: `docs/contracts/financeiro-core.json`
- Create: `docs/financeiro-core.md`
- Modify: `docs/ARCHITECTURE.md`

**Interfaces:**
- Produces: contrato versionado e registro explícito de divergências conhecidas do baseline.

- [ ] **Step 1: Record the source contract**

Documentar endpoints, permissões, derivação dos valores, invariantes e o fato de que `financeiro_a_receber_v2` no Supabase live ainda inclui exame cancelado em atendimento ativo; registrar que Laravel não replica esse defeito porque a própria função canônica de recomputação exclui exame cancelado.

- [ ] **Step 2: Run all mandatory gates**

Run: `composer validate --strict --no-interaction && composer audit --locked --no-interaction && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/pest --parallel --colors=always && bash scripts/check-no-central-in-tenant.sh && bash scripts/check-backend-scope.sh && bash scripts/check-postgres-bootstrap.sh && bash scripts/check-database-contract.sh && bash scripts/check-file-size.sh`
Expected: all PASS.

- [ ] **Step 3: Verify CI on the exact final SHA**

Somente declarar a subfase tecnicamente concluída quando os jobs `Qualidade PHP` e `Guards de repositório` estiverem verdes no SHA final da branch.
