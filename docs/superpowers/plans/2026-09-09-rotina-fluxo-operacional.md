# Rotina / Fluxo Operacional Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar no Laravel/PostgreSQL tenant a configuração e a máquina de estados da Rotina (Coleta e Análise), preservando os três modos atuais sem antecipar Resultados, Estoque ou integrações.

**Architecture:** Rotina estende o agregado `Atendimentos` e reutiliza `atendimento_exames`. Laravel recebe intenções de negócio; PostgreSQL tenant permanece autoridade final das transições e short-circuits. A configuração é singleton por tenant e a troca de modo normaliza, na mesma transação, exames internos que ficariam presos em etapas desativadas.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, stancl/tenancy, Pest, Larastan nível 8.

**Spec:** `docs/superpowers/specs/2026-09-09-rotina-fluxo-operacional-design.md`

## Global Constraints

- Base desta onda: `feat/atendimentos-laravel`, SHA `900a0756b5ccabdcda2aba7608cb93d31f63e9e3`.
- Frontend baseline: `DevCactusTecnologia/sislacprivado`, SHA `57cc9be96703a41b207d530088369da1cc23cd94`.
- Nenhuma escrita ou DDL no Supabase.
- Nenhuma alteração no frontend nesta onda.
- Autenticação clínica: Bearer Supabase → `supabase.auth` → tenant → autorização Laravel.
- Não criar CRUD genérico de status, endpoint batch, Redis, fila persistente, WebSocket, CQRS, event bus, repository genérico ou abstração preventiva.
- Reutilizar `atendimento_exames`, `atendimento_audit` e a recomputação existente de `status_atendimento`.
- Exames `TERCEIRIZADO` não participam da análise interna e não são normalizados por mudança de modo.
- Timestamps oficiais de coleta/análise são server-side.
- Cancelamento exige motivo não vazio.
- PostgreSQL 17 é o banco de teste/integridade da onda.

---

## File Structure

### Criar

- `database/migrations/tenant/2026_09_09_000400_add_rotina_fluxo.php` — `lab_config` singleton, função de modo, short-circuit e validação de status.
- `app/Domain/Atendimentos/Actions/UpdateRotinaConfig.php` — troca transacional de modo e normalização de exames em andamento.
- `app/Domain/Atendimentos/Actions/TransitionAtendimentoExame.php` — intenção operacional com lock da ocorrência.
- `app/Domain/Atendimentos/Queries/ListRotinaColeta.php` — fila de coleta derivada.
- `app/Domain/Atendimentos/Queries/ListRotinaAnalise.php` — fila de análise interna derivada.
- `app/Http/Controllers/Rotina/ShowRotinaConfigController.php`
- `app/Http/Controllers/Rotina/UpdateRotinaConfigController.php`
- `app/Http/Controllers/Rotina/ListRotinaColetaController.php`
- `app/Http/Controllers/Rotina/ListRotinaAnaliseController.php`
- `app/Http/Controllers/Rotina/TransitionRotinaExameController.php`
- `app/Http/Requests/Rotina/UpdateRotinaConfigRequest.php`
- `app/Http/Requests/Rotina/TransitionRotinaExameRequest.php`
- `tests/Feature/Domain/Rotina/RotinaSchemaTest.php`
- `tests/Feature/Domain/Rotina/RotinaConfigApiTest.php`
- `tests/Feature/Domain/Rotina/RotinaTransitionApiTest.php`
- `tests/Feature/Domain/Rotina/RotinaQueuesApiTest.php`
- `tests/Feature/Concurrency/RotinaConcurrencyTest.php`

### Modificar

- `app/Platform/Authorization/TenantPermission.php` — adicionar permissões já existentes `registrar_coleta`, `analisar_amostra`, `configuracoes_sistema`.
- `routes/api.php` — expor somente as cinco rotas canônicas da Rotina.
- `database/migrations/tenant/2026_09_08_000200_create_atendimentos_tables.php` — somente se o schema base ainda não possuir coluna necessária já existente no baseline; preferir migration `000400` para qualquer adição nova.
- `database/migrations/tenant/2026_09_08_000300_add_atendimento_invariants.php` — não duplicar recomputação; alterar apenas se uma invariável existente impedir a máquina aprovada e houver teste que prove a necessidade.
- `tests/Feature/Provisioning/*` — novo tenant deve executar `000400` e iniciar em `completo`.
- `docs/ARCHITECTURE.md` e `README.md` — registrar o backend Rotina apenas após comportamento e CI estarem verdes.

