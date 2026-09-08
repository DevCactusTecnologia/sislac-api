# Finalização do Backend Laravel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Concluir a fundação do backend Laravel do SISLAC mantendo o frontend existente, usando o Supabase atual como baseline/transição e criando um PostgreSQL dedicado para cada novo laboratório, com Super Admin totalmente Laravel.

**Architecture:** O `sislac-api` mantém banco central para identidade/plataforma, `stancl/tenancy` apenas para database-per-lab e migrations tenant. O banco Supabase atual é conectado ao Laravel como origem de leitura/concordância durante a migração. Novos laboratórios são provisionados pelo Laravel em bancos PostgreSQL físicos separados. O Super Admin é server-rendered no próprio Laravel e usa o banco central; não é criado um segundo SPA.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, Laravel Sanctum 4, stancl/tenancy 3, Pest, Larastan 8, Pint.

**Spec:** `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`

**Nota de execução:** a auditoria final comprovou que o Super Admin Blade não consome `@vite` nem assets compilados. Portanto, a toolchain Vite/Tailwind/NPM do scaffold foi classificada como órfã e removida do backend. Se um consumidor real de assets surgir futuramente, essa toolchain deve ser reintroduzida de forma deliberada, com teste e necessidade concreta.

## Global Constraints

- Documentação oficial Laravel 13, Supabase, PostgreSQL e stancl/tenancy é normativa.
- Frontend React/Vite existente não será redesenhado nesta onda.
- Supabase de produção não recebe alteração destrutiva nesta onda.
- Banco central Laravel contém somente plataforma, identidade, memberships, provisionamento, planos e auditoria.
- Cada novo laboratório recebe um banco PostgreSQL dedicado.
- O banco de cada laboratório nasce exclusivamente das migrations tenant versionadas; nunca de DDL manual em produção.
- O Supabase atual é baseline e origem de leitura/concordância enquanto cada módulo ainda não foi migrado.
- Nenhuma Edge Function é removida antes de existir substituto Laravel e consumidor migrado.
- Nenhuma abstração preventiva: sem CQRS, event bus, repositories ou DTOs sem consumidor concreto.
- Composer lock, audit, Pint, Larastan nível 8, Pest e guards permanecem gates obrigatórios.
- Arquivo, dependência ou infraestrutura sem consumidor comprovado deve ser removido.

---

### Task 1: Travar o contrato arquitetural final

**Files:**
- Modify: `README.md`
- Modify: `AGENTS.md`
- Modify: `docs/ARCHITECTURE.md`
- Create: `scripts/check-backend-scope.sh`
- Modify: `.github/workflows/ci.yml`
- Test: `tests/Feature/Architecture/BackendScopeTest.php`

**Interfaces:**
- Produces: gate `scripts/check-backend-scope.sh` executado pelo CI.

- [ ] **Step 1: escrever teste arquitetural RED**

O teste deve provar que:
- `stancl/tenancy` permanece presente;
- conexão `central` e `tenant_template` permanecem presentes;
- `config/tenancy.php` usa somente `DatabaseTenancyBootstrapper`;
- não existe segundo frontend em `resources/js`;
- não existem specs ativas afirmando que Laravel é apenas proxy do Supabase;
- as PRs superseded de Atendimentos não são tratadas como baseline normativa.

- [ ] **Step 2: adicionar guard shell equivalente**

O guard deve falhar se reaparecer `supabase-integration-only-remediation`, se `database/migrations/tenant` desaparecer ou se houver mais de uma estratégia de tenancy ativa.

- [ ] **Step 3: atualizar documentação canônica**

README/AGENTS/ARCHITECTURE devem declarar explicitamente:
- frontend existente permanece;
- Laravel é backend definitivo;
- Supabase atual é baseline/transição;
- novo laboratório = PostgreSQL dedicado;
- Super Admin = Laravel;
- nenhuma nova função/módulo fora de onda.

