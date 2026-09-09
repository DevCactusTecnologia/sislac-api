# Fase 0 — Saneamento da Fundação Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** alinhar a fundação Laravel ao `sislacprivado`/Supabase atuais sem ampliar domínio clínico, removendo falsa conformidade, autenticação clínica duplicada e infraestrutura preventiva.

**Architecture:** o CI permanece offline e determinístico, validando apenas integridade do manifesto. A conformidade real vira um comando live explícito, read-only, inicialmente para Pacientes. A API clínica autentica Bearer tokens no Supabase Auth e usa o UUID validado apenas para localizar um `central.users` já provisionado; Super Admin continua autenticando exclusivamente pelo fluxo web Laravel.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, Supabase Auth/Postgres, Pest 4, Larastan nível 8, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-08-fase-0-saneamento-fundacao-design.md`

## Global Constraints

- `database-per-lab` permanece via `stancl/tenancy`; não criar segunda estratégia de tenancy.
- `X-Tenant` nunca autoriza por si só.
- PR #7 de Atendimentos permanece congelado e fora desta fase.
- Nenhuma escrita clínica ou DDL no Supabase.
- Nenhum segredo de Supabase no frontend ou repositório.
- Não adicionar Redis, Horizon, Reverb, event bus, CQRS, repositories genéricos ou DTOs preventivos.
- Toda mudança funcional usa TDD e deve ficar verde no mesmo SHA final.

---

### Task 1: Corrigir semântica do contrato offline e atualizar baseline

**Files:**
- Modify: `docs/contracts/supabase-baseline.json`
- Modify: `docs/contracts/pacientes.json`
- Modify: `scripts/check-supabase-contract.php`
- Modify: `tests/Contract/SupabaseBaselineTest.php`
- Modify: `tests/Contract/PacientesContractTest.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Produces: `scripts/check-supabase-contract.php` como verificador exclusivamente de integridade offline.
- Produces: manifesto versão 2 com `frontend.sha=57cc9be96703a41b207d530088369da1cc23cd94` e `migrated_contracts=["pacientes"]`.

- [ ] **Step 1: escrever testes RED para rejeitar a antiga promessa de conformidade live**

```php
it('descreve o gate como integridade offline', function () {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
    expect($workflow)->toContain('Integridade do manifesto Supabase')
        ->not->toContain('Contrato Supabase ↔ Laravel');
});

it('fixa a baseline na main atual do frontend', function () {
    $manifest = json_decode(file_get_contents(base_path('docs/contracts/supabase-baseline.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['version'])->toBe(2)
        ->and($manifest['frontend']['sha'])->toBe('57cc9be96703a41b207d530088369da1cc23cd94')
        ->and($manifest['migrated_contracts'])->toBe(['pacientes']);
});
```

- [ ] **Step 2: executar a suíte Contract e confirmar RED**

Run: `vendor/bin/pest tests/Contract --colors=always`
Expected: FAIL por baseline v1/nome antigo do job.

- [ ] **Step 3: reduzir o manifesto ao que foi realmente revalidado**

Usar estrutura determinística sem contagens amplas antigas:

```json
{
  "version": 2,
  "captured_at": "2026-09-08",
  "frontend": {
    "repository": "DevCactusTecnologia/sislacprivado",
    "sha": "57cc9be96703a41b207d530088369da1cc23cd94"
  },
  "supabase": {
    "project_ref": "eramenhnqcbyctyiqwlm",
    "postgres_major": 17,
    "runtime": "single-tenant"
  },
  "migrated_contracts": ["pacientes"],
  "sha256": "<hash canônico calculado pelo script>"
}
```

O script deve validar presença, tipos, SHA-256 canônico e nada além disso; não validar contagens congeladas de RPCs/Edge Functions/buckets.

- [ ] **Step 4: atualizar `pacientes.json` para a main atual**

Preservar o contrato de 24 colunas já conferido live, atualizar `captured_at`, `frontend_sha` e registrar `frontend_source_blob=e05d3c7e231320963d18c26f9c44ef43726f12cb`.

- [ ] **Step 5: renomear o step de CI**

```yaml
- name: Integridade do manifesto Supabase
  run: php scripts/check-supabase-contract.php
```

- [ ] **Step 6: executar Contract tests e confirmar GREEN**

Run: `vendor/bin/pest tests/Contract --colors=always`
Expected: PASS.

