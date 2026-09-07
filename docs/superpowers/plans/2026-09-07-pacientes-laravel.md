# Pacientes Laravel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o primeiro módulo de domínio no Laravel com paridade funcional do fluxo de Pacientes consumido pelo `sislacprivado` no SHA `0760c6123f3062842eaff5f7304b6460c00c058d`.

**Architecture:** `app/Domain/Pacientes` contém apenas persistência e regras do banco tenant; autorização continua no plano HTTP/Platform, sem importar `App\Platform` dentro do domínio. O contrato HTTP substitui gradualmente os acessos diretos do frontend a `public.pacientes`, preservando paginação por cursor, busca, status, CPF único, `friendly_id` imutável, LGPD e permissões.

**Tech Stack:** Laravel 13.30.1, PHP 8.4, PostgreSQL 17, Sanctum 4.3.3, stancl/tenancy 3.10.1, Pest 4, Larastan 3.11.

**Spec:** `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`

## Global Constraints

- Documentação oficial Laravel 13, Supabase e PostgreSQL é normativa.
- PostgreSQL é o único banco de produção suportado.
- Um banco PostgreSQL por laboratório; nenhuma autorização depende apenas de `X-Tenant`.
- `app/Platform` não importa `App\Domain`; `app/Domain` não importa `App\Platform`.
- Nunca chamar `DB::connection('tenant')` fora do middleware de tenancy; modelos de domínio usam a conexão default já trocada pelo bootstrapper.
- Dados de testes são exclusivamente sintéticos.
- TDD obrigatório: RED → GREEN → REFACTOR.
- Nenhum delete de paciente nesta onda: não existe consumidor executável no frontend baseline; desativação por `status` é o fluxo atual.
- O frontend baseline permanece fixado em `0760c6123f3062842eaff5f7304b6460c00c058d` durante esta onda.

---

### Task 1: Contrato tenant da tabela `pacientes`

**Files:**
- Create: `database/migrations/tenant/2026_09_07_000100_create_pacientes_table.php`
- Create: `app/Domain/Pacientes/Models/Paciente.php`
- Test: `tests/Feature/Domain/Pacientes/PacienteSchemaTest.php`

**Interfaces:**
- Produces: tabela `pacientes` no banco tenant e `App\Domain\Pacientes\Models\Paciente`.

- [ ] **Step 1: RED — schema mínimo e invariantes**

Criar teste que inicializa um tenant real e prova: colunas do contrato Supabase; CPF opcional e único quando não vazio; `status` limitado a `Ativo|Inativo`; `sexo` limitado a `M|F`; `friendly_id` único quando preenchido; timestamps com timezone.

```php
expect(Schema::hasColumns('pacientes', [
    'id','nome','nome_social','cpf','data_nascimento','sexo','telefone','celular','email',
    'cep','estado','cidade','bairro','endereco','numero','complemento','status',
    'guardian_name','guardian_cpf','consentimento_lgpd','consentimento_em','friendly_id',
    'created_at','updated_at',
]))->toBeTrue();
```

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteSchemaTest.php`
Expected: FAIL porque a migration/model ainda não existem.

- [ ] **Step 3: implementar migration/model mínimo**

Migration usa `bigIncrements('id')`, `text`, `date`, `timestampTz`, checks PostgreSQL para sexo/status e índices compatíveis com os acessos reais (`cpf`, `status`, `(updated_at,id)`). CPF recebe índice único parcial `WHERE cpf IS NOT NULL AND cpf <> ''`; `friendly_id` recebe índice único parcial `WHERE friendly_id <> ''`.

Model:
```php
final class Paciente extends Model
{
    protected $table = 'pacientes';
    protected $guarded = ['id', 'friendly_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date:Y-m-d',
            'consentimento_lgpd' => 'boolean',
            'consentimento_em' => 'immutable_datetime',
        ];
    }
}
```

- [ ] **Step 4: GREEN + regressão**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteSchemaTest.php && vendor/bin/pest --parallel`
Expected: PASS.