---

### Task 1: Configuração singleton e vocabulário de autorização

**Files:**
- Create: `database/migrations/tenant/2026_09_09_000400_add_rotina_fluxo.php`
- Modify: `app/Platform/Authorization/TenantPermission.php`
- Test: `tests/Feature/Domain/Rotina/RotinaSchemaTest.php`
- Test/Modify: `tests/Feature/Provisioning/*`

**Interfaces:**
- Produces: `lab_config.rotina_fluxo_modo`, singleton tenant, default `completo`.
- Produces: `TenantPermission::RegisterCollection`, `AnalyzeSample`, `SystemSettings` com valores existentes do produto.
- Consumed by Tasks 2–6.

- [ ] **Step 1: Write the failing schema tests**

Testar explicitamente:

```php
expect(DB::connection('tenant')->table('lab_config')->count())->toBe(1);
expect(DB::connection('tenant')->table('lab_config')->value('rotina_fluxo_modo'))->toBe('completo');
```

E executar SQL que tente inserir segundo registro e valor `invalido`, esperando constraint violation. O teste também deve verificar os valores literais do enum:

```php
expect(TenantPermission::RegisterCollection->value)->toBe('registrar_coleta');
expect(TenantPermission::AnalyzeSample->value)->toBe('analisar_amostra');
expect(TenantPermission::SystemSettings->value)->toBe('configuracoes_sistema');
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Domain/Rotina/RotinaSchemaTest.php`

Expected: FAIL porque `lab_config`/enum ainda não existem.

- [ ] **Step 3: Implement minimal schema**

A migration `000400` deve criar `lab_config` com uma chave singleton fixa e CHECK para:

```text
completo | coleta_resultado | apenas_resultado
```

Inserir exatamente um registro default `completo`. Não portar outras colunas do `lab_config` Supabase.

- [ ] **Step 4: Run GREEN + provisioning subset**

Run:

```bash
php artisan test tests/Feature/Domain/Rotina/RotinaSchemaTest.php
php artisan test tests/Feature/Provisioning
```

Expected: PASS.

- [ ] **Step 5: Commit**

`feat: adiciona configuração mínima da rotina`

---

### Task 2: Máquina de estados PostgreSQL e short-circuits

**Files:**
- Modify: `database/migrations/tenant/2026_09_09_000400_add_rotina_fluxo.php`
- Test: `tests/Feature/Domain/Rotina/RotinaSchemaTest.php`
- Test: `tests/Feature/Domain/Atendimentos/*` se necessário para garantir criação de exame interno/terceirizado.

**Interfaces:**
- Produces: função tenant que lê modo atual.
- Produces: proteção DB de transições e short-circuit de criação/update.
- Consumed by Tasks 3–6.

- [ ] **Step 1: Write RED tests for the three modes**

Criar ocorrências internas e provar no banco:

```text
completo: novo interno => pendente
coleta_resultado: novo interno => pendente
apenas_resultado: novo interno => analisado + data_coleta + data_analise + coletor/analista __SEM_REGISTRO__
```

Testar também:

```text
completo: pendente -> analisado direto => constraint failure
completo: pendente -> coletado => permitido
completo: coletado -> em_bancada => permitido
completo: em_bancada -> analisado => permitido
coleta_resultado: atualização de coleta resulta efetivamente analisado; em_bancada não pode persistir
apenas_resultado: pendente/coletado/em_bancada não podem ser materializados por fluxo normal
finalizado: não regride
TERCEIRIZADO digitado: permanece fora dos short-circuits internos
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Domain/Rotina/RotinaSchemaTest.php`

Expected: FAIL nas regras ainda inexistentes.

- [ ] **Step 3: Implement DB invariants**