- [ ] **Step 7: commit**

```bash
git add docs/contracts scripts/check-supabase-contract.php tests/Contract .github/workflows/ci.yml
git commit -m "test: separa integridade de conformidade Supabase"
```

---

### Task 2: Adicionar conformidade live read-only de Pacientes

**Files:**
- Create: `app/Platform/Supabase/PacientesLiveContract.php`
- Create: `app/Console/Commands/CheckSupabaseLiveContract.php`
- Create: `tests/Unit/Platform/Supabase/PacientesLiveContractTest.php`
- Create: `tests/Feature/Platform/SupabaseLiveContractCommandTest.php`
- Modify: `app/Platform/Supabase/SupabaseSource.php`
- Modify: `docs/SEGURANCA.md`
- Modify: `docs/DEPLOY.md`

**Interfaces:**
- Consumes: `SupabaseSource::connection(): ConnectionInterface`.
- Produces: `PacientesLiveContract::check(ConnectionInterface $connection): list<string>`; lista vazia significa conformidade.
- Produces: comando `contract:supabase-live` com exit code 0/1.

- [ ] **Step 1: escrever testes RED do comparador e do comando**

```php
it('detecta coluna ausente no contrato live', function () {
    $checker = app(PacientesLiveContract::class);
    expect($checker->compareColumns(
        [['name' => 'id', 'type' => 'bigint', 'nullable' => false]],
        [['name' => 'id', 'type' => 'bigint', 'nullable' => false], ['name' => 'nome', 'type' => 'text', 'nullable' => false]],
    ))->toContain('coluna ausente: nome');
});
```

```php
it('falha de forma explícita sem configuração live', function () {
    config()->set('database.connections.supabase_source.host', null);
    $this->artisan('contract:supabase-live')->assertExitCode(2);
});
```

- [ ] **Step 2: executar testes e confirmar RED**

Run: `vendor/bin/pest tests/Unit/Platform/Supabase tests/Feature/Platform/SupabaseLiveContractCommandTest.php --colors=always`
Expected: FAIL porque classes/comando ainda não existem.

- [ ] **Step 3: implementar comparador mínimo**

Consultar apenas metadados necessários de `public.pacientes`:

```sql
select column_name, data_type, is_nullable, column_default
from information_schema.columns
where table_schema = 'public' and table_name = 'pacientes'
order by ordinal_position;
```

Também verificar `relrowsecurity`, índices relevantes e políticas `SELECT/INSERT/UPDATE` contendo as permissões `visualizar_pacientes`, `cadastrar_paciente`, `editar_paciente`; `DELETE` deve permanecer admin-only.

- [ ] **Step 4: tornar read-only verificável**

`SupabaseSource::connection()` deve continuar executando `set default_transaction_read_only = on` e `PacientesLiveContract` deve abortar se `show default_transaction_read_only` não retornar `on`.

- [ ] **Step 5: implementar comando sem credencial embutida**

```php
protected $signature = 'contract:supabase-live';
```

Sem host/usuário configurados: exit 2 com mensagem de configuração. Divergência: exit 1. Conforme: exit 0.

- [ ] **Step 6: executar testes e confirmar GREEN**

Run: `vendor/bin/pest tests/Unit/Platform/Supabase tests/Feature/Platform/SupabaseLiveContractCommandTest.php tests/Feature/Platform/SupabaseSourceTest.php --colors=always`
Expected: PASS.

- [ ] **Step 7: commit**

```bash
git add app/Platform/Supabase app/Console tests/Unit/Platform/Supabase tests/Feature/Platform docs/SEGURANCA.md docs/DEPLOY.md
git commit -m "feat: adiciona conformidade live read-only de pacientes"
```

---

### Task 3: Substituir autenticação clínica Laravel por ponte Supabase Auth