- [ ] **Step 5: commit**

`feat: cria schema tenant de pacientes`

### Task 2: `friendly_id` concorrente e imutável

**Files:**
- Modify: `database/migrations/tenant/2026_09_07_000100_create_pacientes_table.php`
- Create: `app/Domain/Pacientes/Services/PacienteFriendlyId.php`
- Test: `tests/Feature/Domain/Pacientes/PacienteFriendlyIdTest.php`

**Interfaces:**
- Produces: `PacienteFriendlyId::next(): string` no formato `PAC-000001`.

- [ ] **Step 1: RED**

Testar primeiro ID, incremento, concorrência transacional e impossibilidade de alterar `friendly_id` depois de criado.

```php
expect($generator->next())->toBe('PAC-000001');
expect($generator->next())->toBe('PAC-000002');
```

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteFriendlyIdTest.php`
Expected: FAIL.

- [ ] **Step 3: implementar com contador PostgreSQL**

Criar `friendly_id_counters(scope text primary key, next_value bigint)` no tenant. `next()` usa uma única instrução `INSERT ... ON CONFLICT ... DO UPDATE ... RETURNING next_value - 1`, prefixo fixo `PAC-`, sem sequence global central. A aplicação nunca aceita `friendly_id` de payload externo.

- [ ] **Step 4: GREEN**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteFriendlyIdTest.php`
Expected: PASS.

- [ ] **Step 5: commit**

`feat: gera friendly id imutável de pacientes`

### Task 3: autorização equivalente ao Supabase

**Files:**
- Modify: `database/migrations/central/2026_09_06_000100_create_platform_tables.php` via nova migration central
- Create: `database/migrations/central/2026_09_07_000200_add_permission_overrides_to_memberships.php`
- Create: `app/Platform/Authorization/TenantPermission.php`
- Create: `app/Platform/Authorization/MembershipAuthorizer.php`
- Create: `app/Http/Middleware/RequireTenantPermission.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Security/PacienteAuthorizationTest.php`

**Interfaces:**
- Produces: `MembershipAuthorizer::allows(string $userId, string $tenantId, TenantPermission $permission): bool`.

- [ ] **Step 1: RED — matriz real de permissões**

Perfis default: `admin` permite tudo; `analista` permite `visualizar_pacientes`; `recepcionista` permite visualizar/cadastrar/editar; `financeiro` permite visualizar. `permissions_revoked` vence default/admin; `permissions_extra` concede quando não revogada.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Security/PacienteAuthorizationTest.php`
Expected: FAIL.

- [ ] **Step 3: implementar autorização central**

Adicionar em `memberships` dois arrays JSONB não nulos com default `[]`: `permissions_extra`, `permissions_revoked`. `TenantPermission` enumera apenas permissões já consumidas nesta onda. Middleware consulta a membership ativa do usuário/tenant atual e responde 403 sem tocar banco clínico quando não autorizado.

- [ ] **Step 4: GREEN**

Run: `vendor/bin/pest tests/Feature/Security/PacienteAuthorizationTest.php && vendor/bin/pest --parallel`
Expected: PASS.

- [ ] **Step 5: commit**

`feat: aplica permissões do módulo de pacientes`

### Task 4: API de leitura e paginação por cursor

**Files:**
- Create: `app/Http/Controllers/Pacientes/ListPacientesController.php`
- Create: `app/Http/Controllers/Pacientes/ShowPacienteController.php`
- Create: `app/Http/Requests/Pacientes/ListPacientesRequest.php`
- Create: `app/Http/Resources/Pacientes/PacienteResource.php`
- Create: `app/Domain/Pacientes/Queries/ListPacientes.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Pacientes/PacienteReadApiTest.php`

**Interfaces:**
- `GET /api/pacientes?status=Todos|Ativo|Inativo&q=&cursor=`
- `GET /api/pacientes/{id}`

- [ ] **Step 1: RED — contrato de leitura**

Testar: 50 itens por página; ordenação `updated_at DESC, id DESC`; cursor opaco estável; busca com >=3 dígitos usa CPF parcial, caso contrário nome case-insensitive; counts ignoram filtro de status e respeitam busca; show inexistente 404; serialização mantém nomes esperados pelo adaptador frontend.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteReadApiTest.php`
Expected: FAIL.