- [ ] **Step 4: rodar guards e testes**

```bash
bash scripts/check-backend-scope.sh
vendor/bin/pest tests/Feature/Architecture/BackendScopeTest.php
```

Expected: PASS.

---

### Task 2: Remover scaffold e infraestrutura sem consumidor

**Files:**
- Delete: `resources/views/welcome.blade.php`
- Delete: `resources/js/app.js`
- Delete if no consumer: `package.json`, `.npmrc`, `vite.config.js`, `resources/css/app.css`
- Modify: `routes/web.php`
- Review/remove if unused: Redis service/env/CI configuration
- Review/remove if unused: Composer dev dependencies

**Interfaces:**
- Produces: raiz web mínima e Super Admin Blade sem pipeline frontend próprio enquanto não existir consumidor real.

- [ ] **Step 1: testar que `/` não depende de welcome**

Adicionar feature test esperando redirect explícito da raiz para `/admin` quando o painel existir; até então, usar resposta/redirect mínimo e estável sem view scaffold.

- [ ] **Step 2: remover `welcome.blade.php` e JS vazio**

Remover somente depois de confirmar ausência de consumidores.

- [ ] **Step 3: auditar pipeline frontend do backend**

Verificar se o layout do Super Admin consome `@vite` ou assets compilados. Se não houver consumidor, remover `package.json`, `.npmrc`, `vite.config.js`, `resources/css/app.css` e comandos NPM de `composer setup`. Não manter Vite/Tailwind preventivamente.

- [ ] **Step 4: auditar Redis**

Buscar consumidores reais de cache, queue e Redis. Se não houver consumidor, remover Redis do Docker Compose, CI, `.env.example` e dependências/extensões específicas. Não remover suporte do framework por aparência; remover somente configuração operacional própria sem uso.

- [ ] **Step 5: auditar dependências**

Para cada dependência Composer sem chamada, script ou comando usado no projeto, remover somente com evidência de ausência de consumidor e repetir Composer Audit. Ferramentas reais de desenvolvimento/teste permanecem.

---

### Task 3: Conectar Laravel ao Supabase atual como origem de leitura

**Files:**
- Modify: `config/database.php`
- Modify: `.env.example`
- Create: `app/Platform/Supabase/SupabaseSource.php`
- Test: `tests/Feature/Platform/SupabaseSourceTest.php`

**Interfaces:**
- Produces: `SupabaseSource::connection(): \Illuminate\Database\ConnectionInterface`
- Produces: conexão nomeada `supabase_source` somente para leitura/migração/concordância.

- [ ] **Step 1: teste RED para conexão configurada**

Testar que `supabase_source` usa PostgreSQL, `sslmode=require`, credenciais separadas e nunca vira a conexão default.

- [ ] **Step 2: implementar conexão oficial**

Configurar `supabase_source` por URL/host/porta/database/user/password. Para VPS persistente usar conexão direta quando IPv6 estiver disponível ou Supavisor Session Mode (`5432`) quando IPv4-only. Nunca usar Transaction Pooler `6543` como datasource ORM principal.

- [ ] **Step 3: wrapper read-only mínimo**

`SupabaseSource` apenas expõe a conexão e helpers de leitura necessários à concordância. Não criar repository genérico.

- [ ] **Step 4: impedir escrita acidental**

A credencial operacional usada em produção deve ser de leitura quando o PostgreSQL permitir; testes do wrapper não devem expor métodos de mutação próprios.

- [ ] **Step 5: testar**

```bash
vendor/bin/pest tests/Feature/Platform/SupabaseSourceTest.php
```

Expected: PASS.

---

### Task 4: Super Admin Laravel — fundação mínima

