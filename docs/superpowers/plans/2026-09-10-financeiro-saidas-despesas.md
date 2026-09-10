# Financeiro — Saídas / Despesas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tornar `financeiro_saidas` um módulo operacional Laravel não destrutivo, com protocolo server-side, correção somente enquanto aberta, transição formal para paga, estorno auditável, integração com Caixa e listagem paginada.

**Architecture:** `financeiro_saidas` permanece a única SSOT no banco PostgreSQL físico do laboratório. Laravel expressa comandos de negócio pequenos; o PostgreSQL protege protocolo, coerência de estado, imutabilidade terminal e DELETE. PATCH/estorno usam transação + `FOR UPDATE`; o trigger de Caixa continua responsável pelo vínculo automático de Saídas pagas em Dinheiro/PIX.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, stancl/tenancy, Pest, Supabase Auth somente para Bearer/transição.

**Spec:** `docs/superpowers/specs/2026-09-10-financeiro-saidas-despesas-design.md`

## Global Constraints

- Base funcional: `fase-financeiro-caixa-operacional`, SHA `8c563881ceb21dcf842b5facd26b75ee4206bce9`.
- Branch de execução: `fase-financeiro-saidas-despesas`.
- Laravel/PostgreSQL tenant é o backend definitivo desta funcionalidade.
- Supabase live é baseline somente leitura; nenhuma escrita, DDL ou migration nele.
- `financeiro_saidas` é a única SSOT de despesas; não criar livro paralelo.
- `status` é a autoridade; `foi_pago` é derivado e nunca aceito como autoridade HTTP.
- Estados: `aberta -> paga -> cancelada` e `aberta -> cancelada`; `paga`/`cancelada` são terminais para edição comum.
- `cancelada` só pode ser materializada pelo fluxo formal de estorno, com `financeiro_estornos` correspondente.
- Não criar `DELETE /api/financeiro/saidas/{id}`.
- Não introduzir Redis, fila, worker, event bus, optimistic versioning adicional, DRE, centro de custo, conciliação ou ERP.
- Funções/triggers novos: `SECURITY INVOKER`, `SET search_path = ''`, referências schema-qualified.
- Dinheiro/PIX pagos reutilizam o vínculo automático do Caixa; outras formas não entram automaticamente.
- Permissões: leitura `visualizar_financeiro`; mutações/estorno `gestao_financeira`.
- Todo dinheiro é persistido/calculado como `numeric`; não usar `float` para decisão financeira.

---

## File map

**Criar**

