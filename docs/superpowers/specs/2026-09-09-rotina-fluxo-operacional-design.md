# Rotina / Fluxo Operacional Laravel — Design aprovado

## Objetivo

Migrar para o backend Laravel definitivo a máquina de estados operacional que governa **Registrar Coleta** e **Analisar Amostras**, preservando o comportamento atualmente observado no `sislacprivado` e no PostgreSQL/Supabase ativo, sem antecipar Resultados, Estoque, integrações laboratoriais, Financeiro, Convênios ou Caixa.

A onda deve permanecer enxuta: Rotina estende o agregado já existente de Atendimentos e reutiliza `atendimento_exames`. Não será criado um segundo domínio paralelo para coleta/análise.

## Base e fontes de verdade

- Backend base: `feat/atendimentos-laravel`, SHA `900a0756b5ccabdcda2aba7608cb93d31f63e9e3`.
- Frontend de referência: `DevCactusTecnologia/sislacprivado`, SHA `57cc9be96703a41b207d530088369da1cc23cd94`.
- Durante a transição, o frontend atual e o Supabase/PostgreSQL ativo são baseline de comportamento.
- Laravel/PostgreSQL tenant é o backend definitivo desta funcionalidade.
- Nenhuma escrita ou DDL será feita no Supabase nesta onda.
- Autenticação clínica continua em Supabase Auth: Bearer → `supabase.auth` → tenant → autorização Laravel.

## Decisão arquitetural

A regra operacional ficará dividida em duas responsabilidades explícitas:

1. **Laravel expressa intenção de negócio** por ações como coletar, iniciar análise, finalizar análise, recoletar e cancelar.
2. **PostgreSQL tenant é a autoridade final da transição de status**, impedindo saltos inválidos mesmo que exista escrita fora do controller HTTP.

Não será exposto um CRUD genérico de `status` para o cliente. O cliente não poderá escolher livremente o próximo estado nem fornecer timestamps clínicos arbitrários.

A implementação deve reproduzir somente as invariantes de Rotina hoje observadas no Supabase. Triggers de estoque, snapshots regulatórios, integração com laboratório de apoio, normalização de resultados e demais efeitos de ondas futuras ficam fora do escopo.

## Modos de fluxo

O sistema possui exatamente três modos:

### `completo`

Fluxo operacional:

`pendente → coletado → em_bancada → analisado → Resultados`

- Coleta disponível.
- Análise interna disponível.
- Não é permitido saltar de `pendente` direto para análise/resultado.
- Não é permitido saltar de `coletado`/`em_bancada` direto para etapas posteriores de Resultado sem concluir análise.

### `coleta_resultado`

Fluxo operacional:

`pendente → coletado → analisado → Resultados`

- Coleta disponível.
- Etapa de análise interna indisponível.
- A intenção de coletar grava a coleta e o PostgreSQL promove o estado para `analisado` na mesma operação.
- `data_analise` é preenchida automaticamente.
- `analista` recebe `__SEM_REGISTRO__` quando não houver analista real.

### `apenas_resultado`

Fluxo operacional:

`analisado → Resultados`

- Coleta indisponível.
- Análise interna indisponível.
- Novo exame interno que normalmente nasceria `pendente` deve entrar operacionalmente como `analisado`.
- `data_coleta` e `data_analise` são preenchidas automaticamente.
- `coletor` e `analista` recebem `__SEM_REGISTRO__` quando não houver registro real.

O marcador `__SEM_REGISTRO__` é dado técnico de rastreabilidade e não deve ser substituído por nome fictício de usuário.

## Exames terceirizados

Exames com `tipo_processo = 'TERCEIRIZADO'` continuam seguindo o comportamento já definido em Atendimentos: podem nascer em `digitado` e não participam da bancada de análise interna.

Esta onda não cria lógica de envio, retorno, integração, status externo ou laboratório de apoio. A Rotina não deve regredir nem reinterpretar o ciclo específico desses exames.

## Configuração tenant

Criar no banco de cada laboratório uma configuração mínima `lab_config`, sem espelhar todas as configurações atuais do Supabase.

Nesta onda, o único dado obrigatório é:

- `rotina_fluxo_modo`: `completo | coleta_resultado | apenas_resultado`, default `completo`.

A configuração será singleton por tenant. O schema deve impedir múltiplas configurações ativas para o mesmo banco do laboratório.

Não portar agora:

- `rotina_coleta_analise_enabled` legado;
- `rastreabilidade_resultado_habilitada`;
- demais preferências sem consumidor Laravel nesta onda.

