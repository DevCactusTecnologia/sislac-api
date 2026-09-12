# Implementação da arquitetura final enxuta do SISLAC

Data: 2026-09-12
Status: aprovado para execução
Especificação: `docs/superpowers/specs/2026-09-12-arquitetura-final-enxuta-sislac-design.md`

## Objetivo

Migrar o backend atual para a arquitetura final aprovada sem reescrita em massa e sem remover um caminho antes de existir substituto testado:

```text
React/Vite
  -> Laravel Sanctum
  -> User
  -> laboratory_id
  -> Laboratory
  -> conexão PostgreSQL dinâmica `lab`
  -> domínio
```

Regras fixas:

- um usuário operacional pertence a exatamente um laboratório;
- um laboratório pode possuir qualquer quantidade de usuários;
- o frontend nunca escolhe o laboratório nem o banco;
- cada laboratório possui uma conexão PostgreSQL própria;
- o provedor do PostgreSQL é irrelevante para o domínio;
- Supabase permanece somente enquanto houver consumidor legado real;
- remoções são feitas por evidência de zero consumidor;
- usar recursos nativos do Laravel antes de qualquer pacote ou abstração adicional.

## Fase 1 — fundação nativa Laravel, sem quebrar o legado

### Tarefa 1.1 — contrato do banco principal

**Teste primeiro**

Criar/ajustar testes para provar:

- `laboratories` possui os campos mínimos aprovados;
- `users.laboratory_id` é nullable para permitir Super Admin e obrigatório por regra para usuário operacional;
- `users.email` continua único;
- um laboratório possui muitos usuários;
- um usuário operacional resolve um único laboratório;
- `database_url` não aparece serializada nem em logs/JSON por padrão.

**Implementação mínima**

- introduzir `Laboratory` como modelo central;
- adicionar `laboratory_id`, `role`, `status`, `permissions_extra`, `permissions_revoked` a `users` somente na medida necessária para substituir `memberships`;
- armazenar `database_url` em coluna `TEXT` com cast `encrypted` nativo do Eloquent;
- preservar estruturas antigas enquanto ainda tiverem consumidores.

### Tarefa 1.2 — conexão PostgreSQL `lab`

**Teste primeiro**

Provar com dois bancos PostgreSQL distintos:

- usuário do Lab A resolve o banco A;
- usuário do Lab B resolve o banco B;
- uma segunda requisição não reutiliza a conexão do laboratório anterior;
- laboratório suspenso recebe 403 antes de executar domínio;
- usuário operacional sem laboratório recebe 403;
- Super Admin não ganha acesso clínico implícito.

**Implementação mínima**

- declarar somente o template `lab` em `config/database.php`;
- criar uma única classe pequena `LaboratoryDatabase` para configurar a conexão a partir da URL descriptografada;
- usar `DB::purge('lab')` antes de resolver nova conexão;
- middleware `UseLaboratoryDatabase` resolve exclusivamente `$request->user()->laboratory`;
- nenhum `X-Tenant`, `tenant_id` de request ou seletor do frontend participa da decisão.

### Tarefa 1.3 — models do domínio

**Teste primeiro**

Provar que models clínicos consultam `lab` e nunca o banco principal.

**Implementação mínima**

- introduzir `LabModel` apenas para eliminar repetição real de `protected $connection = 'lab'`;
- migrar os models clínicos existentes para `LabModel`;
- não mover controllers/actions/queries sem necessidade funcional.

## Fase 2 — Sanctum para a SPA

### Tarefa 2.1 — autenticação first-party

**Teste primeiro**

Cobrir:

- login válido cria sessão Laravel;
- login inválido não autentica;
- sessão é regenerada no login;
- logout invalida sessão e regenera CSRF token;
- rotas clínicas exigem `auth:sanctum`;
- request autenticada resolve usuário e laboratório sem Bearer Supabase.

**Implementação mínima**

- habilitar `statefulApi()` conforme documentação oficial do Sanctum;
- usar cookie/sessão do Laravel, não personal access token para a SPA first-party;
- expor apenas endpoints mínimos de login/logout/usuário necessários ao React;
- manter temporariamente o middleware Supabase somente onde o frontend legado ainda depender dele.

### Tarefa 2.2 — CORS/cookies

- restringir origins aos hosts reais do SISLAC;
- `supports_credentials=true` enquanto a SPA e API usam cookie Sanctum;
- alinhar domínio/cookie/SameSite/secure ao ambiente real;
- não criar configuração para domínios hipotéticos.

## Fase 3 — autorização pelo usuário, não membership

### Tarefa 3.1 — defaults atuais

**Teste primeiro**

Copiar como contrato os perfis e permissões realmente existentes no SISLAC atual:

- admin;
- analista;
- recepcionista;
- financeiro;
- extras;
- revogadas.

Regra:

```text
effective = defaults(role) + extra - revoked
```

**Implementação mínima**

- uma única autoridade pequena para defaults;
- `User::hasPermission()` ou classe equivalente apenas se reduzir duplicação;
- substituir `MembershipAuthorizer` nos endpoints já migrados;
- nenhum pacote RBAC.

## Fase 4 — cutover de rotas clínicas