- `database/migrations/tenant/2026_09_10_000800_harden_financeiro_saidas.php` — protocolo, estado, imutabilidade terminal, `updated_at` e ajuste do trigger de Caixa.
- `app/Domain/Financeiro/Models/FinanceiroSaida.php` — representação Eloquent tenant.
- `app/Domain/Financeiro/Actions/CreateFinanceiroSaida.php` — criação normalizada.
- `app/Domain/Financeiro/Actions/UpdateFinanceiroSaida.php` — correção aberta + `aberta -> paga` com lock.
- `app/Domain/Financeiro/Actions/ReverseFinanceiroSaida.php` — estorno transacional + `financeiro_estornos`.
- `app/Domain/Financeiro/Queries/ListFinanceiroSaidas.php` — filtros + cursor `(data,id)`.
- `app/Http/Requests/Financeiro/ListFinanceiroSaidasRequest.php` — filtros da listagem.
- `app/Http/Requests/Financeiro/StoreFinanceiroSaidaRequest.php` — criação e campos HTTP proibidos.
- `app/Http/Requests/Financeiro/UpdateFinanceiroSaidaRequest.php` — PATCH e campos HTTP proibidos.
- `app/Http/Requests/Financeiro/ReverseFinanceiroSaidaRequest.php` — motivo obrigatório.
- `app/Http/Resources/Financeiro/FinanceiroSaidaResource.php` — representação canônica da API.
- `app/Http/Controllers/Financeiro/ListFinanceiroSaidasController.php`
- `app/Http/Controllers/Financeiro/StoreFinanceiroSaidaController.php`
- `app/Http/Controllers/Financeiro/UpdateFinanceiroSaidaController.php`
- `app/Http/Controllers/Financeiro/ReverseFinanceiroSaidaController.php`
- `tests/Feature/Domain/Financeiro/FinanceiroSaidasSchemaTest.php`
- `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- `docs/contracts/financeiro-saidas-despesas.json`
- `docs/financeiro-saidas-despesas.md`

**Modificar**

- `routes/api.php` — quatro rotas, sem DELETE.
- `tests/Feature/Provisioning/TenantProvisionerTest.php` — schema version `000800` + smoke das invariantes.
- `docs/ARCHITECTURE.md` — integrar a nova subfase à arquitetura financeira.

---

### Task 1: Hardening físico de `financeiro_saidas`

**Files:**
- Create: `tests/Feature/Domain/Financeiro/FinanceiroSaidasSchemaTest.php`
- Create: `database/migrations/tenant/2026_09_10_000800_harden_financeiro_saidas.php`
- Modify: `tests/Feature/Provisioning/TenantProvisionerTest.php`

**Interfaces:**
- Consumes: tabelas `financeiro_saidas`, `financeiro_estornos`, `caixa_sessoes`, `protocolo_sequence` e triggers de Caixa criados até `000700`.
- Produces: funções `public.next_financeiro_saida_protocolo(timestamptz)`, `public.financeiro_saida_prepare_state()`, `public.financeiro_saida_assign_protocolo()`, `public.financeiro_saida_protect_update()` e `public.financeiro_saida_touch_updated_at()`; schema tenant atual `2026_09_10_000800_harden_financeiro_saidas`.

- [ ] **Step 1: escrever RED do schema**

Cobrir no PostgreSQL tenant real:

```php
it('gera protocolo SAI server-side e ignora valor provisório do insert', function () {
    $row = $pdo->query(<<<'SQL'
        INSERT INTO financeiro_saidas
            (protocolo, data, descricao, valor, tipo_despesa, destino_pagamento)
        VALUES ('CLIENTE-NAO-MANDA', '2026-09-10 12:00:00+00', 'Energia', 100, 'Conta', 'Fornecedor')
        RETURNING protocolo
    SQL)?->fetch(PDO::FETCH_ASSOC);

    expect($row['protocolo'] ?? null)->toMatch('/^SAI-2026-\d{7}$/');
});
```

Adicionar casos para:

```php
// valor 0 e negativo -> PDOException
// UPDATE protocolo -> PDOException
// assinatura_protocolo não nula -> imutável
// INSERT status='paga', foi_pago=false, data_pagamento=null -> banco normaliza true + current_date
// INSERT status='aberta', foi_pago=true -> banco normaliza false + data_pagamento null
// UPDATE aberta -> paga -> permitido
// UPDATE paga alterando valor/descricao/data/forma -> PDOException
// UPDATE cancelada alterando negócio/reabrindo -> PDOException
// UPDATE direta para cancelada sem estorno -> PDOException
// DELETE -> permanece bloqueado pela 000700
```

- [ ] **Step 2: executar CI e confirmar RED comportamental**

Run esperado:

```bash
php artisan test tests/Feature/Domain/Financeiro/FinanceiroSaidasSchemaTest.php
```

No GitHub Actions, Pint/Larastan/guards devem continuar verdes; as falhas devem se limitar às invariantes ainda inexistentes. Corrigir fixture/estilo antes de tocar produção se o RED morrer antes do Pest.

- [ ] **Step 3: criar migration `000800` mínima**

A migration deve primeiro substituir o check antigo `valor >= 0` por `valor > 0` e depois instalar os guards:

```sql
ALTER TABLE public.financeiro_saidas
  DROP CONSTRAINT IF EXISTS financeiro_saidas_valor_check;

ALTER TABLE public.financeiro_saidas
  ADD CONSTRAINT financeiro_saidas_valor_check CHECK (valor > 0);
```

Protocolo atômico por ano:

```sql
CREATE OR REPLACE FUNCTION public.next_financeiro_saida_protocolo(p_data timestamptz)
RETURNS text
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = ''
AS $$
DECLARE
    v_ano integer := EXTRACT(YEAR FROM COALESCE(p_data, now()))::integer;
    v_numero bigint;