O boolean legado continuará sendo responsabilidade do frontend atual até o cutover correspondente.

## Mudança de modo com exames em andamento

Alterar `rotina_fluxo_modo` deve ser uma operação transacional. Não é aceitável apenas trocar a configuração e deixar ocorrências em estados pertencentes a etapas que acabaram de ser desativadas.

Ao **encurtar** o fluxo, somente exames `INTERNO` não terminais são normalizados:

### destino `coleta_resultado`

- `pendente` permanece `pendente`, porque a coleta continua ativa;
- `coletado` e `em_bancada` são promovidos para `analisado`;
- `data_analise` recebe horário server-side quando ausente;
- `analista` recebe `__SEM_REGISTRO__` quando não existir registro real.

### destino `apenas_resultado`

- `pendente`, `coletado` e `em_bancada` são promovidos para `analisado`;
- `data_coleta` e `data_analise` recebem horário server-side quando ausentes;
- `coletor` e `analista` recebem `__SEM_REGISTRO__` somente quando não existir registro real.

Ao **alongar** o fluxo, não existe regressão automática: exames já `analisado`, `digitado`, `finalizado` ou `cancelado` permanecem como estão.

Exames `TERCEIRIZADO`, `finalizado` e `cancelado` nunca são reclassificados pela troca de modo.

Configuração e normalização precisam confirmar ou fazer rollback juntas. A recomputação de `status_atendimento` e a auditoria já existentes devem refletir as mudanças sem segundo cálculo em PHP.

## Máquina de estados no PostgreSQL

O banco tenant deve proteger as seguintes invariantes:

### Regras gerais

- atualização sem mudança de status é permitida pelas demais regras do agregado;
- `cancelado` é estado alcançável por ação de cancelamento autorizada;
- exame `finalizado` não pode voltar para estados anteriores sem retificação válida; a retificação completa pertence à onda Resultados, portanto esta onda não expõe uma ação genérica para reabrir `finalizado`;
- de `cancelado`, a única retomada operacional suportada nesta onda é uma recoleta válida;
- etapas inexistentes no modo configurado não podem ser materializadas artificialmente.

### Modo `completo`

- `pendente → coletado` permitido;
- `coletado → em_bancada` permitido;
- `em_bancada → analisado` permitido;
- `coletado → analisado` pode ser aceito apenas quando representar a ação explícita de finalizar análise compatível com o comportamento já existente; a API preferirá as intenções `iniciar_analise` e `finalizar_analise`;
- saltos para Resultado antes da análise permanecem bloqueados.

### Modo `coleta_resultado`

- intenção `coletar` parte de `pendente`;
- o banco promove o novo estado efetivo para `analisado`;
- `data_coleta` e `data_analise` são server-side;
- `analista = '__SEM_REGISTRO__'` quando não houver analista real;
- `em_bancada` não pode ser criado nesse modo.

### Modo `apenas_resultado`

- novo exame interno equivalente a `pendente` sofre short-circuit de criação para `analisado`;
- `pendente`, `coletado` e `em_bancada` não podem ser criados por transição normal;
- `data_coleta`, `data_analise`, `coletor` e `analista` recebem os valores técnicos necessários para registrar que essas etapas não ocorreram formalmente.

## Recoleta

Recoleta é uma intenção de negócio, não uma edição livre de status.

- Em `completo` ou `coleta_resultado`, recoleta retorna o exame para `pendente` e limpa `data_coleta`/`data_analise` que pertencem ao ciclo descartado, preservando a identidade da ocorrência e sem gerar nova cobrança.
- Em `apenas_resultado`, não existe fila de coleta; portanto uma solicitação de recoleta não pode deixar o exame preso em `pendente`. O estado operacional de retorno permanece `analisado`, compatível com a regra atual de `statusRecoleta()` do frontend.
- Recoleta de exame `finalizado` continua dependente da política de retificação/justificativa da onda Resultados e não será liberada genericamente por esta fase.

## Cancelamento

Cancelamento usa a permissão já existente `cancelar_atendimento` e deve:

- alterar o exame para `cancelado`;
- exigir `motivo` não vazio;
- preservar histórico de coleta/análise existente; cancelar não deve apagar automaticamente timestamps clínicos anteriores;
- deixar a recomputação do status do atendimento a cargo das invariantes já existentes no agregado Atendimentos.

## Timestamps e responsáveis

O cliente não envia horário oficial de coleta/análise.

Laravel/PostgreSQL gera:

- `data_coleta` ao coletar;
- `data_analise` ao iniciar/finalizar a etapa pertinente;
- os valores técnicos dos modos encurtados.