Na migration, criar apenas funções/triggers da Rotina. A leitura do modo deve retornar `completo` se o singleton estiver ausente por condição anormal de bootstrap. A validação deve ignorar updates sem mudança de status, permitir cancelamento e impedir regressão de `finalizado` nesta onda.

Não copiar triggers de estoque, resultado, snapshot, POP, lab apoio ou integração.

- [ ] **Step 4: Run GREEN and Atendimentos regression subset**

Run:

```bash
php artisan test tests/Feature/Domain/Rotina/RotinaSchemaTest.php
php artisan test tests/Feature/Domain/Atendimentos
```

Expected: PASS.

- [ ] **Step 5: Commit**

`feat: protege máquina de estados da rotina`

---

### Task 3: API de configuração e normalização transacional na troca de modo

**Files:**
- Create: `app/Domain/Atendimentos/Actions/UpdateRotinaConfig.php`
- Create: `app/Http/Controllers/Rotina/ShowRotinaConfigController.php`
- Create: `app/Http/Controllers/Rotina/UpdateRotinaConfigController.php`
- Create: `app/Http/Requests/Rotina/UpdateRotinaConfigRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Rotina/RotinaConfigApiTest.php`

**Interfaces:**
- Produces: `UpdateRotinaConfig::handle(string $mode): array` ou retorno equivalente tipado pelo padrão existente.
- Produces: GET/PATCH `/api/rotina/config`.
- Uses: DB authority from Task 2.

- [ ] **Step 1: Write RED API tests**

Cobrir Bearer Supabase fake, tenant e permissões. Casos mínimos:

```text
GET autenticado => 200 + completo
PATCH sem configuracoes_sistema => 403
PATCH modo inválido => 422
PATCH completo -> coleta_resultado:
  pendente interno permanece pendente
  coletado/em_bancada internos -> analisado
  timestamps/responsáveis existentes são preservados
  analista ausente -> __SEM_REGISTRO__
PATCH * -> apenas_resultado:
  pendente/coletado/em_bancada internos -> analisado
  preencher somente timestamps/responsáveis ausentes
TERCEIRIZADO/finalizado/cancelado não mudam
alongar fluxo não regride status
falha de normalização => rollback também do lab_config
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Domain/Rotina/RotinaConfigApiTest.php`

Expected: FAIL por rotas/action inexistentes.

- [ ] **Step 3: Implement minimal transactional action**

`UpdateRotinaConfig` deve:

1. abrir transação tenant;
2. bloquear singleton `lab_config` (`FOR UPDATE`);
3. validar modo de destino;
4. normalizar apenas internos não terminais conforme spec;
5. preservar dados reais existentes e usar `__SEM_REGISTRO__` apenas se ausentes;
6. atualizar configuração;
7. deixar triggers existentes recomputarem atendimento/auditoria;
8. commit ou rollback integral.

Não executar loop N+1 por exame se um update set-based seguro puder cumprir a mesma regra.

- [ ] **Step 4: Run GREEN**

