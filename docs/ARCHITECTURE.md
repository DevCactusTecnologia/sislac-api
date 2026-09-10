# Arquitetura — SISLAC API

A especificação normativa desta migração é `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`, complementada pela Fase 0 aprovada em `docs/superpowers/specs/2026-09-08-fase-0-saneamento-fundacao-design.md`. A onda de Rotina / Fluxo Operacional é especificada em `docs/superpowers/specs/2026-09-09-rotina-fluxo-operacional-design.md`. O contrato da subfase Financeiro Core está em `docs/contracts/financeiro-core.json` e sua descrição em `docs/financeiro-core.md`. Documentação oficial Laravel 13, PostgreSQL, Supabase e `stancl/tenancy` prevalece sobre comportamento legado.

## Objetivo

O Laravel é o backend definitivo do SISLAC. O frontend React/Vite existente permanece durante a migração progressiva. O Supabase atual continua funcionando como baseline de produção, fonte de autenticação clínica transitória e origem de leitura/concordância até que cada onda tenha equivalência comprovada e o consumidor correspondente seja migrado.

A arquitetura final é **database-per-lab**:

```text
Frontend existente
  | Bearer Supabase
  v
Laravel
  |-- valida identidade no Supabase Auth
  |-- PostgreSQL central: users / tenants / memberships / provisionamento
  |-- PostgreSQL físico do laboratório: domínio clínico
  `-- Supabase PostgreSQL read-only: concordância/migração
```

## Bancos

| Conexão | Finalidade |
|---|---|
| `central` | usuários correlacionados, tenants, memberships, Super Admin, provisionamento e auditoria de plataforma |
| `tenant_template` | molde PostgreSQL usado pelo `stancl/tenancy` |
| `tenant` | conexão dinâmica ao banco físico do laboratório durante a requisição |
| `supabase_source` | leitura/concordância do Supabase durante a transição; nunca conexão default |

Cada novo laboratório recebe um banco PostgreSQL físico dedicado. O nome do banco é gerado/controlado pelo backend; o navegador não escolhe identificador físico arbitrário.

## Identidade e tenancy

Durante a transição existem dois contextos explicitamente separados:

- **usuário clínico:** autentica no Supabase Auth; a API recebe Bearer token, valida server-side em `/auth/v1/user`, exige que o UUID já exista em `central.users` e só então aplica memberships/permissões Laravel;
- **Super Admin:** autenticação web Laravel própria, restrita ao plano central.

Token válido não cria automaticamente usuário, membership, tenant ou permissão. Dados de autorização não são aceitos de `user_metadata` do token.

Depois da identidade clínica validada:

1. memberships ativas determinam os laboratórios permitidos;
2. um laboratório autorizado é selecionado;
3. middleware inicializa o contexto tenant;
4. o domínio opera no banco dedicado;
5. o contexto tenant é encerrado deterministicamente, inclusive em exceções.

`X-Tenant` nunca concede acesso por si só; apenas seleciona um vínculo já autorizado.

`stancl/tenancy` permanece pequeno: somente `DatabaseTenancyBootstrapper` é necessário. Não adicionar bootstrappers de cache, filesystem ou queue sem consumidor real.

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

Falha não ativa laboratório parcial. O usuário HTTP normal não recebe privilégios administrativos; a credencial de provisionamento é separada.

## Super Admin

O Super Admin é Laravel e server-rendered. Ele usa somente o plano central para visualizar laboratórios/status, iniciar provisionamento e executar operações globais que realmente pertencem à plataforma. Não criar outro SPA ou pacote administrativo preventivo.

## Supabase durante a transição

O Supabase não é recriado dentro do banco central. Ele possui dois papéis transitórios:

1. **Auth clínico:** validação server-side do Bearer token atual;
2. **origem de concordância:** leitura PostgreSQL para validar/migrar módulos.

A conexão `supabase_source` possui defesa em profundidade:

- nunca é a conexão default;
- `SupabaseSource` executa `SET default_transaction_read_only = on`;
- o projeto Supabase atual possui `supabase_read_only_user` com `default_transaction_read_only=on`, SELECT em `public.pacientes` e sem INSERT/UPDATE/DELETE/CREATE DATABASE; essa é a credencial recomendada para `SUPABASE_DB_USERNAME`;
- o gate live falha se a sessão não estiver read-only.

Nenhuma tabela, RPC, Edge Function, policy ou dado do Supabase é removido apenas porque uma implementação Laravel surgiu.

## Banco central mínimo

Novas instalações criam somente o estado de plataforma necessário hoje:

- `users`;
- `tenants`;
- `memberships`;
- `provisioning_runs`;
- `platform_audit`;
- tabelas efetivamente necessárias a cache/sessão do Laravel.

`plans` e `subscriptions` foram removidos da baseline por não possuírem consumidor runtime. Em banco central já existente, `platform:audit-unused-tables` apenas informa presença/contagem; não executa `DROP`.

Dados clínicos permanecem nos bancos tenant, nunca duplicados no central.

## Domínio do laboratório

`app/Domain` contém as regras que operam no banco dedicado do laboratório. Pacientes é a primeira onda concluída. Atendimentos, Rotina / Fluxo Operacional e Financeiro Core estão implementados em branches encadeadas e permanecem isolados do `main` e do cutover do frontend até que as ondas dependentes e seus gates sejam concluídos.

### Atendimentos

O agregado de Atendimentos vive somente no banco tenant e é composto por `atendimentos`, `atendimento_exames`, `atendimento_pagamentos` e `atendimento_audit`. Protocolo, idempotência, campos derivados e auditoria são protegidos no PostgreSQL; criação e edição são transacionais.

O fluxo HTTP clínico é sempre:

```text
Bearer Supabase
  -> supabase.auth
  -> membership/tenant autorizado
  -> tenant.permission ou autorização específica do PATCH
  -> domínio no banco físico do laboratório
