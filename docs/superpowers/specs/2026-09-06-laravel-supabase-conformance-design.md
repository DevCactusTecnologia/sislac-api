# Design — Conformidade Laravel ↔ Supabase do SISLAC

Data: 2026-09-06

## 1. Objetivo

Construir o backend Laravel do SISLAC como substituto progressivo do backend Supabase atualmente consumido por `DevCactusTecnologia/sislacprivado`, preservando comportamento, contratos, segurança e integridade até que o frontend possa operar sem dependência executável de Supabase.

A migração será incremental e reversível. O Supabase continua sendo a referência comportamental de produção enquanto cada módulo não obtiver evidência de concordância no Laravel.

## 2. Fontes de verdade

Ordem de precedência:

1. Documentação oficial Laravel 13 para comportamento e padrões Laravel.
2. Documentação e changelog oficiais do Supabase para semântica do backend atual.
3. PostgreSQL oficial para semântica de banco de dados.
4. Documentação oficial do pacote `stancl/tenancy` apenas para a integração específica de tenancy.
5. Contrato executável atual do `sislacprivado` e schema real do projeto Supabase `sislac` para requisitos funcionais existentes.
6. Código legado apenas como evidência de comportamento, nunca como justificativa para contrariar documentação oficial ou boas práticas de segurança.

Qualquer divergência entre código atual e documentação deve ser registrada em teste de concordância e resolvida explicitamente. Não será copiado comportamento inseguro apenas para obter igualdade superficial.

## 3. Baseline congelada

### Laravel

Repositório: `DevCactusTecnologia/sislac-api`

Baseline inicial: `08669eaafe43905e34fffc51a892849ad42a3e01`.

Estado inicial:

- Laravel 13 / PHP 8.4.
- PostgreSQL como banco obrigatório.
- Conexões `central` e `tenant` já previstas.
- Arquitetura database-per-tenant: um banco PostgreSQL por laboratório.
- Separação `app/Platform` ↔ `app/Domain` protegida por guard de CI.
- Apenas health check implementado no domínio HTTP.

### Frontend

Repositório: `DevCactusTecnologia/sislacprivado`.

Baseline observada durante o design: `0760c6123f3062842eaff5f7304b6460c00c058d`.

O frontend continua evoluindo durante a construção do Laravel. Por isso o hash acima é uma baseline de início, não um congelamento permanente. Cada etapa de concordância deve registrar o SHA do frontend usado no teste.

### Supabase

Projeto: `sislac` (`eramenhnqcbyctyiqwlm`).

Inventário observado em 2026-09-06:

- PostgreSQL 17.
- 96 tabelas públicas.
- 2 views públicas.
- 6 enums públicos.
- 96/96 tabelas públicas com RLS habilitado.
- 209 funções PostgreSQL no schema `public` quando consideradas todas as sobrecargas e helpers existentes.
- Extensões relevantes observadas: `pg_cron`, `pg_stat_statements`, `pg_trgm`, `pgcrypto`, `uuid-ossp` e `supabase_vault`.

O inventário tipado/consumido pelo frontend pode ser menor que o inventário físico. A migração deve distinguir explicitamente objetos realmente utilizados de objetos internos, auxiliares ou mortos.

## 4. Escopo desta primeira entrega

A migração completa é grande demais para uma única implementação segura. Esta primeira entrega cobre a fundação necessária para todas as demais:

1. Laravel Boost e ambiente de desenvolvimento conforme as instruções do próprio repositório.
2. Dependências mínimas e compatíveis com Laravel 13.
3. Banco central reproduzível por migrations.
4. Tenancy database-per-lab.
5. Autenticação e autorização base.
6. Resolução segura de laboratório.
7. Estrutura de migrations tenant.
8. Harness de concordância Laravel ↔ Supabase.
9. Testes de isolamento, segurança, concorrência, capacidade e performance da fundação.
10. Documentação operacional e critérios de aceite.

Ficam fora desta primeira entrega funcional: PDF, WhatsApp, integrações laboratoriais, Reverb completo, estoque, financeiro, resultados, painel, analytics e demais módulos de domínio. Eles entram em ondas posteriores usando a fundação criada aqui.

## 5. Decisões arquiteturais

### 5.1 Database-per-lab permanece

A arquitetura atual do `sislac-api` será preservada: banco central para identidade/plataforma e um banco PostgreSQL por laboratório.

Motivos:

- isolamento físico mais forte entre laboratórios;
- compatibilidade conceitual com o frontend atual, que já opera como instalação single-tenant por laboratório;
- redução do risco de BOLA/IDOR entre laboratórios;
- possibilidade de migração de um laboratório por vez;
- simplificação da futura restauração, exportação e movimentação de tenants.

