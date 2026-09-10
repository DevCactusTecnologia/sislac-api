# Arquitetura — SISLAC API

A especificação normativa desta migração é `docs/superpowers/specs/2026-09-06-laravel-supabase-conformance-design.md`, complementada pela Fase 0 aprovada em `docs/superpowers/specs/2026-09-08-fase-0-saneamento-fundacao-design.md`. A onda de Rotina / Fluxo Operacional é especificada em `docs/superpowers/specs/2026-09-09-rotina-fluxo-operacional-design.md`. O contrato da subfase Financeiro Core está em `docs/contracts/financeiro-core.json` e sua descrição em `docs/financeiro-core.md`. O hardening de totais canônicos está em `docs/contracts/financeiro-totais-atendimento.json` e `docs/financeiro-totais-atendimento.md`. O Caixa Operacional está em `docs/contracts/financeiro-caixa-operacional.json` e `docs/financeiro-caixa-operacional.md`. Saídas e Despesas estão em `docs/contracts/financeiro-saidas-despesas.json` e `docs/financeiro-saidas-despesas.md`. Documentação oficial Laravel 13, PostgreSQL, Supabase e `stancl/tenancy` prevalece sobre comportamento legado.

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

`app/Domain` contém as regras que operam no banco dedicado do laboratório. Pacientes é a primeira onda concluída. Atendimentos, Rotina / Fluxo Operacional, Financeiro Core, Totais Canônicos, Caixa Operacional e Saídas/Despesas estão implementados em branches encadeadas e permanecem isolados do `main` e do cutover do frontend até que as ondas dependentes e seus gates sejam concluídos.

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

Pagamento efetivo passa a ser histórico: campos de negócio são imutáveis, `DELETE` físico é rejeitado e a única atualização permitida é a transição irreversível para `estornado`. Por consequência, `POST /api/atendimentos` e `PATCH /api/atendimentos/{id}` exigem que o campo `pagamentos` esteja ausente; criação e edição de Atendimento não são portas alternativas para mutação financeira.

As funções de trigger novas usam `SECURITY INVOKER`, `search_path = ''` e nomes schema-qualified. Não há `SECURITY DEFINER`, Redis, fila, event bus ou novo serviço para esta subfase.

A divergência conhecida do baseline está documentada em `docs/financeiro-core.md`: `financeiro_a_receber_v2` live ainda soma exame cancelado cobrado do paciente, enquanto `recompute_atendimento_completo` já o exclui. O Laravel segue a invariante canônica do recompute e não replica o defeito.

Convênios/faturas, Saídas, Caixa, resumo financeiro e cutover do frontend continuam fora do Financeiro Core.

### Financeiro — Totais Canônicos do Atendimento

`subtotal`, `desconto_total`, `acrescimo_total` e `total` são valores derivados do agregado de Atendimentos e pertencem ao PostgreSQL tenant. O calculador canônico continua sendo exclusivamente `public.recompute_atendimento_completo`; esta subfase não cria cálculo paralelo em PHP.

```text
subtotal        = Σ COALESCE(valor_original, valor) dos exames não cancelados
total           = Σ valor dos exames não cancelados
desconto_total  = MAX(subtotal - total, 0)
acrescimo_total = MAX(total - subtotal, 0)
```

Os quatro campos precisam estar ausentes em `POST /api/atendimentos` e `PATCH /api/atendimentos/{id}`. O cliente lê os totais persistidos por `AtendimentoResource`, mas não os fornece como autoridade.

`atendimento_exames.valor_original` pode ser definido na criação do exame e torna-se imutável depois de não nulo. A proteção PostgreSQL preserva silenciosamente o valor anterior em tentativa posterior de alteração, reproduzindo a semântica verificada no Supabase live. Exames cancelados permanecem fora de subtotal e total.

A migration dessa proteção usa `SECURITY INVOKER`, `search_path = ''` e referências schema-qualified. Nenhuma escrita é realizada no Supabase live para esta subfase.

Convênios/faturas, Saídas, Caixa, resumo financeiro e cutover do frontend permanecem fora deste hardening.

### Financeiro — Caixa Operacional

O Caixa Operacional vive no banco PostgreSQL físico do laboratório e não repete `tenant_id`. `caixa_sessoes` admite no máximo uma sessão aberta por `unidade_id`, com a invariante garantida pelo índice único parcial `uq_caixa_sessao_aberta_por_unidade`.

As rotas são:

```text
GET  /api/financeiro/caixa/aberto
POST /api/financeiro/caixa/abrir
POST /api/financeiro/caixa/{id}/fechar
```