```

Não existe autenticação clínica Laravel/Sanctum para esse módulo. Não existe `DELETE /api/atendimentos`; cancelamento é evento de negócio auditado. A disponibilidade do backend não autoriza o cutover do React/Vite.

### Rotina / Fluxo Operacional

A Rotina não cria um segundo agregado clínico. Ela opera diretamente sobre `atendimento_exames`, preservando Atendimentos como fonte de verdade e reutilizando `atendimento_audit`.

O laboratório possui uma configuração singleton `lab_config.rotina_fluxo_modo` com três valores canônicos:

- `completo`: coleta → bancada → analisado;
- `coleta_resultado`: coleta encurta o fluxo e persiste o estado efetivo compatível sem materializar bancada;
- `apenas_resultado`: etapas de coleta/bancada não são materializadas e o estado é normalizado para análise.

A autoridade de integridade permanece no PostgreSQL. Triggers tenant impedem estados incompatíveis com o modo, protegem terminais e aplicam os short-circuits aprovados. O Laravel não mantém uma segunda máquina de estados: ele recebe uma **intenção operacional**, bloqueia a ocorrência com `SELECT ... FOR UPDATE`, valida precondições de intenção que não podem ser inferidas apenas pelo estado efetivo, gera timestamps/responsáveis server-side e deixa o banco validar a transição final.

As intenções HTTP são `coletar`, `recoletar`, `iniciar_analise`, `finalizar_analise` e `cancelar`. Repetição idempotente só é sucesso quando o estado persistido já representa exatamente o resultado da mesma intenção; por exemplo, duas coletas concorrentes não reescrevem timestamp/responsável nem duplicam auditoria. `finalizar_analise` exige que a intenção de início já tenha produzido `em_bancada`, evitando que uma intenção stale salte a operação depois de outra transição concorrente.

As rotas são:

```text
GET   /api/rotina/config
PATCH /api/rotina/config
GET   /api/rotina/coleta
GET   /api/rotina/analise
POST  /api/rotina/exames/{id}/transicao
```

As filas de Coleta e Análise são **consultas derivadas** de `atendimentos` + `atendimento_exames`; não existe tabela de fila, batch endpoint, worker, Redis ou infraestrutura assíncrona. Etapa desativada pelo modo retorna `200` com coleção vazia e `enabled: false`. Exames `TERCEIRIZADO` ficam fora das filas/transições internas.

A troca de modo é transacional: bloqueia `lab_config`, persiste a configuração e normaliza de forma set-based apenas ocorrências internas afetadas, preservando dados clínicos reais e fazendo rollback integral em caso de falha. Mudanças concorrentes de modo são serializadas pelo banco.

A cadeia de segurança continua sendo Bearer Supabase → usuário central já correlacionado → membership ativa → tenant autorizado → permissão específica. A onda adiciona `registrar_coleta`, `analisar_amostra` e `configuracoes_sistema`; não existe auto-provisionamento por token nem autorização a partir de metadata do Supabase.

Esta implementação de Rotina não inclui o frontend `sislacprivado`, cutover, resultados/PDF, estoque, laboratório de apoio, Financeiro/Convênios/Caixa, Redis, WebSocket, CQRS ou event bus. Essas responsabilidades permanecem em ondas próprias.

### Financeiro Core — pacientes

O Financeiro Core não cria um livro paralelo de entradas. A cobrança do paciente continua derivada do agregado de Atendimentos:

```text
valor devido = exames ativos cobrados do paciente
valor pago   = pagamentos não estornados
saldo        = valor devido - valor pago
```

Exame `cancelado` e exame com `cobranca_destino = 'convenio'` não compõem dívida do paciente. Essa regra é aplicada tanto nas consultas quanto na proteção contra sobrepagamento.

As rotas são:

```text
GET  /api/financeiro/a-receber/pacientes
GET  /api/financeiro/recebimentos/pacientes
POST /api/financeiro/atendimentos/{id}/pagamentos
POST /api/financeiro/pagamentos/{id}/estorno
```

A Receber e Recebimentos são consultas derivadas. A primeira preserva o contrato útil de `financeiro_a_receber_v2`; a segunda replica a parcela de pacientes da view live `financeiro_entradas`. O Supabase permanece somente como baseline read-only nessa subfase.

Registro de pagamento exige `registrar_pagamento`, bloqueia o atendimento com `FOR UPDATE`, recalcula o saldo dentro da mesma transação e cria sempre uma nova linha. O PostgreSQL repete a invariante crítica em trigger, impedindo sobrepagamento mesmo fora da API.

Estorno exige `gestao_financeira`, bloqueia o pagamento com `FOR UPDATE`, preserva a linha original, altera somente `status_pagamento` para `estornado` e cria uma linha única em `financeiro_estornos`. O livro de estornos é append-only. A recepção mantém `registrar_pagamento`, mas não recebe `gestao_financeira` implicitamente.

Pagamento efetivo passa a ser histórico: campos de negócio são imutáveis, `DELETE` físico é rejeitado e a única atualização permitida é a transição irreversível para `estornado`. Por consequência, `PATCH /api/atendimentos/{id}` exige que o campo `pagamentos` esteja ausente e `UpdateAtendimento` não substitui mais pagamentos.

As funções de trigger novas usam `SECURITY INVOKER`, `search_path = ''` e nomes schema-qualified. Não há `SECURITY DEFINER`, Redis, fila, event bus ou novo serviço para esta subfase.

A divergência conhecida do baseline está documentada em `docs/financeiro-core.md`: `financeiro_a_receber_v2` live ainda soma exame cancelado cobrado do paciente, enquanto `recompute_atendimento_completo` já o exclui. O Laravel segue a invariante canônica do recompute e não replica o defeito.

Convênios/faturas, Saídas, Caixa, resumo financeiro e cutover do frontend continuam fora do Financeiro Core.

## Fronteira Platform ↔ Domain

O CI impede:

- `app/Domain` acessar explicitamente o banco central ou importar `App\Platform`;
- `app/Platform` importar domínio clínico;
- uma segunda estratégia de tenancy;
- infraestrutura futura sem consumidor real.

A camada HTTP é a coordenadora entre identidade central e inicialização tenant.

## Infraestrutura

PostgreSQL é obrigatório. Cache/sessão persistentes existem porque têm consumidor atual. A fila permanece `sync`, sem migration `jobs`, worker, Redis ou Horizon enquanto nenhum fluxo runtime exigir execução assíncrona.

Redis, Reverb, cache distribuído, workers, WebSockets ou outros serviços só entram na onda que apresentar consumidor e testes concretos.

## Conformidade em duas camadas

1. **Manifesto offline:** fixa a evidência versionada e valida formato/hash/invariantes no CI, sem acesso a produção.
2. **Live:** `php artisan contract:supabase-live` consulta exclusivamente em modo read-only os módulos já migrados e detecta drift real.

O termo “conforme” só é usado para um módulo após o gate live correspondente. Um manifesto íntegro sozinho não significa conformidade atual.

## Estratégia por ondas

1. fixar contrato executável atual do módulo;
2. escrever testes;
3. implementar schema/regra Laravel mínima;
4. executar conformidade live e testes de autorização/integridade;
5. adaptar o consumidor;
6. cortar a origem anterior somente depois da prova de equivalência.

Não avançar uma nova onda com a anterior incompleta ou com CI vermelho.

## Gates

Toda mudança deve passar no mesmo SHA:

- `composer validate --strict`;
- `composer audit --locked`;
- Pint;
- Larastan nível 8;
- Pest em PostgreSQL real;
- integridade offline do manifesto;
- guards de fronteira, banco, arquivos, `.env` e escopo arquitetural;
- migrations centrais e tenant reproduzíveis;
- provisionamento/smoke test quando a mudança tocar tenancy.

Antes de declarar uma onda conforme, executar também o gate live em ambiente confiável com credencial PostgreSQL read-only quando existir contrato live aplicável à onda.