Para cada módulo Laravel já existente (Pacientes, Atendimentos, Rotina, Financeiro):

1. escrever teste do novo caminho `auth:sanctum -> UseLaboratoryDatabase -> permission`;
2. migrar a rota;
3. provar isolamento Lab A/Lab B;
4. manter o comportamento de domínio existente;
5. remover o caminho Supabase/tenant antigo daquela rota somente após todos os testes ficarem verdes.

Não refatorar regras clínicas durante a troca de infraestrutura, salvo correção necessária demonstrada por teste.

## Fase 5 — provisionamento enxuto

### Regra de escopo

Existem dois casos reais e diferentes:

1. **PostgreSQL administrado pelo SISLAC**: Laravel possui credencial de provisionamento e pode executar `CREATE DATABASE`, migrations e smoke check.
2. **PostgreSQL externo já fornecido** (Supabase, Neon, RDS, VPS de terceiro): o laboratório informa/recebe uma conexão PostgreSQL existente; Laravel valida, migra o schema e usa a URL. Não automatizar criação de projeto/conta de provedor.

Não criar `SupabaseDatabaseDriver`, `NeonDriver`, `AwsDriver` ou equivalentes.

### Tarefa 5.1 — `LaboratoryProvisioner`

- renomear/refatorar o provisionamento atual somente depois de remover dependência do modelo `Tenant`;
- usar migrations `database/migrations/tenant` como fonte de verdade do schema clínico;
- `active` somente após conexão, migration e smoke check concluírem;
- erro deixa `provisioning_failed`;
- segredos nunca entram em erro/log.

## Fase 6 — Super Admin

- trocar linguagem/rotas/views de tenant para laboratório;
- listar/criar/ver/suspender/reativar/retry;
- Super Admin continua com sessão Laravel e sem acesso clínico implícito;
- nenhuma impersonação, billing, plano ou analytics SaaS.

## Fase 7 — remoção por evidência

Antes de apagar cada item, executar busca de consumidores + suíte relevante.

Remover quando zero consumidor:

- `stancl/tenancy` e `config/tenancy.php`;
- `Tenant` e traits stancl;
- `EnsureTenantContext`;
- `TenantSelection` e exceptions;
- `memberships` e `MembershipAuthorizer`;
- `X-Tenant`;
- `AuthenticateSupabaseUser` e `SupabaseAuth` do runtime;
- provider `TenancyServiceProvider`;
- testes que congelam o comportamento removido, substituindo-os por testes da regra nova;
- `supabase_source` somente após não haver mais contrato/migração que o consuma.

Não apagar artefatos de transição ainda usados para validar concordância contra o Supabase.

## Fase 8 — frontend React

No `sislacprivado`:

- substituir Supabase Auth pelo fluxo Sanctum;
- inicializar CSRF via `/sanctum/csrf-cookie`;
- enviar cookies/credentials conforme documentação;
- remover `X-Tenant`/seletores de tenant inexistentes;
- manter `unidadeAtiva` porque unidade pertence ao domínio do laboratório, não à seleção de banco;
- cortar Supabase módulo por módulo, nunca com fallback silencioso.

## Fase 9 — limpeza final

Quando todos os módulos tiverem cutover:

- remover Supabase Auth/Data API/Edge Functions usados apenas como backend do SISLAC;
- remover SDK Supabase dos fluxos sem consumidor;
- remover tabelas/infraestrutura central antigas comprovadamente órfãs (`memberships`, `provisioning_runs`, `platform_audit`, `plans`, `subscriptions` quando aplicável);
- atualizar AGENTS/README/ARCHITECTURE/DEPLOY/SEGURANCA;
- manter histórico de migrations necessário para instalações existentes; não apagar migrations aplicadas em produção por estética.

## Verificação obrigatória por checkpoint

Em cada fase relevante:

```bash
composer validate --strict
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
```

Executar também todos os `scripts/check-*.sh` ainda aplicáveis.

A conclusão só pode ser declarada quando, no mesmo SHA:

- CI estiver verde;
- isolamento entre dois laboratórios em bancos/hosts distintos estiver coberto;
- autenticação Sanctum estiver coberta;
- laboratório suspenso estiver bloqueado;
- Super Admin não receber acesso clínico implícito;
- nenhum segredo aparecer em response/log/test fixture;
- buscas confirmarem zero consumidor para cada componente removido;
- frontend e backend concordarem no contrato ativo.

## Ordem de commits

Preferir commits pequenos nesta ordem:

1. `docs: aprova arquitetura final enxuta`
2. `test: define contrato user laboratory`
3. `feat: adiciona laboratory e conexão lab`
4. `test: cobre isolamento da conexão lab`
5. `feat: habilita sanctum para spa`
6. `refactor: move autorização para user`
7. `refactor: corta rotas clínicas para conexão lab`
8. `refactor: simplifica provisionamento de laboratórios`
9. `refactor: remove tenancy legado sem consumidores`
10. `docs: atualiza arquitetura e operação final`

A lista é uma ordem lógica; commits podem ser agrupados quando a atomicidade exigir, mas nunca misturar mudança de comportamento não relacionada.
