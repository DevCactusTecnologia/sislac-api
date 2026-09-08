# Arquitetura — SISLAC API

A especificação normativa desta migração é `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`. Documentação oficial Laravel 13, PostgreSQL, Supabase e `stancl/tenancy` prevalece sobre comportamento legado.

## Objetivo

O Laravel é o backend definitivo do SISLAC. O frontend React/Vite existente permanece durante a migração progressiva. O Supabase atual continua funcionando como baseline de produção e origem de leitura/concordância até que cada onda tenha equivalência comprovada e o consumidor correspondente seja migrado.

A arquitetura de produção é **database-per-lab**:

```text
Frontend existente
        |
        v
     Laravel
        |
        +--> PostgreSQL central
        |      plataforma / usuários / memberships
        |      Super Admin / provisionamento / auditoria
        |
        +--> PostgreSQL do laboratório selecionado
        |      pacientes / atendimentos / exames / domínio
        |
        +--> Supabase atual (origem de transição/concordância)
```

## Bancos

| Conexão | Finalidade |
|---|---|
| `central` | plataforma, identidade, tenants, memberships, planos, assinaturas, provisionamento e auditoria |
| `tenant_template` | molde PostgreSQL usado pelo `stancl/tenancy` |
| `tenant` | conexão dinâmica ao banco físico do laboratório durante a requisição |
| `supabase_source` | leitura/concordância do banco Supabase atual durante a transição; nunca conexão default |

Cada novo laboratório recebe um banco PostgreSQL físico dedicado. O nome do banco é gerado/controlado pelo backend; o navegador não escolhe identificador físico arbitrário.

## Tenancy

`stancl/tenancy` é a implementação adotada e deve permanecer pequena. Somente `DatabaseTenancyBootstrapper` está ativo. Não usar bootstrappers de cache, filesystem ou queue sem consumidor real.

Fluxo de requisição tenant:

1. usuário autentica no Laravel;
2. memberships ativas determinam os laboratórios permitidos;
3. um laboratório autorizado é selecionado;
4. middleware inicializa o contexto tenant;
5. o domínio opera no banco dedicado;
6. o contexto tenant é encerrado deterministicamente, inclusive em exceções.

`X-Tenant` nunca concede acesso por si só; quando utilizado, apenas seleciona um vínculo já autorizado.

## Provisionamento de novo laboratório

O provisionamento existente é a única trilha de criação de um novo banco:

```text
registro central com status provisioning
  -> advisory lock
  -> CREATE DATABASE
  -> inicializar tenancy
  -> executar database/migrations/tenant
  -> smoke check do banco correto
  -> status active
```

Falha não ativa laboratório parcial. A execução registra estado/auditoria e pode ser repetida de forma idempotente.

`PostgresDatabaseAdmin` valida o nome físico e o usuário HTTP normal não recebe privilégios desnecessários como `SUPERUSER`.

## Super Admin

O **Super Admin é totalmente Laravel e server-rendered**. Ele usa somente o plano central para:

- visualizar laboratórios e status;
- iniciar provisionamento de novo laboratório;
- acompanhar falhas/sucessos de provisionamento;
- administrar operações globais que realmente pertencem à plataforma.

A fundação atual inclui autenticação/autorização, dashboard, listagem/criação de laboratórios e comando seguro para criar ou promover o primeiro Super Admin. Não criar outro SPA e não adicionar Filament, Livewire ou pacote de RBAC por antecipação.

O layout atual é Blade/HTML puro e não consome `@vite`. Por isso o backend Laravel não mantém `package.json`, `.npmrc`, Vite, Tailwind ou stylesheet compilado próprio nesta fundação. Essa decisão não altera o frontend React/Vite externo já existente. Um pipeline de assets só deve voltar quando houver consumidor Laravel real e teste que justifique a dependência.

## Supabase durante a transição

O Supabase atual não é recriado dentro do banco central. Ele é uma fonte de referência temporária para:

- leitura de dados existentes;
- testes diferenciais/concordância;
- migração de dados por módulo;
- validação antes do corte de cada onda.