**Files:**
- Create: `app/Http/Controllers/Admin/DashboardController.php`
- Create: `app/Http/Controllers/Admin/Tenants/IndexTenantController.php`
- Create: `app/Http/Controllers/Admin/Tenants/CreateTenantController.php`
- Create: `app/Http/Controllers/Admin/Tenants/StoreTenantController.php`
- Create: `app/Http/Requests/Admin/StoreTenantRequest.php`
- Create: `app/Http/Middleware/RequireSuperAdmin.php`
- Modify: `routes/web.php`
- Create: `resources/views/admin/layout.blade.php`
- Create: `resources/views/admin/dashboard.blade.php`
- Create: `resources/views/admin/tenants/index.blade.php`
- Create: `resources/views/admin/tenants/create.blade.php`
- Test: `tests/Feature/Admin/SuperAdminAccessTest.php`
- Test: `tests/Feature/Admin/TenantProvisioningTest.php`

**Interfaces:**
- Consumes: `TenantProvisioner::provision(Tenant $tenant): void`
- Produces: `/admin`, `/admin/laboratorios`, `/admin/laboratorios/novo`.

- [ ] **Step 1: teste RED de acesso**

Usuário comum recebe 403; usuário `super_admin` acessa o painel.

- [ ] **Step 2: implementar middleware mínimo**

Usar a identidade central existente e verificar apenas a role/permissão oficial já modelada; não introduzir segundo sistema de autenticação.

- [ ] **Step 3: dashboard mínimo**

Exibir somente contagem/status de laboratórios e links operacionais. Sem analytics preventivo.

- [ ] **Step 4: formulário de novo laboratório**

Campos mínimos: nome, slug/identificador, status inicial. O nome do banco é gerado server-side pelo modelo/provisionador e nunca aceito cru do navegador.

- [ ] **Step 5: provisionar transacionalmente**

Criar tenant central em `provisioning`, chamar `TenantProvisioner`, exibir sucesso somente se banco, migrations e smoke test concluírem. Falha fica auditada como `provisioning_failed`.

- [ ] **Step 6: testes**

Cobrir:
- acesso negado para não-super-admin;
- criação válida;
- nome de banco seguro;
- provisionamento idempotente;
- falha não marca tenant como ativo;
- tenant ativo aponta para banco físico correto.

---

### Task 5: Limpeza de legado e órfãos

**Files:** definidos pelo inventário de referências.

**Interfaces:**
- Produces: repositório sem arquivos/rotas/configs/testes/documentos sem consumidor.

- [ ] **Step 1: inventário de referências**

Classificar cada candidato como `runtime`, `test/dev-tool`, `historical-contract` ou `orphan`.

- [ ] **Step 2: remover órfãos**

Remover somente itens `orphan`, atualizando imports, docs e locks no mesmo commit.

- [ ] **Step 3: remover documentação superseded**

Manter somente documentos que ainda descrevem arquitetura corrente, operação real ou contrato histórico necessário. Specs/plans contraditórios ou sem função de auditoria devem sair.

- [ ] **Step 4: rodar varredura final**

Buscar referências a arquivos removidos, imports quebrados, `TODO`, `FIXME`, `legacy`, `deprecated`, scaffold padrão, paths inexistentes e dependências sem uso.

---

### Task 6: Gate final da fundação

- [ ] `composer validate --strict`
- [ ] `composer audit --locked --no-interaction`
- [ ] `vendor/bin/pint --test`
- [ ] `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- [ ] `vendor/bin/pest --parallel`
- [ ] todos os `scripts/check-*.sh`
- [ ] migrations central fresh em PostgreSQL 17
- [ ] provisionamento de tenant em PostgreSQL 17
- [ ] smoke test de conexão `supabase_source` sem escrita
- [ ] bootstrap real do `docker-compose.yml` com autenticação de `sislac_app`
- [ ] ausência de pipeline frontend sem consumidor no backend
- [ ] diff final sem arquivos órfãos conhecidos

Somente depois destes gates a próxima onda de domínio (Atendimentos) pode ser reaberta a partir da `main` limpa.