O banco tenant não dependerá de `tenant_id` em todas as tabelas para isolamento primário, pois o próprio banco é a fronteira física. Quando colunas históricas `tenant_id` existirem no schema importado, a decisão de removê-las ou preservá-las será feita por módulo após teste de concordância, nunca em massa.

### 5.2 Identidade central e seleção do laboratório

O usuário pertence ao plano central. `memberships` define os laboratórios autorizados.

Regra:

- zero vínculos ativos: acesso negado;
- um vínculo ativo: tenant resolvido automaticamente;
- múltiplos vínculos: o cliente informa `X-Tenant` e a API valida o valor exclusivamente contra `memberships` do usuário autenticado;
- `X-Tenant` nunca concede acesso por si só.

A conexão tenant será inicializada antes de qualquer model/serviço de domínio ser resolvido.

### 5.3 Sanctum

Para o SPA oficial first-party, será usada a autenticação stateful por cookie do Laravel Sanctum, com CSRF e sessão, conforme a documentação Laravel 13.

Bearer tokens serão reservados para clientes que realmente precisem de API tokens, integrações servidor-servidor ou automações, com abilities e expiração explícitas.

Autorização de negócio não dependerá apenas de `tokenCan()`: policies/gates e permissões do usuário continuarão sendo obrigatórios.

### 5.4 Separação Platform ↔ Domain

A regra existente permanece:

- `app/Platform` não importa `App\Domain\*`;
- `app/Domain` não importa `App\Platform\*`;
- somente a camada HTTP/middleware de contexto pode coordenar autenticação central e inicialização tenant.

Essa fronteira continuará verificada automaticamente.

### 5.5 Schema Supabase como contrato, não como cópia cega

O PostgreSQL atual será inventariado e classificado em quatro categorias:

- `required-runtime`: usado diretamente pelo frontend ou por função/trigger necessária;
- `required-compat`: necessário para preservar comportamento durante migração;
- `platform-specific`: objeto ligado ao runtime Supabase e que deve ser substituído no Laravel;
- `dead-or-legacy`: sem consumidor e candidato a remoção após evidência.

Nenhum objeto será removido por aparência de inutilidade. A remoção exige busca de consumidores, dependências PostgreSQL e teste de regressão.

## 6. Banco central

O banco central será criado exclusivamente por migrations Laravel e terá, no mínimo:

- `users`;
- `tenants`;
- `memberships`;
- `plans`;
- `subscriptions`;
- `provisioning_runs`;
- `platform_audit`;
- tabelas do Sanctum/sessão conforme o modo de autenticação adotado.

Requisitos:

- chaves UUID onde a interoperabilidade com identidades existentes justificar;
- FKs explícitas;
- índices em todas as colunas de vínculo e lookup comprovadamente necessárias;
- unicidade de membership por usuário/laboratório;
- soft delete apenas quando a regra de negócio exigir;
- auditoria da plataforma append-only;
- timestamps armazenados em UTC;
- nenhuma credencial de banco tenant em resposta de API ou log.

## 7. Schema tenant

As migrations tenant serão mantidas em `database/migrations/tenant` e executadas por tenant durante provisionamento.

A primeira onda não irá reescrever manualmente as 96 tabelas sem inventário automatizado. O processo será:

1. gerar inventário determinístico do Supabase;
2. mapear tipos, defaults, FKs, índices, checks, enums, views, funções e triggers;
3. identificar dependências específicas de Supabase (`auth.*`, `storage.*`, Realtime, Edge Functions);
4. gerar migrations reproduzíveis para objetos de dados necessários;
5. substituir dependências Supabase por mecanismos Laravel/PostgreSQL equivalentes;
6. executar diff estrutural automatizado entre contrato esperado e tenant Laravel.

## 8. Segurança

### 8.1 Princípios

- deny by default;
- menor privilégio;
- nenhuma confiança no cabeçalho de tenant sem membership;
- nenhuma autorização baseada em metadata editável pelo usuário;
- nenhum segredo em repositório;
- logs sem dados clínicos desnecessários;
- trilhas clínicas/financeiras append-only;
- endpoints públicos separados e rate-limited;
- validação de entrada via Form Requests;
- serialização de saída via API Resources quando houver contrato público.

### 8.2 Débitos observados no Supabase que não serão replicados

A baseline apresenta avisos que devem virar testes/controles no Laravel:

- `create_atendimento_tx` e `update_atendimento_tx` são `SECURITY DEFINER` executáveis por `authenticated`;
- proteção contra senhas vazadas está desativada no Supabase Auth;
- existem políticas RLS que reavaliam helpers de auth por linha;
- existem políticas permissivas duplicadas e índices duplicados/sem uso.

Esses avisos não significam automaticamente vulnerabilidade explorável, mas impedem considerar a baseline Supabase como referência de segurança perfeita. A referência de segurança será a documentação oficial e o comportamento necessário, não o débito existente.

### 8.3 Testes de isolamento obrigatórios

A suíte deve provar, entre outros casos:

- usuário do laboratório A não lê B;
- usuário do A não grava B;
- `X-Tenant` forjado falha;
- membership suspensa falha imediatamente;
- usuário multi-lab só acessa tenant selecionado e autorizado;
- uma exceção durante mudança de contexto não deixa a conexão tenant contaminada para a próxima requisição;
- jobs recebem um tenant explícito e validado;
- cache e storage nunca compartilham namespace tenant inadvertidamente.

## 9. Transações e concorrência

Fluxos críticos serão executados dentro de transações PostgreSQL.

Para operações concorrentes com risco de dupla alteração, serão usados locks pessimistas/constraints/idempotency keys conforme a regra concreta, evitando mutexes globais desnecessários.

`DB::transaction()` será preferido para garantir rollback automático. Retries de deadlock/serialization failure serão limitados e observáveis.

Casos obrigatórios de teste:

- criação concorrente do mesmo recurso idempotente;
- atualização concorrente de atendimento/financeiro;
- rollback integral quando uma etapa falha;
- nenhuma publicação/evento externo antes do commit efetivo.

## 10. Performance e capacidade

Performance será tratada como contrato mensurável, não como impressão visual.

### 10.1 Gates iniciais da fundação

Ambiente de CI com PostgreSQL real e Redis real deve validar:

- ausência de N+1 nos endpoints adicionados à fundação;
- consultas de resolução de tenant usando índice;
- ausência de conexão central dentro do domínio após a resolução;
- ausência de conexão tenant antes da autorização central;
- tempo p95 do middleware de resolução de tenant registrado em benchmark controlado;
- teste concorrente com múltiplos tenants para detectar vazamento de contexto;
- teste de provisionamento repetido/idempotente.

### 10.2 Metas

Metas de negócio de throughput serão definidas por fluxo quando o endpoint correspondente for implementado. Não será inventado um número universal de RPS sem perfil de carga real.

Para a fundação, o critério é regressão relativa: a resolução de tenant e autenticação não podem aumentar de forma não explicada entre commits. O benchmark terá baseline versionada e tolerância explícita antes de virar gate bloqueante.

## 11. Harness de concordância

Será criado um manifesto versionado com o contrato observado no frontend/Supabase:

- tabelas/views consumidas;
- colunas usadas;
- enums;
- RPCs;
- Edge Functions;
- buckets;
- canais Realtime;
- permissões;
- payloads e respostas;
- constraints/comportamentos invisíveis ao frontend;
- regras de timezone, paginação, ordenação e nullability.

Para cada fluxo migrado existirão fixtures sintéticas idênticas e dois adaptadores de teste:

- referência Supabase;
- candidato Laravel.

O teste de concordância compara respostas normalizadas e efeitos persistidos, aceitando somente diferenças documentadas e aprovadas como melhoria de segurança/correção.

## 12. Provisionamento

Provisionar tenant será uma operação explícita, auditada e idempotente:

1. criar registro central em estado `provisioning`;
2. criar banco usando credencial administrativa fora do runtime HTTP;
3. aplicar migrations tenant;
4. aplicar seed mínimo e configuração inicial;
5. rodar smoke tests;
6. marcar tenant `active` somente após sucesso;
7. registrar duração, versão do schema e erro sanitizado em `provisioning_runs`.

Falha não deve produzir tenant parcialmente ativo.

O usuário HTTP da aplicação não terá `CREATEDB`, `CREATEROLE` ou `SUPERUSER`.

## 13. Estratégia de testes

### Unitários

- value objects e resolvers;
- regras de seleção de tenant;
- autorização;
- transformação de contratos;
- idempotência.

### Feature/HTTP

- login/logout/CSRF;
- rotas protegidas;
- resolução de tenant;
- erros 401/403/404/422/429 padronizados;
- serialização.

### Integração PostgreSQL

- central e tenant reais;
- migrations fresh;
- FKs/checks/unique constraints;
- transações/locks;
- rollback;
- isolamento de conexões.

### Segurança

- tenant forgery;
- IDOR/BOLA;
- mass assignment;
- injection;
- privilege escalation;
- rate limit;
- session fixation/revocation;
- vazamento de dados em erros/logs.

