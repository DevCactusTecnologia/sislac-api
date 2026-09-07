# Remediação arquitetural — Laravel somente como backend de integração do Supabase

**Status:** normativa e substitutiva da arquitetura multi-database anterior.

**Objetivo único:** o `sislac-api` é um backend Laravel server-side que integra o frontend SISLAC ao Supabase e a provedores externos. Ele **não substitui** o Supabase, não replica o domínio clínico em banco Laravel e não cria um segundo sistema de autenticação, autorização ou tenancy.

## 1. Fonte de verdade

O Supabase permanece fonte de verdade para:

- PostgreSQL e schema de negócio;
- histórico de migrations do SISLAC;
- Supabase Auth e sessões;
- Row Level Security;
- Storage;
- Realtime;
- RPCs PostgreSQL existentes enquanto forem necessários;
- dados clínicos, financeiros e operacionais.

`supabase/migrations/**` no repositório do frontend continua sendo a única história normativa do schema SISLAC. O Laravel não cria migrations equivalentes de `pacientes`, `atendimentos`, `exames`, financeiro ou qualquer outra tabela do produto.

## 2. Responsabilidade do Laravel

Laravel existe somente para operações que realmente exigem ambiente server-side, como:

- integração com provedores externos;
- uso de segredos que jamais podem chegar ao navegador;
- orquestração de operações privilegiadas;
- webhooks;
- jobs realmente necessários e demonstrados por um fluxo concreto;
- geração/processamento server-side quando não couber no Supabase/Edge Function existente.

CRUD comum protegido por RLS continua diretamente entre frontend e Supabase. Laravel não vira proxy universal do Data API.

## 3. Fluxos permitidos

### 3.1 Operação no contexto do usuário

```text
React/Vite
  -> Authorization: Bearer <Supabase user JWT>
Laravel
  -> valida usuário no Supabase Auth
  -> Data API/RPC com:
       apikey: <publishable key>
       Authorization: Bearer <mesmo user JWT>
Supabase
  -> role authenticated
  -> RLS aplicada normalmente
```

Laravel nunca converte silenciosamente uma operação user-scoped em secret/service role.

### 3.2 Operação privilegiada

```text
React/Vite
  -> user JWT
Laravel
  -> valida identidade no Supabase Auth
  -> valida a permissão necessária em dados/RPC protegidos do Supabase
  -> somente então usa secret key server-side para a operação específica
Supabase
  -> service_role / BYPASSRLS apenas nessa etapa controlada
```

A secret key fica somente no host Laravel. Nunca é exposta ao frontend, log, resposta HTTP, repositório ou CI em texto plano.

### 3.3 Serviço para serviço

Webhooks, cron e workers sem usuário usam autenticação interna específica e mínima. Eles não simulam usuário e não reutilizam credencial de navegador.

## 4. Autenticação

Supabase Auth é o único sistema de identidade do SISLAC.

Remover do `sislac-api`:

- `laravel/sanctum`;
- login Laravel próprio;
- logout Laravel próprio;
- sessão/cookie/CSRF como mecanismo de identidade SISLAC;
- tabela Laravel `users` usada como identidade paralela;
- qualquer token Laravel substituto do JWT Supabase.

A fundação Laravel valida o Bearer JWT recebido do frontend contra Supabase Auth. O frontend continua responsável pelo login, refresh e logout através do Supabase Auth já existente.

## 5. Tenancy

O SISLAC atual é single-tenant na arquitetura Supabase adotada neste projeto. Laravel não adiciona outra camada de tenancy.

Remover:

- `stancl/tenancy`;
- banco central;
- banco por laboratório;
- `X-Tenant`;
- `memberships` Laravel para seleção de banco;
- `tenant_template` / conexão dinâmica `tenant`;
- provisionamento PostgreSQL de tenant;
- `Tenant`, `TenantSelection`, `TenantProvisioner` e middlewares equivalentes;
- migrations `database/migrations/central/**` e `database/migrations/tenant/**` que duplicam o produto.

Não substituir isso por outro framework de tenancy.

## 6. Domínio duplicado

O módulo Laravel de Pacientes foi criado durante a direção arquitetural anterior. Ele não deve continuar como segunda implementação persistente do domínio.

Remover a persistência Laravel duplicada de Pacientes:

- migration tenant `pacientes`;
- Model Eloquent `Paciente`;
- Actions/Queries destinadas a persistir/consultar a cópia Laravel;
- controllers/resources/requests cujo único objetivo é operar essa cópia;
- testes de isolamento/performance do banco tenant que deixarem de fazer sentido.

Preservar somente regras/normalizações/testes que tenham valor real como contrato e possam ser usados sem duplicar persistência. Qualquer reaproveitamento deve ter consumidor concreto na integração Supabase; caso contrário, remover.

Atendimentos não entra nesta remediação. As PRs antigas de Atendimentos permanecem fechadas/superseded e não serão reaproveitadas por cherry-pick.

## 7. Cliente Supabase Laravel

Implementar uma única fronteira pequena, por exemplo `App\Services\Supabase\SupabaseClient`, usando exclusivamente o Laravel HTTP Client.

Responsabilidades permitidas:

- `getUser(string $jwt)`;
- construir request user-scoped com publishable key + JWT;
- construir request admin com secret key no header `apikey`;
- chamadas explícitas à Data API/RPC quando um endpoint Laravel realmente precisar delas.

Não instalar SDK Supabase PHP comunitário enquanto o Laravel HTTP Client cobrir o caso real.

Configuração mínima:

```dotenv
SUPABASE_URL=
SUPABASE_PUBLISHABLE_KEY=
SUPABASE_SECRET_KEY=
```

As novas chaves `sb_publishable_*` e `sb_secret_*` são API keys, não JWTs. Devem ser enviadas no header `apikey`. O header `Authorization: Bearer ...` é reservado ao JWT real do usuário.

## 8. Superfície HTTP inicial

Após a remediação, a fundação Laravel deve expor somente endpoints necessários para provar o contrato:

- `GET /api/health` — saúde do processo Laravel, sem depender de banco Laravel;
- `GET /api/me` — protegido por middleware Supabase, devolvendo identidade mínima necessária.

Não criar endpoint Laravel para Pacientes, Atendimentos ou qualquer CRUD apenas para “padronizar” a API.

O próximo endpoint funcional só entra quando houver uma Edge Function/integração privilegiada específica escolhida para migração.

## 9. Banco, cache, Redis e filas

A fundação não depende de PostgreSQL próprio, Redis, Horizon, Reverb ou banco de sessão.

- Health não consulta banco Laravel.
- Cache default deve ser não persistente/arquivo quando necessário pelo framework.
- Queue default deve ser `sync` enquanto nenhum fluxo concreto exigir processamento assíncrono.
- Redis, Postgres local, pgAdmin e provisionamento Docker associados à arquitetura antiga devem ser removidos quando não houver consumidor remanescente.

Adicionar fila/cache/Redis no futuro exige caso de uso concreto, teste e justificativa na PR correspondente.

## 10. Estrutura permitida

Estrutura inicial esperada:

```text
app/
  Http/
    Controllers/
    Middleware/SupabaseUser.php
  Services/
    Supabase/SupabaseClient.php
  Providers/
routes/api.php
config/services.php
tests/
```

Não criar preventivamente:

- `Domain/` por módulo clínico;
- `Platform/`;
- `Repositories/`;
- `DTOs/`;
- `Actions/` por CRUD;
- `Managers/`;
- `Adapters/` em cascata;
- barramento/event bus/CQRS;
- Models Eloquent espelhando tabelas Supabase.

Uma nova camada exige necessidade concreta e consumidor real.

## 11. Gates anti-desvio

O CI deve falhar se qualquer um destes itens retornar sem aprovação arquitetural explícita:

- dependência `stancl/tenancy`;
- dependência `laravel/sanctum` ou Passport como segundo Auth;
- diretório `app/Platform`;
- infraestrutura de seleção de tenant/banco por laboratório;
- migrations de domínio SISLAC no Laravel;
- Model Eloquent criado apenas para espelhar tabela Supabase;
- rota `/api/auth/login` ou equivalente;
- `X-Tenant`;
- conexão `central`, `tenant` ou `tenant_template`;
- Redis/Horizon/Reverb sem consumidor real;
- segredo Supabase em arquivo versionado.

Gates mantidos:

- Composer lock versionado;
- `composer validate --strict`;
- `composer audit --locked`;
- Pint;
- Larastan nível 8;
- Pest;
- guard de `.env`;
- file-size guard.

Adicionar um novo guard `check-supabase-integration-boundary` para os itens acima.

## 12. Estratégia de remediação

A remediação ocorre em ordem para evitar um estado intermediário confuso:

1. congelar arquitetura antiga e manter PRs de Atendimentos fechadas;
2. adicionar testes/gates que descrevam a fronteira correta;
3. adicionar `SupabaseClient` e middleware `SupabaseUser` com testes;
4. substituir Auth Laravel/Sanctum pelo JWT Supabase;
5. remover rotas e persistência Laravel de Pacientes;
6. remover tenancy/provisionamento/banco central/migrations próprias;
7. remover dependências e infraestrutura sem consumidor;
8. atualizar README, AGENTS, ARCHITECTURE e contratos que ainda indiquem substituição/cutover;
9. rodar Composer Audit, Pint, Larastan, Pest e todos os guards;
10. só após o merge escolher UMA integração/Edge Function de baixo risco para migrar.

Nenhuma etapa altera o schema ou dados de produção do Supabase.

## 13. Documentação oficial normativa

As decisões acima devem ser verificadas na documentação oficial vigente no momento de cada mudança. Fontes iniciais:

- Laravel 13 HTTP Client: https://laravel.com/docs/13.x/http-client
- Supabase Auth: https://supabase.com/docs/guides/auth
- Supabase API keys: https://supabase.com/docs/guides/getting-started/api-keys
- Supabase Authorization headers: https://supabase.com/docs/guides/functions/auth-headers
- Supabase RLS: https://supabase.com/docs/guides/database/postgres/row-level-security
- Supabase changelog: https://supabase.com/changelog

Mudança de comportamento relevante exige nova consulta à documentação/changelog antes da implementação.

## 14. Critério de conclusão

A remediação só está concluída quando:

- existe um único backend Laravel canônico: `DevCactusTecnologia/sislac-api`;
- `sislacprivado-api` não recebe desenvolvimento adicional e pode ser arquivado posteriormente;
- Laravel não possui identidade, banco central, tenant DB ou schema clínico próprio;
- Supabase Auth é a única identidade;
- Supabase continua único schema/dados/RLS/Storage/Realtime;
- Laravel contém apenas fronteira Supabase e infraestrutura necessária a integrações server-side;
- CI bloqueia retorno da arquitetura antiga;
- todos os testes/gates passam no mesmo SHA;
- nenhuma Edge Function é removida antes de existir substituto Laravel validado e consumidor migrado.