BEGIN
    INSERT INTO public.protocolo_sequence (prefixo, ano, ultimo_numero)
    VALUES ('SAI', v_ano, 1)
    ON CONFLICT (prefixo, ano) DO UPDATE
       SET ultimo_numero = public.protocolo_sequence.ultimo_numero + 1,
           updated_at = now()
    RETURNING ultimo_numero INTO v_numero;

    RETURN 'SAI-' || v_ano::text || '-' || lpad(v_numero::text, 7, '0');
END;
$$;
```

O BEFORE INSERT deve ser autoridade:

```sql
CREATE OR REPLACE FUNCTION public.financeiro_saida_assign_protocolo()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = ''
AS $$
BEGIN
    NEW.protocolo := public.next_financeiro_saida_protocolo(NEW.data);
    NEW.assinatura_protocolo := NULL;
    RETURN NEW;
END;
$$;
```

Normalizar estado antes do trigger de Caixa:

```sql
CREATE OR REPLACE FUNCTION public.financeiro_saida_prepare_state()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = ''
AS $$
BEGIN
    IF NEW.status = 'paga' THEN
        NEW.foi_pago := true;
        NEW.data_pagamento := COALESCE(NEW.data_pagamento, CURRENT_DATE);
    ELSIF NEW.status = 'aberta' THEN
        NEW.foi_pago := false;
        NEW.data_pagamento := NULL;
    ELSE
        NEW.foi_pago := false;
    END IF;
    RETURN NEW;
END;
$$;
```

A proteção de UPDATE deve permitir dados de negócio somente quando `OLD.status='aberta'`; permitir `aberta -> paga`; e permitir `-> cancelada` somente quando a transação já registrar estorno. Para evitar janela intermediária, `ReverseFinanceiroSaida` deve inserir `financeiro_estornos` **antes** do UPDATE para `cancelada` dentro da mesma transação:

```sql
IF NEW.status = 'cancelada' AND OLD.status <> 'cancelada' THEN
    IF NOT EXISTS (
        SELECT 1 FROM public.financeiro_estornos e
        WHERE e.origem_tipo = 'saida' AND e.origem_id = OLD.id
    ) THEN
        RAISE EXCEPTION 'saída só pode ser cancelada por estorno';
    END IF;
END IF;
```

Proteger protocolo/assinatura e campos terminais:

```sql
IF NEW.protocolo IS DISTINCT FROM OLD.protocolo THEN
    RAISE EXCEPTION 'protocolo da saída é imutável' USING ERRCODE = '23514';
END IF;

IF OLD.assinatura_protocolo IS NOT NULL
   AND NEW.assinatura_protocolo IS DISTINCT FROM OLD.assinatura_protocolo THEN
    RAISE EXCEPTION 'assinatura da saída é imutável' USING ERRCODE = '23514';
END IF;

IF OLD.status IN ('paga', 'cancelada')
   AND (
       NEW.data IS DISTINCT FROM OLD.data
       OR NEW.descricao IS DISTINCT FROM OLD.descricao
       OR NEW.valor IS DISTINCT FROM OLD.valor
       OR NEW.tipo_despesa IS DISTINCT FROM OLD.tipo_despesa
       OR NEW.destino_pagamento IS DISTINCT FROM OLD.destino_pagamento
       OR NEW.data_vencimento IS DISTINCT FROM OLD.data_vencimento
       OR NEW.data_pagamento IS DISTINCT FROM OLD.data_pagamento
       OR NEW.forma_pagamento IS DISTINCT FROM OLD.forma_pagamento
       OR NEW.caixa_sessao_id IS DISTINCT FROM OLD.caixa_sessao_id
   ) THEN
    RAISE EXCEPTION 'saída financeira terminal é imutável; use estorno';
END IF;
```

Substituir o attach de Saída da `000700` por versão que usa `NEW.status = 'paga'`, mantendo a regra de exatamente um Caixa aberto e `FOR SHARE`.

Controlar a ordem com nomes de triggers ordenáveis, por exemplo:

```sql
CREATE TRIGGER trg_10_financeiro_saida_prepare_state
BEFORE INSERT OR UPDATE ON public.financeiro_saidas
FOR EACH ROW EXECUTE FUNCTION public.financeiro_saida_prepare_state();