Run: `php artisan test tests/Feature/Domain/Rotina/RotinaConfigApiTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

`feat: adiciona configuração transacional da rotina`

---

### Task 4: Intenções de coleta, análise, recoleta e cancelamento

**Files:**
- Create: `app/Domain/Atendimentos/Actions/TransitionAtendimentoExame.php`
- Create: `app/Http/Controllers/Rotina/TransitionRotinaExameController.php`
- Create: `app/Http/Requests/Rotina/TransitionRotinaExameRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Rotina/RotinaTransitionApiTest.php`

**Interfaces:**
- Produces: POST `/api/rotina/exames/{id}/transicao`.
- Accepted actions: `coletar`, `recoletar`, `iniciar_analise`, `finalizar_analise`, `cancelar`.
- Uses: Task 2 DB invariants.

- [ ] **Step 1: Write RED transition tests**

Cobrir:

```text
coletar/completo => coletado + data_coleta server-side
iniciar_analise/completo => em_bancada + data_analise
finalizar_analise/completo => analisado
coletar/coleta_resultado => analisado + data_coleta/data_analise + __SEM_REGISTRO__ analista
recoletar/completo|coleta_resultado => pendente, limpa timestamps do ciclo descartado, não altera valor/pagamento
recoletar/apenas_resultado => permanece analisado e não cria fila inexistente
cancelar sem motivo => 422
cancelar com motivo => cancelado, preserva timestamps clínicos anteriores
finalizado => não reabre
id inexistente => 404
transição incompatível => 409
payload tentando status/data_coleta/data_analise => não aceito pelo Request
```

Permissões devem ser testadas separadamente:

```text
coletar/recoletar => registrar_coleta
iniciar/finalizar análise => analisar_amostra
cancelar => cancelar_atendimento
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Domain/Rotina/RotinaTransitionApiTest.php`

Expected: FAIL por action/route inexistentes.

- [ ] **Step 3: Implement minimal transition action**

Dentro de transação tenant:

1. `SELECT ... FOR UPDATE` da ocorrência;
2. ler modo atual;
3. mapear ação para intenção permitida;
4. gerar timestamps server-side;
5. usar identidade autenticada/contexto já existente para responsável; não criar cadastro paralelo;
6. update da ocorrência;
7. deixar PostgreSQL validar estado efetivo;
8. traduzir violação de transição para `409` estável.

Repetição idempotente só pode retornar sucesso quando o estado persistido já representa exatamente o resultado da mesma intenção; não transformar transição errada em sucesso.

- [ ] **Step 4: Run GREEN + Atendimentos regression**

Run:

```bash
php artisan test tests/Feature/Domain/Rotina/RotinaTransitionApiTest.php
php artisan test tests/Feature/Domain/Atendimentos
```

Expected: PASS.

- [ ] **Step 5: Commit**

`feat: adiciona transições operacionais da rotina`

---

### Task 5: Filas derivadas de coleta e análise

**Files:**
- Create: `app/Domain/Atendimentos/Queries/ListRotinaColeta.php`
- Create: `app/Domain/Atendimentos/Queries/ListRotinaAnalise.php`
- Create: `app/Http/Controllers/Rotina/ListRotinaColetaController.php`
- Create: `app/Http/Controllers/Rotina/ListRotinaAnaliseController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Domain/Rotina/RotinaQueuesApiTest.php`

**Interfaces:**
- Produces: GET `/api/rotina/coleta` and GET `/api/rotina/analise`.
- Returns: dados mínimos derivados de `atendimentos` + `atendimento_exames`; não criar tabela de fila.

- [ ] **Step 1: Write RED queue tests**

Cobrir:

```text
modo completo: coleta mostra internos pendentes; análise mostra internos coletados/em_bancada
modo coleta_resultado: coleta mostra pendentes; análise retorna coleção vazia/indisponível conforme contrato HTTP escolhido
modo apenas_resultado: coleta e análise não materializam etapas desativadas
TERCEIRIZADO nunca aparece em análise interna
a fila não retorna finalizado/cancelado
tenant A não lê ocorrências de tenant B
leitura exige visualizar_atendimentos
```

Escolha de contrato: endpoints de etapa desativada retornam `200` com `data: []` e `enabled: false`, evitando usar erro HTTP para uma configuração normal.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Domain/Rotina/RotinaQueuesApiTest.php`

Expected: FAIL por queries/controllers inexistentes.

- [ ] **Step 3: Implement focused queries**

Queries devem selecionar somente colunas necessárias às telas operacionais. Não retornar blobs/resultados/PDF/auditoria inteira. Ordenação deve ser determinística (`atendimentos.data`, `atendimento_exames.id` ou equivalente estável já usado no projeto).

- [ ] **Step 4: Run GREEN**