**Files:**
- Create: `app/Platform/Supabase/SupabaseAuthUser.php`
- Create: `app/Platform/Supabase/SupabaseAuth.php`
- Create: `app/Http/Middleware/AuthenticateSupabaseUser.php`
- Create: `tests/Feature/Auth/SupabaseBearerAuthenticationTest.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Delete: `app/Http/Controllers/Auth/LoginController.php`
- Delete: `app/Http/Controllers/Auth/LogoutController.php`
- Delete: `app/Http/Controllers/Auth/SessionController.php`
- Update/remove tests que existirem apenas para essas três rotas API; preservar testes do Super Admin web.

**Interfaces:**
- Produces: `SupabaseAuth::user(string $accessToken): ?SupabaseAuthUser`.
- `SupabaseAuthUser` contém apenas `id` UUID e `email` nullable necessários à correlação.
- Middleware define `$request->setUserResolver(fn () => $centralUser)` e nunca cria usuário/membership.

- [ ] **Step 1: escrever testes RED para token válido, inválido e usuário não provisionado**

```php
Http::fake([
    'https://example.supabase.co/auth/v1/user' => Http::response(['id' => $user->id, 'email' => $user->email], 200),
]);

$this->withToken('valid-token')
    ->getJson('/api/pacientes')
    ->assertOk();
```

```php
Http::fake(['*' => Http::response(['message' => 'invalid'], 401)]);
$this->withToken('invalid')->getJson('/api/pacientes')->assertUnauthorized();
```

Token válido com UUID ausente em `central.users` deve retornar 403 e não inserir nada.

- [ ] **Step 2: executar testes e confirmar RED**

Run: `vendor/bin/pest tests/Feature/Auth/SupabaseBearerAuthenticationTest.php --colors=always`
Expected: FAIL porque a API ainda usa `auth:sanctum`.

- [ ] **Step 3: configurar apenas URL e publishable key**

```php
// config/services.php
'supabase' => [
    'url' => env('SUPABASE_URL'),
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY'),
],
```

`.env.example` deve documentar as duas variáveis sem valor real.

- [ ] **Step 4: implementar cliente Auth mínimo com Laravel HTTP Client**

```php
$response = Http::acceptJson()
    ->withHeaders(['apikey' => $publishableKey])
    ->withToken($accessToken)
    ->timeout(5)
    ->get(rtrim($url, '/').'/auth/v1/user');
```

Somente HTTP 200 com UUID válido produz identidade. Não confiar em `user_metadata` para autorização.

- [ ] **Step 5: implementar middleware**

Regras:
- ausência/malformed Bearer: 401;
- Auth Supabase rejeita token: 401;
- falha de rede/5xx: 503 sem expor token;
- token válido mas `central.users.id` inexistente: 403;
- usuário existente: definir resolver e continuar para `tenant`/`tenant.permission`.

- [ ] **Step 6: trocar apenas as rotas clínicas**

```php
Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:visualizar_pacientes'])
```

Remover `/api/auth/login`, `/api/auth/logout`, `/api/auth/session`. O fluxo `/admin/login` web permanece intacto.

- [ ] **Step 7: executar Auth, Tenant e Pacientes tests**

Run: `vendor/bin/pest tests/Feature/Auth tests/Feature/Tenancy tests/Feature/Security/PacienteAuthorizationTest.php tests/Feature/Domain/Pacientes --colors=always`
Expected: PASS.

- [ ] **Step 8: commit**

```bash
git add app/Platform/Supabase app/Http/Middleware bootstrap/app.php routes/api.php config/services.php .env.example tests/Feature/Auth tests/Feature/Tenancy tests/Feature/Security tests/Feature/Domain/Pacientes
git commit -m "feat: usa Supabase Auth na API clínica"
```

---

### Task 4: Remover fila/jobs sem consumidor e impedir retorno acidental

**Files:**
- Delete: `database/migrations/0001_01_01_000002_create_jobs_table.php`
- Modify: `.env.example`
- Modify: `scripts/check-backend-scope.sh`
- Modify: `tests/Feature/Architecture/BackendScopeTest.php`

**Interfaces:**
- Default runtime: `QUEUE_CONNECTION=sync`.
- Guard deve falhar se migration de jobs ou `QUEUE_CONNECTION=database` reaparecer sem consumidor aprovado.

- [ ] **Step 1: escrever teste RED do contrato enxuto**

```php
expect(base_path('database/migrations/0001_01_01_000002_create_jobs_table.php'))->not->toBeFile()
    ->and(file_get_contents(base_path('.env.example')))->toContain('QUEUE_CONNECTION=sync');