### Capacidade e performance

- benchmark de resolução de contexto;
- concorrência multi-tenant;
- provisionamento em lote controlado;
- query-count assertions;
- `EXPLAIN (ANALYZE, BUFFERS)` em consultas críticas quando surgirem endpoints de domínio.

### Qualidade estática

- Pint;
- Larastan/PHPStan nível 8;
- Pest;
- composer audit;
- guards arquiteturais;
- tamanho máximo de arquivos conforme regra do repositório;
- lockfile obrigatório.

## 14. Dependências

Dependências serão adicionadas somente quando houver necessidade concreta.

Primeira onda prevista:

- Laravel Sanctum, usando o instalador oficial do Laravel;
- `stancl/tenancy` em versão com suporte confirmado ao Laravel 13;
- Larastan para análise estática;
- Laravel Boost como dependência de desenvolvimento, conforme `AGENTS.md`.

Antes de instalar `stancl/tenancy`, a versão será verificada contra Laravel 13 e testada no conjunto exato de versões travadas no `composer.lock`.

Filament não é pré-requisito para provar isolamento/auth/tenancy e pode ser adiado até a fundação central estar estável. Isso reduz superfície e evita misturar painel administrativo com o núcleo de segurança.

## 15. Ordem de implementação

1. Preparar ambiente conforme `AGENTS.md` e Laravel Boost.
2. Fixar dependências e gerar lockfile.
3. Criar testes falhando para banco central e autenticação.
4. Criar migrations/models centrais mínimos.
5. Instalar/configurar Sanctum.
6. Criar testes falhando de tenant resolution/forgery.
7. Instalar/configurar tenancy e middleware de contexto.
8. Criar provisionamento mínimo e testes de rollback/idempotência.
9. Criar inventário automatizado do contrato Supabase/frontend.
10. Criar diff estrutural e fixtures de concordância.
11. Rodar gates estáticos, segurança, concorrência e benchmark baseline.
12. Somente então iniciar o primeiro módulo de domínio: Pacientes → Atendimentos → Coleta → Análise → Resultados → Financeiro.

## 16. Critérios de aceite desta primeira entrega

A fundação só será considerada concluída quando houver evidência reproduzível de que:

- `composer.lock` está atualizado e auditado;
- `php artisan migrate:fresh` do central funciona em PostgreSQL;
- um tenant pode ser criado e migrado sem DDL manual;
- um tenant parcialmente provisionado nunca fica ativo;
- Sanctum autentica o SPA first-party de acordo com a documentação Laravel;
- tenant único é resolvido automaticamente;
- múltiplos tenants exigem seleção explícita e validada;
- `X-Tenant` forjado retorna acesso negado;
- testes provam isolamento A/B;
- Pint passa;
- Larastan nível 8 passa para o escopo implementado;
- Pest passa integralmente;
- `composer audit` não apresenta vulnerabilidade conhecida nas dependências travadas;
- testes de concorrência de contexto não mostram vazamento entre tenants;
- benchmark baseline foi registrado;
- manifesto de concordância existe e referencia o SHA do frontend analisado;
- nenhum dado real de paciente foi copiado para teste;
- nenhum segredo foi comitado.

## 17. Definition of Done da migração completa

O objetivo final de 100% de integração será atingido somente quando:

- o `sislacprivado` puder iniciar sem `VITE_SUPABASE_URL` e sem `VITE_SUPABASE_PUBLISHABLE_KEY`;
- o frontend usar apenas a API Laravel para autenticação e dados do SISLAC;
- não houver uso executável de `supabase.from`, `.rpc`, `.functions.invoke`, `.storage` ou `.auth` no fluxo migrado;
- todos os módulos e fluxos públicos tenham equivalente Laravel;
- Storage, Realtime, PDF e integrações tenham substitutos validados;
- os testes de concordância Supabase ↔ Laravel estejam verdes para os fluxos migrados;
- a suíte end-to-end rode com Supabase indisponível e permaneça verde;
- segurança, capacidade e performance tenham gates objetivos e histórico versionado;
- o cutover seja reversível até a janela final de desligamento.

## 18. Não objetivos

- reescrever o frontend inteiro;
- trocar PostgreSQL por MySQL;
- replicar RLS Supabase dentro do Laravel quando isolamento físico e autorização de aplicação já fornecem a fronteira necessária;
- portar todas as 209 funções PostgreSQL para PHP sem evidência de uso;
- introduzir microsserviços prematuramente;
- instalar dependências apenas por conveniência;
- declarar `100%` sem testes diferenciais e end-to-end.
