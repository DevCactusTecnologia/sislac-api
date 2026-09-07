# Criação de Atendimentos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reimplementar no Laravel, com equivalência funcional comprovada, a criação transacional de `atendimentos + atendimento_exames + atendimento_pagamentos` atualmente executada pelo `sislacprivado`/Supabase.

**Architecture:** Rota HTTP protegida por Sanctum, tenancy e permissão `criar_atendimento`; `FormRequest` valida/normaliza; uma única action `CreateAtendimento` executa `DB::transaction()` e persiste os três conjuntos. Sem repository/DTO/CQRS/event bus/fila/cache preventivo.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, Sanctum, stancl/tenancy, Pest, Larastan nível 8, Pint.

**Spec:** `docs/superpowers/specs/2026-09-07-atendimentos-criacao-design.md`

## Global Constraints

- Fonte funcional normativa: `DevCactusTecnologia/sislacprivado@0760c6123f3062842eaff5f7304b6460c00c058d`.
- Supabase de referência: `eramenhnqcbyctyiqwlm`.
- Não redesenhar regras de negócio; migração é tecnológica.
- Não criar abstrações sem consumidor real.
- TDD obrigatório: RED pelo motivo esperado antes do GREEN.
- Dados de testes exclusivamente sintéticos.
- PostgreSQL real no CI; SQLite não prova comportamento tenant desta onda.
- Não avançar para atualização/cancelamento enquanto esta unidade não estiver integralmente verde.

---

### Task 1: Contrato físico versionado da criação

**Files:**
- Create: `docs/contracts/atendimentos-criacao.json`
- Create: `tests/Feature/Conformance/AtendimentosCriacaoContractTest.php`

**Interfaces:**
- Consumes: schema/funções/policies ativos no Supabase e SHA fixado do frontend.
- Produces: contrato JSON usado como baseline pelas tasks seguintes.

- [ ] **Step 1: Escrever teste RED do contrato**

```php
it('mantem o contrato versionado da criacao de atendimentos', function () {
    $path = base_path('docs/contracts/atendimentos-criacao.json');

    expect($path)->toBeFile();

    $contract = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($contract['baseline']['frontend_sha'])
        ->toBe('0760c6123f3062842eaff5f7304b6460c00c058d')
        ->and($contract['baseline']['supabase_project_ref'])
        ->toBe('eramenhnqcbyctyiqwlm')
        ->and($contract['tables'])->toHaveKeys([
            'atendimentos',
            'atendimento_exames',
            'atendimento_pagamentos',
        ]);
});
```

- [ ] **Step 2: Rodar o teste e confirmar RED**

Run: `vendor/bin/pest tests/Feature/Conformance/AtendimentosCriacaoContractTest.php`

Expected: FAIL porque `docs/contracts/atendimentos-criacao.json` ainda não existe.

- [ ] **Step 3: Criar o contrato com dados observados**

O JSON deve conter, no mínimo:

```json
{
  "version": 1,
  "baseline": {
    "frontend_repository": "DevCactusTecnologia/sislacprivado",
    "frontend_sha": "0760c6123f3062842eaff5f7304b6460c00c058d",
    "supabase_project_ref": "eramenhnqcbyctyiqwlm",
    "postgres_major": 17
  },
  "tables": {
    "atendimentos": {},
    "atendimento_exames": {},
    "atendimento_pagamentos": {}
  },
  "rpc": {
    "create_atendimento_tx": {}
  }
}
```

Preencher tipos/defaults/constraints/índices/policies somente com evidência do catálogo ativo e frontend fixado.

- [ ] **Step 4: Rodar o teste do contrato**

Run: `vendor/bin/pest tests/Feature/Conformance/AtendimentosCriacaoContractTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add docs/contracts/atendimentos-criacao.json tests/Feature/Conformance/AtendimentosCriacaoContractTest.php
git commit -m "test: fixa contrato da criação de atendimentos"
```

---

### Task 2: Schema tenant mínimo e fiel

**Files:**
- Create: `database/migrations/tenant/2026_09_07_000200_create_atendimentos_tables.php`
- Create: `app/Domain/Atendimentos/Models/Atendimento.php`
- Create: `app/Domain/Atendimentos/Models/AtendimentoExame.php`
- Create: `app/Domain/Atendimentos/Models/AtendimentoPagamento.php`
- Create: `tests/Feature/Atendimentos/AtendimentoSchemaTest.php`

**Interfaces:**
- Consumes: contrato da Task 1 e model tenant padrão já usado por Pacientes.
- Produces: três tabelas tenant e models simples para persistência.

- [ ] **Step 1: Escrever RED do schema**

O teste deve provisionar banco tenant PostgreSQL real, executar migrations tenant e verificar:

```php
expect(Schema::hasTable('atendimentos'))->toBeTrue()
    ->and(Schema::hasTable('atendimento_exames'))->toBeTrue()
    ->and(Schema::hasTable('atendimento_pagamentos'))->toBeTrue();
```

Também verificar defaults críticos com consulta a `information_schema.columns`:

```php
expect($defaults['status_atendimento'])->toContain('Pedido Realizado')
    ->and($defaults['status_pagamento'])->toContain('Pagamento pendente')
    ->and($defaults['origem_atendimento'])->toContain('INTERNO')
    ->and($defaults['prioridade_clinica'])->toContain('normal');
```

- [ ] **Step 2: Rodar RED**

Run: `vendor/bin/pest tests/Feature/Atendimentos/AtendimentoSchemaTest.php`
Expected: FAIL porque as tabelas não existem.

- [ ] **Step 3: Implementar migration mínima**

Criar `atendimentos` com tipos/defaults do contrato, incluindo:

```php
$table->bigIncrements('id');
$table->text('protocolo')->unique();
$table->timestampTz('data')->useCurrent();
$table->unsignedBigInteger('paciente_id')->nullable();
$table->text('paciente_nome');
$table->text('paciente_cpf')->default('');
$table->date('paciente_nascimento')->nullable();
$table->text('solicitante')->default('');
$table->integer('convenio_id')->default(0);
$table->text('convenio_nome')->default('Particular');
$table->text('unidade_id')->default('und-001');
$table->text('status_atendimento')->default('Pedido Realizado');
$table->text('status_pagamento')->default('Pagamento pendente');
$table->uuid('idempotency_key')->nullable()->unique();
$table->text('origem_atendimento')->default('INTERNO');
$table->boolean('jejum')->default(false);
$table->text('prioridade_clinica')->default('normal');
$table->text('guia_numero')->nullable();
$table->text('observacoes_assistente')->nullable();
$table->text('motivo_cancelamento')->nullable();
$table->timestampsTz();
```

Criar `atendimento_exames` e `atendimento_pagamentos` com FK `cascadeOnDelete()` para manter atomicidade estrutural e índices exigidos por FKs/uniques. Não copiar índices não consumidos pela criação.

- [ ] **Step 4: Models enxutos**

Cada model deve conter somente `$table`, `$guarded` e casts necessários. Sem repositories/relations extras nesta task.

- [ ] **Step 5: Rodar schema test + suíte tenant existente**

Run:

```bash
vendor/bin/pest tests/Feature/Atendimentos/AtendimentoSchemaTest.php tests/Feature/Provisioning
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/tenant app/Domain/Atendimentos/Models tests/Feature/Atendimentos/AtendimentoSchemaTest.php
git commit -m "feat: cria schema tenant de atendimentos"
```

---

### Task 3: Protocolo server-side e idempotência física

**Files:**
- Create: `app/Domain/Atendimentos/Support/AtendimentoProtocolo.php`
- Create: `tests/Feature/Atendimentos/AtendimentoProtocoloTest.php`
- Modify: migration da Task 2 apenas se o contrato exigir sequence/trigger específico.

**Interfaces:**
- Produces: `AtendimentoProtocolo::next(): string`.

- [ ] **Step 1: Capturar formato real do protocolo no Supabase**

Consultar apenas metadata/expressões/triggers e fixtures sintéticas, nunca dados de pacientes reais. Registrar o formato no contrato.

- [ ] **Step 2: RED para geração**

```php
it('gera protocolo no mesmo formato do sislacprivado', function () {
    $first = app(AtendimentoProtocolo::class)->next();
    $second = app(AtendimentoProtocolo::class)->next();

    expect($first)->toMatch('/<FORMATO_CAPTURADO>/')
        ->and($second)->not->toBe($first);
});
```

- [ ] **Step 3: RED de concorrência**

Abrir duas conexões PostgreSQL tenant independentes, solicitar protocolo simultaneamente e provar unicidade.

- [ ] **Step 4: Implementação mínima**

Usar sequence/`INSERT ... ON CONFLICT ... RETURNING` ou mecanismo equivalente comprovado pelo contrato. Não usar `MAX(id)+1`.

- [ ] **Step 5: Rodar testes**

Run: `vendor/bin/pest tests/Feature/Atendimentos/AtendimentoProtocoloTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Atendimentos/Support/AtendimentoProtocolo.php tests/Feature/Atendimentos/AtendimentoProtocoloTest.php docs/contracts/atendimentos-criacao.json
git commit -m "feat: gera protocolo de atendimento com concorrência segura"
```

---

### Task 4: Request HTTP e autorização