Run: `php artisan test tests/Feature/Domain/Rotina/RotinaQueuesApiTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

`feat: adiciona filas derivadas da rotina`

---

### Task 6: Concorrência, auditoria e segurança cruzada

**Files:**
- Test: `tests/Feature/Concurrency/RotinaConcurrencyTest.php`
- Test/Modify: `tests/Feature/Security/*` se necessário para cobertura cross-domain.
- Modify implementation from Tasks 3–5 only when a failing test proves necessity.

**Interfaces:**
- Consumes all runtime interfaces from Tasks 1–5.
- Produces concurrency/security evidence; no new public API.

- [ ] **Step 1: Write RED concurrency/security tests**

Provar:

```text
duas coletas concorrentes => um estado/timestamp coerente, sem avanço duplo
duas transições incompatíveis concorrentes => uma vence, outra 409/erro determinístico
mudanças de modo concorrentes => config final e estados compatíveis com o modo final; nunca estado impossível
sessão Laravel isolada => 401 nas rotas Rotina
Bearer inválido => 401
X-Tenant sem membership => 403
permission mismatch por ação => 403
audit append-only continua protegido
cada transição relevante deixa evidência na auditoria existente, sem tabela nova
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Concurrency/RotinaConcurrencyTest.php`

Expected: qualquer falha deve apontar lock/transação/auditoria insuficiente, não ser contornada relaxando o teste.

- [ ] **Step 3: Apply minimal fixes**

Somente corrigir locks, contexto de auditoria ou tradução de erro comprovadamente necessários. Não introduzir lock distribuído.

- [ ] **Step 4: Run GREEN + security subsets**

Run:

```bash
php artisan test tests/Feature/Concurrency/RotinaConcurrencyTest.php
php artisan test tests/Feature/Security
```

Expected: PASS.

- [ ] **Step 5: Commit**

`test: endurece concorrência e segurança da rotina`

---

### Task 7: Documentação e gate final da onda

**Files:**
- Modify: `README.md`
- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/superpowers/plans/2026-09-09-rotina-fluxo-operacional.md` somente para marcar execução se o padrão do repositório fizer isso; não reescrever requisitos pós-fato.

**Interfaces:**
- No new runtime interface.

- [ ] **Step 1: Review diff against spec**

Confirmar que o diff não contém:

```text
frontend sislacprivado
Supabase migrations/writes
resultados/PDF/estoque/lab apoio
batch endpoint
Redis/queues/WebSocket/CQRS/event bus
segundo cálculo de status_atendimento
segunda tabela de auditoria
```

- [ ] **Step 2: Update docs**

Documentar somente o que estiver implementado e testado: três modos, rotas, cadeia Bearer, DB authority, filas derivadas e cutover ainda bloqueado.

- [ ] **Step 3: Run complete verification on one SHA**

Executar os mesmos gates do CI do repositório:

```bash
composer validate --strict
composer audit --locked
php scripts/check-supabase-contract.php
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test --parallel
bash scripts/check-architecture.sh
bash scripts/check-backend-scope.sh
bash scripts/check-postgresql-only.sh
bash scripts/check-no-env.sh
```

Mais os guards adicionais que o workflow atual executar.

Expected: todos PASS no mesmo SHA.

- [ ] **Step 4: Verify branch perimeter**

Comparar `feat/atendimentos-laravel...HEAD` e confirmar que todo arquivo alterado pertence à Rotina, autorização necessária, provisioning, documentação ou testes da onda.

- [ ] **Step 5: Create/update draft PR**

PR deve usar `feat/atendimentos-laravel` como base enquanto Atendimentos não estiver em `main`, permanecer DRAFT e declarar explicitamente que frontend/cutover não fazem parte da entrega.

- [ ] **Step 6: Final review**

Revisar spec compliance e qualidade. Corrigir qualquer finding load-bearing e repetir o CI completo no novo SHA antes de declarar a onda tecnicamente concluída.

---

## Self-review result

- Spec coverage: configuração, três modos, mudança de modo, short-circuit, recoleta, cancelamento, filas, terceirizados, permissões, auditoria, status agregado, concorrência, erros, provisioning e gates finais estão mapeados nas Tasks 1–7.
- Placeholder scan: nenhum `TODO`, `TBD`, “similar à Task N” ou etapa sem comportamento esperado.
- Type/interface consistency: as rotas e ações públicas usadas nas Tasks 3–6 são definidas antes do consumo; nenhum endpoint batch ou CRUD genérico foi introduzido.
- Ruling: endpoint de fila desativada retorna `200` com `enabled: false` e `data: []` porque desativação é configuração válida, não erro operacional.
- Ruling: qualquer adição de coluna a `atendimento_exames` deve ocorrer em `000400`; migrations históricas `000200/000300` só mudam se teste de regressão demonstrar incompatibilidade estrutural inevitável.