A conexão `supabase_source` está implementada como conexão PostgreSQL separada, somente de transição/leitura, nunca default, e os testes exercitam a proteção contra escrita acidental.

Para um servidor Laravel persistente, a conexão PostgreSQL deve seguir as opções suportadas oficialmente pelo Supabase: conexão direta quando a rede permitir ou Supavisor em Session Mode para ambientes IPv4-only. A conexão deve exigir SSL e nunca ser a conexão default.

Nenhuma tabela, RPC, Edge Function, policy ou dado do Supabase é removido apenas porque uma implementação Laravel surgiu. O corte acontece somente depois que implementação, dados e consumidores equivalentes forem comprovados.

## Banco central

O banco central contém somente dados de plataforma:

- `users`;
- `tenants`;
- `memberships`;
- `plans`;
- `subscriptions`;
- `provisioning_runs`;
- `platform_audit`;
- estado mínimo necessário ao Super Admin.

Dados clínicos permanecem nos bancos tenant, nunca duplicados no central.

## Domínio do laboratório

`app/Domain` contém as regras que operam no banco dedicado do laboratório. A primeira onda concluída é Pacientes, com schema tenant e contrato de concordância com o Supabase.

Atendimentos, Coleta, Análise, Resultados, Financeiro e demais módulos entram somente em suas próprias ondas. As PRs anteriores de Atendimentos foram superseded enquanto esta fundação é limpa e consolidada.

## Fronteira Platform ↔ Domain

O CI deve impedir:

- `app/Domain` acessar explicitamente o banco central ou importar `App\Platform`;
- `app/Platform` importar o domínio clínico;
- uma segunda estratégia de tenancy;
- infraestrutura futura sem consumidor real.

A camada HTTP é a única coordenadora entre identidade central e inicialização tenant.

## Infraestrutura

PostgreSQL é obrigatório. Outras infraestruturas só existem com consumidor real.

Redis, Horizon, Reverb, cache distribuído, workers ou WebSockets não fazem parte da fundação atual se nenhum fluxo executável os utilizar. Configuração, container, variável de ambiente ou dependência futura sem consumidor é resíduo e deve ser removida, podendo voltar na onda que efetivamente a exigir.

O `docker-compose.yml` atual é validado por smoke test real no CI: o bootstrap cria `sislac_central`, permite autenticação TCP de `sislac_app` com a senha configurada e confirma que esse papel não recebe privilégios administrativos (`CREATEDB`, `CREATEROLE`, `SUPERUSER`).

## Estratégia por ondas

1. fixar contrato executável do módulo no frontend/Supabase;
2. implementar schema/regra Laravel mínima no banco tenant;
3. executar testes de concordância, autorização e integridade;
4. adaptar o consumidor;
5. validar dados e operação;
6. só então cortar o objeto Supabase substituído, quando aplicável.

Não avançar uma nova onda com a anterior incompleta ou com CI vermelho.

## Gates

Toda mudança deve passar no mesmo SHA:

- `composer validate --strict`;
- `composer audit --locked`;
- Pint;
- Larastan nível 8;
- Pest em PostgreSQL real;
- contrato Supabase ↔ Laravel;
- guards de fronteira, banco, arquivos, `.env` e escopo arquitetural;
- migrations centrais e tenant reproduzíveis;
- provisionamento/smoke test quando a mudança tocar tenancy;
- bootstrap real do PostgreSQL via `docker-compose.yml`.

## Estado atual

- fundação Laravel/PostgreSQL: concluída no código;
- banco central: concluído;
- database-per-lab/provisionamento: concluído e testado;
- Pacientes: migrado;
- conexão `supabase_source`: implementada e testada em modo de leitura;
- Super Admin Laravel: fundação implementada e testada;
- limpeza de scaffold/infraestrutura/pipeline frontend sem consumidor: concluída no código e protegida por guards;
- Atendimentos: pausado até esta fundação passar todos os gates no mesmo SHA e ser integrada em `main`.