Quando existir responsável real, a API recebe apenas a intenção/contexto necessário e persiste identidade coerente com o usuário autenticado ou com o responsável escolhido segundo as regras já existentes. A primeira implementação não deve criar um novo cadastro paralelo de analistas/coletadores.

## API

### Configuração

- `GET /api/rotina/config`
- `PATCH /api/rotina/config`

`PATCH` aceita somente `rotina_fluxo_modo` nesta onda e executa, na mesma transação, a normalização de exames internos em andamento descrita acima.

### Filas

- `GET /api/rotina/coleta`
- `GET /api/rotina/analise`

As filas retornam os dados mínimos necessários às telas existentes, derivados de `atendimentos` + `atendimento_exames` + `pacientes` já disponíveis no tenant.

A fila de análise exclui `TERCEIRIZADO`.

Não criar nova tabela materializada de fila e não introduzir cache persistente.

### Transição

- `POST /api/rotina/exames/{id}/transicao`

Payload canônico:

```json
{
  "acao": "coletar | recoletar | iniciar_analise | finalizar_analise | cancelar",
  "motivo": "obrigatório somente para cancelar"
}
```

O cliente não envia `status`, `data_coleta` ou `data_analise` diretamente.

A resposta retorna a ocorrência de `atendimento_exames` já persistida com seu estado efetivo, porque nos modos encurtados o estado final pode ser diferente do estado intermediário solicitado pela UI.

## Sem endpoint batch nesta onda

Não será criado endpoint batch preventivo. O frontend atual já possui concorrência controlada para operações múltiplas.

A API individual deve ser idempotente para repetição segura da mesma intenção quando o estado já refletir o resultado esperado, sempre que isso não mascarar uma transição inválida.

Um batch só será introduzido se medição real demonstrar necessidade ou se o cutover do frontend provar que a atomicidade de lote é requisito funcional.

## Autorização

A cadeia permanece:

1. `supabase.auth` valida Bearer;
2. middleware de tenant resolve membership ativa;
3. autorização Laravel valida a intenção.

Permissões do vocabulário já existente:

- leitura das filas: `visualizar_atendimentos`;
- coletar/recoletar quando a coleta estiver ativa: `registrar_coleta`;
- iniciar/finalizar análise: `analisar_amostra`;
- cancelar: `cancelar_atendimento`;
- ler configuração: usuário autenticado com acesso ao tenant;
- alterar configuração e normalizar o fluxo: `configuracoes_sistema`.

`TenantPermission` receberá casos para permissões que já existem no produto, mas ainda não estavam necessárias no backend Laravel. Isso não cria um novo vocabulário de autorização.

## Auditoria

Reutilizar `atendimento_audit` já criado pelo agregado Atendimentos.

As transições devem registrar o usuário Supabase autenticado nos mesmos `app.audit_*`/contextos já adotados pelo backend, sem criar nova tabela genérica de auditoria.

A auditoria deve permitir distinguir, pelo estado antigo/novo e dados persistidos:

- coleta;
- recoleta;
- início de análise;
- finalização de análise;
- cancelamento;
- short-circuit de modo encurtado;
- normalização decorrente de mudança de modo.

Não duplicar eventos apenas para produzir rótulos cosméticos.

## Consistência com status do atendimento

A função de recomputação de Atendimentos já existente continua sendo a única responsável por derivar `status_atendimento`.

Rotina apenas altera `atendimento_exames`; o trigger/função de recomputação existente deve refletir automaticamente:

- `Pedido Realizado`;
- `Amostra Coletada`;
- `Amostra Analisada`;
- `Resultado Liberado`;
- `Cancelado`.

Não criar segundo cálculo de `status_atendimento` em PHP.

## Concorrência

Cada transição deve ocorrer em uma transação curta e bloquear a ocorrência alvo (`SELECT ... FOR UPDATE` ou mecanismo equivalente) antes de decidir a mudança.

A troca de modo também deve serializar a configuração do tenant antes de normalizar exames, evitando duas alterações de fluxo concorrentes.

Duas requisições concorrentes não podem:

- avançar o mesmo exame duas vezes de forma contraditória;
- sobrescrever timestamps posteriores com valores antigos;
- permitir salto que seria inválido se avaliado contra o estado realmente persistido;
- aplicar normalização baseada em modo já substituído por outra requisição.

Não adicionar Redis, filas, locks distribuídos ou infraestrutura externa.

## Erros HTTP

- `401`: Bearer ausente/inválido.
- `403`: usuário sem tenant/permissão.
- `404`: exame não encontrado no tenant atual.
- `409`: transição de estado incompatível com o estado atual ou com o modo configurado.
- `422`: payload/ação/motivo inválido.