Leitura exige `visualizar_financeiro`; abertura e fechamento exigem `gestao_financeira`. Pagamentos novos de pacientes com `tipo = 'Dinheiro'` ou `tipo = 'PIX'` são vinculados automaticamente à sessão aberta da `unidade_id` do atendimento. Outras formas permanecem fora do Caixa. Se não houver sessão aberta, o pagamento continua válido com `caixa_sessao_id = NULL`.

O fechamento é server-side e transacional. A sessão é bloqueada com `FOR UPDATE` e o PostgreSQL calcula com `numeric`:

```text
saldo_final = valor_abertura + dinheiro + pix - saidas
```

Pagamentos estornados não entram nas entradas. Saídas só são descontadas quando estão pagas, vinculadas e sem estorno de origem `saida`. Uma segunda tentativa de fechamento retorna conflito sem reescrever o histórico.

`financeiro_saidas` existe nesta onda somente como dependência estrutural necessária ao fechamento; nenhum CRUD HTTP de despesas é antecipado. `DELETE` físico tanto de Saída quanto de sessão de Caixa é bloqueado no banco. Fechar uma sessão continua sendo uma atualização legítima de estado, portanto a sessão não é tratada como append-only.

As funções de trigger novas usam `SECURITY INVOKER`, `search_path = ''` e referências schema-qualified. O Supabase live foi consultado apenas em modo read-only para concordância; nenhuma escrita ou DDL foi aplicada nele.

Sangria, suprimento, caixa por operador, múltiplos caixas paralelos na mesma unidade, conciliação, DRE, ERP, Convênios/Faturas, resumo financeiro, frontend cutover e impressão permanecem fora desta subfase.

### Financeiro — Saídas e Despesas

A subfase seguinte ao Caixa promove `financeiro_saidas` de dependência estrutural para módulo operacional mínimo, sem criar livro paralelo ou ampliar o Financeiro para ERP. A autoridade continua no PostgreSQL tenant.

As rotas são:

```text
GET   /api/financeiro/saidas
POST  /api/financeiro/saidas
PATCH /api/financeiro/saidas/{id}
POST  /api/financeiro/saidas/{id}/estorno
```

Leitura exige `visualizar_financeiro`; criação, correção/efetivação e estorno exigem `gestao_financeira`. Não existe endpoint DELETE.

Os estados canônicos são `aberta`, `paga` e `cancelada`. POST pode criar Saída aberta ou paga. PATCH só opera quando o estado atual é `aberta` e permite a transição `aberta -> paga`. Estados `paga` e `cancelada` são terminais para edição. Cancelamento só acontece via estorno formal.

`id`, `protocolo`, `assinatura_protocolo`, `foi_pago`, `caixa_sessao_id`, `created_at` e `updated_at` são server-owned. O PostgreSQL gera o protocolo, deriva `foi_pago`/`data_pagamento`, protege protocolo/assinatura e impede mutação dos campos de negócio em estados terminais.

O estorno bloqueia a Saída com `FOR UPDATE`, cria primeiro a linha append-only em `financeiro_estornos` com `origem_tipo = 'saida'` e somente então altera o estado para `cancelada`, tudo na mesma transação. A ordem satisfaz o trigger que bloqueia cancelamento sem estorno. Segundo estorno retorna conflito e a linha original nunca é apagada.

Saídas pagas em `Dinheiro` ou `PIX` podem ser vinculadas automaticamente ao Caixa elegível. Se uma Saída vinculada for estornada, `caixa_sessao_id` permanece como histórico, mas o fechamento ignora o valor cancelado.

A listagem canônica usa `data DESC, id DESC`, filtros de busca/status/período e cursor composto `(data, id)`, com limite máximo 100. A busca cobre protocolo, descrição, tipo de despesa e destino do pagamento.

A migration `2026_09_10_000800_harden_financeiro_saidas.php` mantém `valor > 0`, proteção de estados, vínculo de Caixa e timestamps no banco. Suas novas funções usam `SECURITY INVOKER`, `search_path = ''` e referências schema-qualified.

O Supabase live permanece somente como baseline read-only; nenhuma escrita ou DDL foi aplicada nele nesta subfase. `assinatura_protocolo` continua reservada ao servidor, mas a assinatura HMAC não é introduzida nesta onda.

Sangria, suprimento, centro de custo, conciliação, DRE, ERP, Convênios/Faturas, resumo financeiro, frontend cutover, impressão/UI e HMAC de protocolo permanecem fora do escopo.

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