- [ ] **Step 3: implementar query sem N+1**

`ListPacientes` usa query Eloquent única para página + duas contagens (`todos`, `ativos`) e cursor base64url de JSON `{updated_at,id}` validado pelo Form Request. Nenhum offset pagination.

- [ ] **Step 4: GREEN + EXPLAIN**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteReadApiTest.php`
Expected: PASS. Registrar em teste de integração que o planner pode usar o índice `(updated_at,id)` na paginação com fixture suficiente.

- [ ] **Step 5: commit**

`feat: expõe leitura paginada de pacientes`

### Task 5: API de criação e edição

**Files:**
- Create: `app/Http/Controllers/Pacientes/CreatePacienteController.php`
- Create: `app/Http/Controllers/Pacientes/UpdatePacienteController.php`
- Create: `app/Http/Requests/Pacientes/StorePacienteRequest.php`
- Create: `app/Http/Requests/Pacientes/UpdatePacienteRequest.php`
- Create: `app/Domain/Pacientes/Actions/CreatePaciente.php`
- Create: `app/Domain/Pacientes/Actions/UpdatePaciente.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Pacientes/PacienteWriteApiTest.php`

**Interfaces:**
- `POST /api/pacientes`
- `PATCH /api/pacientes/{id}`

- [ ] **Step 1: RED — normalização e erros**

Testar: CPF armazenado só com dígitos; CPF duplicado retorna 422 com mensagem estável; data `dd/MM/yyyy` vira `Y-m-d`; sexo longo vira `M|F`; campos opcionais vazios são normalizados; criação gera friendly ID; patch não altera campos ausentes; payload não consegue alterar id/friendly_id/timestamps.

- [ ] **Step 2: verificar RED**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteWriteApiTest.php`
Expected: FAIL.

- [ ] **Step 3: implementar actions transacionais**

`CreatePaciente` usa `DB::transaction()` na conexão default tenant, gera friendly ID dentro da transação e persiste. `UpdatePaciente` faz `lockForUpdate()` quando necessário para serializar alteração concorrente do mesmo paciente. Violação de CPF único é traduzida para ValidationException, sem vazar SQLSTATE bruto.

- [ ] **Step 4: GREEN**

Run: `vendor/bin/pest tests/Feature/Domain/Pacientes/PacienteWriteApiTest.php && vendor/bin/pest --parallel`
Expected: PASS.

- [ ] **Step 5: commit**

`feat: cria e atualiza pacientes pela API`

### Task 6: concordância, segurança e gates finais

**Files:**
- Create: `docs/contracts/pacientes.json`
- Create: `tests/Contract/PacientesContractTest.php`
- Create: `tests/Feature/Performance/PacienteQueryPerformanceTest.php`
- Modify: `docs/ARCHITECTURE.md`
- Modify: `AGENTS.md`
- Modify: `.github/workflows/ci.yml` somente se um gate adicional for necessário

**Produces:** evidência versionada de paridade do módulo contra o frontend/Supabase baseline.

- [ ] **Step 1: versionar contrato**

Registrar SHA frontend, colunas físicas Supabase, índices relevantes, permissões, serialização, filtros, cursor, limite 50, mensagens de conflito e regra de friendly ID.

- [ ] **Step 2: testes diferenciais sintéticos**

Fixtures sintéticas devem provar equivalência de transformação: CPF, datas, sexo, status, guardian, LGPD, paginação e busca. Nenhum dado de produção.

- [ ] **Step 3: gates completos**

Run:
```bash
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
bash scripts/check-no-central-in-tenant.sh
bash scripts/check-database-contract.sh
php scripts/check-supabase-contract.php
```
Expected: todos PASS.

- [ ] **Step 4: commit**

`test: fecha concordância do módulo de pacientes`