CREATE TRIGGER trg_20_financeiro_saida_assign_protocolo
BEFORE INSERT ON public.financeiro_saidas
FOR EACH ROW EXECUTE FUNCTION public.financeiro_saida_assign_protocolo();

CREATE TRIGGER trg_30_caixa_attach_saida
BEFORE INSERT OR UPDATE ON public.financeiro_saidas
FOR EACH ROW EXECUTE FUNCTION public.caixa_attach_saida();

CREATE TRIGGER trg_40_financeiro_saida_protect_update
BEFORE UPDATE ON public.financeiro_saidas
FOR EACH ROW EXECUTE FUNCTION public.financeiro_saida_protect_update();
```

Preservar o bloqueio DELETE existente; não recriar tabela.

- [ ] **Step 4: atualizar provisionamento e rodar GREEN do schema**

Em `TenantProvisionerTest.php`:

```php
expect($run?->schema_version)
    ->toBe('2026_09_10_000800_harden_financeiro_saidas');
```

Rodar CI completo. Esperado: schema tests GREEN sem regressão dos testes do Caixa.

- [ ] **Step 5: commit checkpoint**

```bash
git add database/migrations/tenant/2026_09_10_000800_harden_financeiro_saidas.php \
        tests/Feature/Domain/Financeiro/FinanceiroSaidasSchemaTest.php \
        tests/Feature/Provisioning/TenantProvisionerTest.php
git commit -m "feat: endurece saídas financeiras no PostgreSQL"
```

---

### Task 2: Criação de Saída via API

**Files:**
- Create: `app/Domain/Financeiro/Models/FinanceiroSaida.php`
- Create: `app/Domain/Financeiro/Actions/CreateFinanceiroSaida.php`
- Create: `app/Http/Requests/Financeiro/StoreFinanceiroSaidaRequest.php`
- Create: `app/Http/Resources/Financeiro/FinanceiroSaidaResource.php`
- Create: `app/Http/Controllers/Financeiro/StoreFinanceiroSaidaController.php`
- Create/extend: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces: `CreateFinanceiroSaida::handle(array $payload): FinanceiroSaida`.
- Resource exposes dinheiro como string decimal e datas canônicas.

- [ ] **Step 1: escrever RED de criação/RBAC**

Casos:

```php
$this->postJson('/api/financeiro/saidas', [
    'descricao' => 'Conta de energia',
    'valor' => '120.50',
    'tipo_despesa' => 'Conta',
    'destino_pagamento' => 'Concessionária',
])->assertCreated()
  ->assertJsonPath('data.status', 'aberta')
  ->assertJsonPath('data.foi_pago', false)
  ->assertJsonPath('data.valor', '120.50');
```

Também provar:

```php
// protocolo segue /^SAI-\d{4}-\d{7}$/
// POST status=paga gera data_pagamento e foi_pago=true
// status=cancelada -> 422
// id/protocolo/assinatura_protocolo/foi_pago/caixa_sessao_id/created_at/updated_at -> 422 missing
// valor 0 e negativo -> 422
// recepcionista -> 403
// não existe rota DELETE -> 405/404 conforme roteamento Laravel
```

- [ ] **Step 2: rodar RED e confirmar 404/validação ausente**

Executar o teste focado e CI. Nenhum arquivo de produção desta task antes do RED válido.

- [ ] **Step 3: implementar model/request/action/resource/controller**

Model:

```php
final class FinanceiroSaida extends Model
{
    protected $table = 'financeiro_saidas';
    protected $guarded = ['id', 'protocolo', 'assinatura_protocolo', 'foi_pago', 'caixa_sessao_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'data' => 'immutable_datetime',
            'valor' => 'decimal:2',
            'data_vencimento' => 'immutable_date',
            'foi_pago' => 'boolean',
            'data_pagamento' => 'immutable_date',
        ];
    }
}
```

Request de criação:

```php
return [
    'descricao' => ['required', 'string', 'max:2000'],
    'valor' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
    'tipo_despesa' => ['required', 'string', 'max:255'],
    'destino_pagamento' => ['required', 'string', 'max:255'],
    'data' => ['sometimes', 'date'],
    'data_vencimento' => ['sometimes', 'nullable', 'date'],
    'forma_pagamento' => ['sometimes', 'nullable', 'string', 'max:100'],
    'status' => ['sometimes', Rule::in(['aberta', 'paga'])],
    'data_pagamento' => ['sometimes', 'nullable', 'date', Rule::requiredIf(fn () => $this->input('status') === 'paga')],
    'id' => ['missing'],
    'protocolo' => ['missing'],
    'assinatura_protocolo' => ['missing'],
    'foi_pago' => ['missing'],
    'caixa_sessao_id' => ['missing'],
    'created_at' => ['missing'],
    'updated_at' => ['missing'],
];
```

A action deve normalizar strings com `Str::squish`, preencher `status` default `aberta`, não fornecer protocolo e salvar dentro do tenant ativo. O banco normaliza `foi_pago`/`data_pagamento`.

Controller deve ser fino e rota:

```php
Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:gestao_financeira'])
    ->post('/financeiro/saidas', StoreFinanceiroSaidaController::class)
    ->name('financeiro.saidas.store');