**Files:**
- Create: `app/Http/Requests/Atendimentos/StoreAtendimentoRequest.php`
- Create: `tests/Feature/Atendimentos/CreateAtendimentoApiTest.php`
- Modify: `routes/api.php`
- Modify: defaults de permissões centrais somente para incluir permissões já existentes no Supabase.

**Interfaces:**
- Produces: `POST /api/atendimentos` protegido por `auth:sanctum`, tenant e `criar_atendimento`.

- [ ] **Step 1: RED de autorização**

```php
it('nega criacao sem criar_atendimento', function () {
    $this->actingAs($user)
        ->withHeader('X-Tenant', $tenant->id)
        ->postJson('/api/atendimentos', syntheticPayload())
        ->assertForbidden();
});
```

- [ ] **Step 2: RED de validação/normalização**

Testar CPF `123.456.789-01` sendo entregue à action como `12345678901`, defaults e bloqueio de campos derivados enviados pelo cliente.

- [ ] **Step 3: Implementar FormRequest**

`prepareForValidation()` normaliza somente campos previstos; `rules()` valida UUIDs, números, arrays e formatos. Não resolve catálogo/convênio nesta unidade — o request recebe o payload já equivalente ao que a edge function recebe hoje.

- [ ] **Step 4: Registrar rota com middleware**

```php
Route::post('/atendimentos', StoreAtendimentoController::class)
    ->middleware(['auth:sanctum', EnsureTenantContext::class, 'tenant.permission:criar_atendimento']);
```

- [ ] **Step 5: Rodar testes HTTP**

Run: `vendor/bin/pest tests/Feature/Atendimentos/CreateAtendimentoApiTest.php`
Expected: ainda FAIL por action/controller ausentes, mas autorização e validação devem falhar pelo motivo esperado.

- [ ] **Step 6: Commit RED válido**

```bash
git add app/Http/Requests/Atendimentos routes/api.php tests/Feature/Atendimentos/CreateAtendimentoApiTest.php
git commit -m "test: define contrato HTTP da criação de atendimentos"
```

---

### Task 5: Criação transacional equivalente

**Files:**
- Create: `app/Domain/Atendimentos/Actions/CreateAtendimento.php`
- Create: `app/Http/Controllers/Atendimentos/StoreAtendimentoController.php`
- Modify: `tests/Feature/Atendimentos/CreateAtendimentoApiTest.php`

**Interfaces:**
- Consumes: `StoreAtendimentoRequest::validated()` e `AtendimentoProtocolo::next()`.
- Produces: array/JSON com `ok`, `duplicate?`, `atendimento_id`, `protocolo`, `guia_numero`.

- [ ] **Step 1: RED de criação vazia**

Testar persistência de um atendimento sem exames/pagamentos e defaults equivalentes.

- [ ] **Step 2: Implementar transação mínima**

```php
return DB::transaction(function () use ($payload): array {
    $existing = $this->findByIdempotencyKey($payload['atendimento']['idempotency_key'] ?? null);

    if ($existing !== null) {
        return $this->duplicateResponse($existing);
    }

    $atendimento = Atendimento::query()->create($this->atendimentoAttributes($payload['atendimento']));

    foreach ($payload['exames'] ?? [] as $exame) {
        $atendimento->exames()->create($this->exameAttributes($exame));
    }

    foreach ($payload['pagamentos'] ?? [] as $pagamento) {
        if (($pagamento['tipo'] ?? '') === '') {
            continue;
        }
        $atendimento->pagamentos()->create($this->pagamentoAttributes($pagamento));
    }

    return $this->successResponse($atendimento);
});
```

Se relations aumentarem o código sem clareza, usar `AtendimentoExame::query()->create()` e `AtendimentoPagamento::query()->create()` diretamente. Escolher a forma mais legível.

- [ ] **Step 3: RED/GREEN exames**

Testar dois exames, interno `pendente`, terceirizado `digitado`, `valor_original`, `amostra_seq`, `grupo_exame_id` e cobrança convênio/paciente.

- [ ] **Step 4: RED/GREEN pagamentos**

Testar um e múltiplos pagamentos, item sem `tipo` ignorado e data default.

- [ ] **Step 5: RED/GREEN idempotência simples**

Duas chamadas sequenciais com a mesma chave devem retornar o mesmo `atendimento_id` e total de linhas permanecer 1.

- [ ] **Step 6: Rodar testes da action/API**

Run: `vendor/bin/pest tests/Feature/Atendimentos/CreateAtendimentoApiTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Atendimentos/Actions app/Http/Controllers/Atendimentos tests/Feature/Atendimentos/CreateAtendimentoApiTest.php
git commit -m "feat: cria atendimento exames e pagamentos em transação única"
```

---

### Task 6: Concorrência e rollback integral