Mensagens devem ser específicas e estáveis o suficiente para a UI mostrar erro operacional sem expor detalhes internos de SQL.

## Testes obrigatórios

### Configuração

- default `completo` em novo tenant;
- aceita somente os três modos;
- alteração exige `configuracoes_sistema`;
- configuração isolada por tenant;
- `completo → coleta_resultado` promove internos `coletado/em_bancada`, preserva `pendente` e não toca terceirizados/terminais;
- `* → apenas_resultado` promove internos `pendente/coletado/em_bancada`, preenche apenas dados técnicos ausentes e não toca terceirizados/terminais;
- alongar o fluxo não regride estados;
- falha na normalização faz rollback também da configuração.

### Modo `completo`

- exame interno nasce `pendente`;
- coletar produz `coletado` + `data_coleta` server-side;
- iniciar análise produz `em_bancada` + `data_analise`;
- finalizar análise produz `analisado`;
- saltos inválidos falham.

### Modo `coleta_resultado`

- exame interno nasce `pendente`;
- coletar termina efetivamente em `analisado`;
- `data_coleta` e `data_analise` são preenchidas;
- `analista = '__SEM_REGISTRO__'` quando aplicável;
- `em_bancada` não é materializado.

### Modo `apenas_resultado`

- novo exame interno short-circuita para `analisado`;
- timestamps técnicos são preenchidos;
- `coletor`/`analista` usam `__SEM_REGISTRO__` quando aplicável;
- não cria `pendente`, `coletado` ou `em_bancada` por fluxo normal.

### Filas

- coleta lista somente ocorrências compatíveis com o modo/estado;
- análise lista somente internos compatíveis;
- terceirizados não aparecem na bancada interna;
- tenant A nunca lê fila do tenant B.

### Recoleta/cancelamento

- recoleta respeita o modo;
- não gera nova cobrança;
- cancelar sem motivo retorna `422`;
- cancelamento preserva timestamps anteriores;
- exame finalizado não é reaberto sem regra de retificação futura.

### Segurança

- sessão Laravel isolada não autentica rota clínica;
- Bearer Supabase é obrigatório;
- `registrar_coleta`, `analisar_amostra`, `cancelar_atendimento` e `configuracoes_sistema` são testadas separadamente;
- `X-Tenant` sozinho nunca autoriza.

### Concorrência

- duas coletas concorrentes na mesma ocorrência produzem um único estado coerente;
- avanço concorrente conflitante falha de forma determinística e não corrompe o histórico;
- mudanças de modo concorrentes não deixam configuração e estados incompatíveis.

## Fora do escopo

- edição do frontend React/Vite;
- cutover das telas atuais;
- Inserir/Salvar/Assinar/Liberar/Retificar Resultado;
- parâmetros e valores de referência;
- rastreabilidade de resultado;
- estoque e consumo de insumos;
- laboratório de apoio, integração externa e status externo;
- POP/snapshot regulatório;
- anexos/PDF/laudos;
- Financeiro, Convênios e Caixa;
- fila persistente, Redis, WebSocket, CQRS, event bus, repositories genéricos ou abstrações preventivas;
- alterações de produção no Supabase.

## Gate de cutover

Concluir esta onda significa **backend da Rotina operacional implementado e validado**, não frontend migrado.

O cutover permanece bloqueado até:

1. Fase 0 ser formalmente encerrada e integrada;
2. Atendimentos estar integrado à base correspondente;
3. Rotina passar por conformance e CI no contexto final;
4. Resultados e Financeiro/Convênios/Caixa cobrirem as dependências necessárias às telas que ainda escrevem no Supabase;
5. frontend ser adaptado em onda própria e validado ponta a ponta.

## Gates de qualidade

No mesmo SHA candidato:

- PostgreSQL 17 executa todas as migrations tenant;
- três modos testados;
- troca de modo não deixa exames internos sem fila;
- transições inválidas protegidas no banco e no HTTP;
- timestamps são server-side;
- permissões separadas testadas;
- auditoria reutilizada e append-only preservada;
- status agregado do atendimento continua derivado pela regra existente;
- concorrência testada;
- provisionamento de novo tenant inclui `lab_config` e invariantes da Rotina;
- nenhum trigger fora do escopo de Rotina é copiado preventivamente;
- Composer Validate/Audit, Pint, Larastan nível 8, Pest e guards ficam verdes;
- nenhuma alteração no Supabase de produção;
- nenhuma alteração no frontend durante esta onda.