```

- [ ] **Step 4: rodar GREEN focado + CI completo**

Esperado: criação e RBAC GREEN, Caixa permanece GREEN.

- [ ] **Step 5: commit checkpoint**

```bash
git commit -m "feat: cadastra saídas financeiras"
```

---

### Task 3: Correção de Saída aberta e efetivação de pagamento

**Files:**
- Create: `app/Domain/Financeiro/Actions/UpdateFinanceiroSaida.php`
- Create: `app/Http/Requests/Financeiro/UpdateFinanceiroSaidaRequest.php`
- Create: `app/Http/Controllers/Financeiro/UpdateFinanceiroSaidaController.php`
- Modify: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces: `UpdateFinanceiroSaida::handle(int $id, array $payload): FinanceiroSaida`.

- [ ] **Step 1: escrever RED do PATCH**

Provar:

```php
// aberta -> corrige descricao/valor/tipo/destino/data/data_vencimento/forma
// aberta -> paga -> status paga, foi_pago true, data_pagamento server-side
// paga em Dinheiro/PIX -> caixa_sessao_id preenchido quando exatamente um Caixa aberto
// paga por Crédito -> caixa_sessao_id null
// sem Caixa aberto -> pagamento da Saída continua válido
// PATCH paga -> 409 sem mudança
// PATCH cancelada -> 409 sem mudança
// status=cancelada -> 422
// protocolo/assinatura/foi_pago/caixa_sessao_id -> 422
// id inexistente -> 404
// sem gestao_financeira -> 403
```

- [ ] **Step 2: rodar RED**

Esperado: rota ausente/action ausente, sem falhas inesperadas em schema.

- [ ] **Step 3: implementar request/action/controller**

Request aceita somente os campos editáveis e `status` em `aberta|paga`; os campos server-side usam `missing`.

Action:

```php
return DB::transaction(function () use ($id, $payload): FinanceiroSaida {
    $saida = FinanceiroSaida::query()->lockForUpdate()->findOrFail($id);

    if ($saida->getAttribute('status') !== 'aberta') {
        throw new DomainException('Saída paga ou cancelada não pode ser editada; use estorno.');
    }

    // aplicar somente campos validados
    // se status=paga, deixar o PostgreSQL sincronizar foi_pago/data_pagamento
    $saida->save();

    return $saida->refresh();
});
```

Controller mapeia somente `DomainException` conhecida para 409.

Rota:

```php
Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:gestao_financeira'])
    ->patch('/financeiro/saidas/{id}', UpdateFinanceiroSaidaController::class)
    ->whereNumber('id')
    ->name('financeiro.saidas.update');