**Files:**
- Create: `tests/Feature/Atendimentos/CreateAtendimentoConcurrencyTest.php`
- Create: `tests/Feature/Atendimentos/CreateAtendimentoRollbackTest.php`
- Modify: `app/Domain/Atendimentos/Actions/CreateAtendimento.php` somente se os testes provarem lacuna.

**Interfaces:**
- Verifica garantias da Task 5 sob concorrência/falha.

- [ ] **Step 1: RED concorrente da idempotência**

Abrir duas conexões/processos contra o mesmo banco tenant e executar criação com a mesma UUID. Assert:

```php
expect(Atendimento::query()->where('idempotency_key', $key)->count())->toBe(1);
```

Ambas as respostas devem apontar para o mesmo atendimento; uma pode ser `duplicate: true`.

- [ ] **Step 2: Implementar tratamento de unique violation se necessário**

Capturar somente `23505` da constraint de `idempotency_key`, reconsultar o atendimento e retornar resposta idempotente. Não engolir outras violações.

- [ ] **Step 3: RED rollback por exame inválido**

Forçar constraint inválida em um exame sintético e provar:

```php
expect(Atendimento::query()->count())->toBe(0)
    ->and(AtendimentoExame::query()->count())->toBe(0)
    ->and(AtendimentoPagamento::query()->count())->toBe(0);
```

- [ ] **Step 4: RED rollback por pagamento inválido**

Mesmo padrão, falhando pagamento após atendimento/exame já terem sido inseridos dentro da transação.

- [ ] **Step 5: Rodar concorrência + rollback**

Run:

```bash
vendor/bin/pest tests/Feature/Atendimentos/CreateAtendimentoConcurrencyTest.php tests/Feature/Atendimentos/CreateAtendimentoRollbackTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Atendimentos/Actions/CreateAtendimento.php tests/Feature/Atendimentos/CreateAtendimentoConcurrencyTest.php tests/Feature/Atendimentos/CreateAtendimentoRollbackTest.php
git commit -m "test: prova idempotência e rollback da criação"
```

---

### Task 7: Concordância Supabase ↔ Laravel e fechamento da unidade

**Files:**
- Create: `tests/Feature/Conformance/AtendimentosCreateParityTest.php`
- Modify: `docs/contracts/atendimentos-criacao.json`
- Modify: `docs/ARCHITECTURE.md` apenas para registrar a unidade concluída, sem ampliar arquitetura.

**Interfaces:**
- Consumes: contrato e implementação completos.
- Produces: evidência de equivalência antes de permitir atualização/cancelamento.

- [ ] **Step 1: Fixtures sintéticas diferenciais**

Cobrir exatamente os 16 casos mínimos da spec. As fixtures devem comparar campos persistidos e resposta relevante, ignorando apenas timestamps/IDs naturalmente diferentes quando o contrato indicar equivalência sem identidade literal.

- [ ] **Step 2: Teste de isolamento tenant A/B**

Criar atendimento no A, alternar para B no mesmo processo, provar ausência; voltar para A e provar presença.

- [ ] **Step 3: Verificação física de queries críticas**

Usar `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` somente para consultas introduzidas nesta unidade que tenham filtro/lookup crítico, principalmente `idempotency_key` e FKs. Não criar benchmark artificial nem índices extras sem evidência.

- [ ] **Step 4: Rodar suíte completa e gates**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
composer audit --locked --no-interaction
bash scripts/check-no-central-in-tenant.sh
bash scripts/check-postgresql-only.sh
```

Expected: todos exit 0.

- [ ] **Step 5: Confirmar CI no mesmo SHA**

O run de PR deve concluir `success` nos jobs `Qualidade PHP` e `Guards de repositório`.

- [ ] **Step 6: Revisar simplicidade/YAGNI**

Checklist obrigatório:

```text
[ ] nenhuma classe existe só para encaminhar uma chamada
[ ] nenhuma interface tem uma única implementação sem fronteira real
[ ] nenhuma dependência nova foi adicionada
[ ] nenhuma fila/cache/event bus/CQRS foi introduzida
[ ] nenhuma regra de atualização/cancelamento entrou por antecipação
[ ] nenhum objeto Supabase foi copiado sem consumidor nesta unidade
```

- [ ] **Step 7: Commit final da unidade**

```bash
git add tests/Feature/Conformance/AtendimentosCreateParityTest.php docs/contracts/atendimentos-criacao.json docs/ARCHITECTURE.md
git commit -m "test: fecha concordância da criação de atendimentos"
```

## Definition of Done

A criação de Atendimentos só está concluída quando todas as Tasks 1–7 estiverem verdes no mesmo branch e o CI integral estiver `success`. Somente então escrever a spec/plano separados para atualização e cancelamento.