```

- [ ] **Step 2: executar e confirmar RED**

Run: `vendor/bin/pest tests/Feature/Architecture/BackendScopeTest.php --colors=always`
Expected: FAIL.

- [ ] **Step 3: remover migration e mudar fila default para sync**

Nenhum worker, tabela de jobs, failed_jobs ou batches deve permanecer na fundação.

- [ ] **Step 4: adicionar guard anti-regressão**

O `check-backend-scope.sh` deve rejeitar `database/migrations/*jobs*` e `QUEUE_CONNECTION=database` enquanto `app/` não contiver consumidor aprovado.

- [ ] **Step 5: executar teste/guard e confirmar GREEN**

Run: `vendor/bin/pest tests/Feature/Architecture/BackendScopeTest.php --colors=always && bash scripts/check-backend-scope.sh`
Expected: PASS.

- [ ] **Step 6: commit**

```bash
git add -A database/migrations .env.example scripts/check-backend-scope.sh tests/Feature/Architecture/BackendScopeTest.php
git commit -m "chore: remove fila sem consumidor"
```

---

### Task 5: Tratar `plans/subscriptions` sem apagar estado desconhecido

**Files:**
- Modify: `tests/Feature/Platform/CentralSchemaTest.php`
- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/DEPLOY.md`
- Optional Create: `scripts/audit-unused-platform-tables.php`

**Interfaces:**
- Esta task não executa `DROP TABLE` sem evidência do banco central persistente.
- Produces: auditoria read-only que informa contagem de `plans` e `subscriptions` quando uma conexão central real estiver disponível.

- [ ] **Step 1: escrever teste que deixa de tratar plans/subscriptions como fundação obrigatória**

```php
$required = ['users', 'tenants', 'memberships', 'provisioning_runs', 'platform_audit'];
foreach ($required as $table) {
    expect($schema->hasTable($table))->toBeTrue();
}
```

O teste não exige mais `plans`/`subscriptions`; também não os apaga.

- [ ] **Step 2: criar auditoria read-only operacional**

```php
$plans = DB::connection('central')->table('plans')->count();
$subscriptions = DB::connection('central')->table('subscriptions')->count();
```

Se as tabelas não existirem, reportar `absent`; se existirem, reportar apenas contagens. Não alterar dados.

- [ ] **Step 3: documentar o bloqueio de remoção física**

Registrar que a remoção só pode ocorrer quando o banco central real confirmar ausência de estado útil. Não criar migration de DROP nesta Fase 0 sem essa evidência.

- [ ] **Step 4: executar Platform tests**

Run: `vendor/bin/pest tests/Feature/Platform --colors=always`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
git add tests/Feature/Platform docs/ARCHITECTURE.md docs/DEPLOY.md scripts
git commit -m "chore: desobriga plataforma preventiva sem apagar estado"
```

---

### Task 6: Validação final, prova live e governança

**Files:**
- Modify: `README.md`
- Modify: `docs/SEGURANCA.md`
- Modify: PR #8 metadata only after verification.

**Interfaces:**
- `contract:supabase-live` deve rodar apenas em ambiente confiável com credencial PostgreSQL read-only.
- Repositório deve ficar privado via administração GitHub; se a conexão atual não oferecer essa mutação, registrar como bloqueio operacional explícito sem fingir conclusão.

- [ ] **Step 1: executar gates completos no mesmo SHA**

```bash
composer validate --strict --no-interaction
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel --colors=always
bash scripts/check-no-central-in-tenant.sh
bash scripts/check-backend-scope.sh
bash scripts/check-postgres-bootstrap.sh
bash scripts/check-database-contract.sh
bash scripts/check-file-size.sh
```

Expected: todos PASS.

- [ ] **Step 2: executar conformidade live contra `eramenhnqcbyctyiqwlm`**

Run: `php artisan contract:supabase-live`
Expected: exit 0, `Pacientes: conforme`, sem qualquer INSERT/UPDATE/DELETE/DDL.

- [ ] **Step 3: revisar diff contra main**

Run: `git diff --check main...HEAD` e revisar todos os arquivos alterados.
Expected: nenhum whitespace error, nenhuma mudança em Atendimentos/PR #7, nenhuma credencial real.

- [ ] **Step 4: tornar repositório privado**

Executar pela administração do GitHub se a ferramenta conectada suportar. Confirmar `visibility=private` depois da mutação. Se não suportar, deixar este item como único bloqueio operacional documentado.

- [ ] **Step 5: atualizar PR #8**

Marcar ready somente se todos os gates executáveis estiverem verdes e descrever qualquer bloqueio operacional remanescente de forma explícita.