```

- [ ] **Step 4: GREEN focado + CI**

Confirmar também que `CaixaOperacionalApiTest` continua verde.

- [ ] **Step 5: commit checkpoint**

```bash
git commit -m "feat: controla edição e pagamento de saídas"
```

---

### Task 4: Estorno não destrutivo de Saída

**Files:**
- Create: `app/Domain/Financeiro/Actions/ReverseFinanceiroSaida.php`
- Create: `app/Http/Requests/Financeiro/ReverseFinanceiroSaidaRequest.php`
- Create: `app/Http/Controllers/Financeiro/ReverseFinanceiroSaidaController.php`
- Modify: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `FinanceiroSaida`, `FinanceiroEstorno` e UNIQUE `uq_financeiro_estornos_origem`.
- Produces: `ReverseFinanceiroSaida::handle(int $id, string $motivo, string $userId): array{saida:FinanceiroSaida,estorno:FinanceiroEstorno}`.

- [ ] **Step 1: escrever RED do estorno**

Provar:

```php
// estornar aberta -> cancelada, foi_pago=false, estorno origem_tipo=saida
// estornar paga -> preserva valor/data/data_pagamento/forma/caixa_sessao_id, mas status cancelada
// segundo estorno -> 409 e count(estornos)=1
// motivo vazio -> 422
// inexistente -> 404
// sem gestao_financeira -> 403
// saldo do Caixa ignora saída estornada
```

- [ ] **Step 2: rodar RED**

Esperado: rota/action ausente.

- [ ] **Step 3: implementar estorno com ordem correta**

A ordem dentro da **mesma transação** deve ser:

```php
$saida = FinanceiroSaida::query()->lockForUpdate()->findOrFail($id);

if (FinanceiroEstorno::query()
    ->where('origem_tipo', 'saida')
    ->where('origem_id', $id)
    ->exists()) {
    throw new DomainException('Saída já foi estornada.');
}

$estorno = FinanceiroEstorno::query()->create([
    'origem_tipo' => 'saida',
    'origem_id' => $id,
    'motivo' => Str::squish($motivo),
    'valor' => (string) $saida->getAttribute('valor'),
    'criado_por' => $userId,
]);

$saida->setAttribute('status', 'cancelada');
$saida->save();
```

Inserir o estorno **antes** da mudança para `cancelada` é intencional: o trigger do PostgreSQL exige a existência do estorno correspondente. Se o UPDATE falhar, a transação inteira desfaz também o INSERT do estorno.

Rota:

```php
Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:gestao_financeira'])
    ->post('/financeiro/saidas/{id}/estorno', ReverseFinanceiroSaidaController::class)
    ->whereNumber('id')
    ->name('financeiro.saidas.estorno');
```

- [ ] **Step 4: GREEN focado + Caixa regression**

Rodar `FinanceiroSaidasApiTest` e `CaixaOperacionalApiTest` juntos antes do CI completo.

- [ ] **Step 5: commit checkpoint**

```bash
git commit -m "feat: estorna saídas financeiras"
```

---

### Task 5: Listagem canônica com filtros e cursor

**Files:**
- Create: `app/Domain/Financeiro/Queries/ListFinanceiroSaidas.php`
- Create: `app/Http/Requests/Financeiro/ListFinanceiroSaidasRequest.php`
- Create: `app/Http/Controllers/Financeiro/ListFinanceiroSaidasController.php`
- Modify: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces: `ListFinanceiroSaidas::handle(array $filters): array{data:list<array<string,mixed>>,next_cursor:?array{data:string,id:int}}`.

- [ ] **Step 1: escrever RED da listagem**

Provar:

```php
$this->getJson('/api/financeiro/saidas?status=aberta&limit=2')
    ->assertOk()
    ->assertJsonCount(2, 'data')
    ->assertJsonStructure(['data', 'meta' => ['nextCursor']]);
```

Cobrir `search` em protocolo/descrição/tipo/destino, `status`, `date_from`, `date_to`, cursor `(data,id)`, ordem DESC, teto 100, leitura `visualizar_financeiro` e recepcionista 403.

- [ ] **Step 2: rodar RED**

Esperado: rota/query ausente.

- [ ] **Step 3: implementar request/query/controller**

Request:

```php
return [
    'search' => ['sometimes', 'nullable', 'string', 'max:200'],
    'status' => ['sometimes', 'nullable', Rule::in(['aberta', 'paga', 'cancelada'])],
    'date_from' => ['sometimes', 'nullable', 'date'],
    'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
    'cursor_data' => ['sometimes', 'nullable', 'date'],
    'cursor_id' => ['required_with:cursor_data', 'nullable', 'integer', 'min:1'],
    'limit' => ['sometimes', 'integer', 'between:1,100'],
];
```

Query deve seguir o padrão já usado por `ListAReceberPacientes`: ordenar `data DESC, id DESC`, buscar `limit + 1`, criar `next_cursor` apenas se houver página seguinte e serializar valores monetários como string com duas casas.

Cursor:

```php
$query->where(function (Builder $page) use ($cursorData, $cursorId): void {
    $page->where('data', '<', $cursorData)
        ->orWhere(function (Builder $sameDate) use ($cursorData, $cursorId): void {
            $sameDate->where('data', '=', $cursorData)
                ->where('id', '<', (int) $cursorId);
        });
});
```

Rota:

```php
Route::middleware(['supabase.auth', 'tenant', 'tenant.permission:visualizar_financeiro'])
    ->get('/financeiro/saidas', ListFinanceiroSaidasController::class)
    ->name('financeiro.saidas.index');
```

- [ ] **Step 4: GREEN focado + CI**

Confirmar paginação estável com duas linhas na mesma `data` e ids diferentes.

- [ ] **Step 5: commit checkpoint**

```bash
git commit -m "feat: lista saídas financeiras"
```

---

### Task 6: Contrato, arquitetura e gate final

**Files:**
- Create: `docs/contracts/financeiro-saidas-despesas.json`
- Create: `docs/financeiro-saidas-despesas.md`
- Modify: `docs/ARCHITECTURE.md`
- Verify: `tests/Feature/Domain/Financeiro/FinanceiroSaidasApiTest.php`
- Verify: `tests/Feature/Domain/Financeiro/FinanceiroSaidasSchemaTest.php`
- Verify: `tests/Feature/Domain/Financeiro/CaixaOperacionalApiTest.php`
- Verify: `tests/Feature/Domain/Financeiro/CaixaOperacionalSchemaTest.php`

**Interfaces:**
- Produces: SSOT documental da subfase e SHA final verificável.

- [ ] **Step 1: criar contrato JSON**

Registrar explicitamente:

```json
{
  "name": "financeiro-saidas-despesas",
  "authority": "tenant-postgresql",
  "states": ["aberta", "paga", "cancelada"],
  "terminal_for_edit": ["paga", "cancelada"],
  "cancel_requires_estorno": true,
  "physical_delete": false,
  "http": {
    "index": "GET /api/financeiro/saidas",
    "store": "POST /api/financeiro/saidas",
    "update": "PATCH /api/financeiro/saidas/{id}",
    "reverse": "POST /api/financeiro/saidas/{id}/estorno",
    "delete": null
  },
  "caixa": {
    "eligible_payment_methods": ["Dinheiro", "PIX"],
    "authority_field": "status",
    "eligible_status": "paga"
  }
}
```

- [ ] **Step 2: criar documento operacional e integrar `ARCHITECTURE.md`**

Documentar somente o comportamento final implementado, divergências intencionais do live (sem HMAC nesta fase) e ausência de escrita no Supabase.

- [ ] **Step 3: executar verificação final no mesmo SHA**

Obrigatório no GitHub Actions:

```text
Composer Validate      SUCCESS
Composer Audit         SUCCESS
Supabase manifest      SUCCESS
Pint                    SUCCESS
Larastan level 8        SUCCESS
Pest/PostgreSQL 17      SUCCESS
Repository guards       SUCCESS
```

Registrar contagem exata de testes/assertions do log, sem reutilizar contagem de run anterior.

- [ ] **Step 4: comparar escopo contra base do Caixa**

Comparar:

```text
base: 8c563881ceb21dcf842b5facd26b75ee4206bce9
head: <SHA final>
```

Esperado: somente arquivos de Saídas/Financeiro, migration `000800`, rotas, provisionamento, testes e documentação. Nenhuma alteração em Pacientes, Rotina, cálculo de Atendimento ou infraestrutura não relacionada.

- [ ] **Step 5: commit documental final**

```bash
git commit -m "docs: fecha contrato de saídas e despesas"
```

Não fazer merge automático ao concluir.
