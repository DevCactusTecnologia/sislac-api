# Financeiro — Saídas / Despesas — Design aprovado

## Objetivo

Migrar para o backend Laravel/PostgreSQL definitivo a operação de Saídas/Despesas do SISLAC, aproveitando a tabela `financeiro_saidas` já criada estruturalmente pela fase Caixa Operacional e substituindo o CRUD direto do frontend por comandos financeiros explícitos, não destrutivos e auditáveis.

A subfase deve permanecer enxuta: cadastrar, consultar, corrigir Saída ainda aberta, efetivar pagamento e estornar. Não será criado livro paralelo, DRE, centro de custo, conciliação bancária, contas a pagar completa, ERP ou novo mecanismo de Caixa.

## Base e fontes de verdade

- Backend base: `fase-financeiro-caixa-operacional`, SHA `8c563881ceb21dcf842b5facd26b75ee4206bce9`.
- Frontend de referência: `DevCactusTecnologia/sislacprivado`, SHA `57cc9be96703a41b207d530088369da1cc23cd94`.
- Supabase/PostgreSQL live: baseline de concordância e leitura durante esta transição.
- Laravel/PostgreSQL tenant: backend definitivo da funcionalidade.
- Nenhuma escrita, DDL ou migration será executada no Supabase live nesta subfase.

O Supabase live consultado em 2026-09-10 possui `financeiro_saidas` vazia. Portanto, não existe base empírica para inventar enums fechados de `tipo_despesa` ou `destino_pagamento`; ambos permanecem texto validado e normalizado.

## Decisão arquitetural

`financeiro_saidas` permanece a única fonte de verdade das despesas operacionais.

O frontend atual ainda trata Saídas como CRUD otimista. O backend novo não reproduzirá `DELETE` físico nem edição retrospectiva irrestrita. O modelo passa a ser orientado a estado:

```text
aberta -> paga -> cancelada
   \--------------> cancelada
```

- `aberta`: lançamento ainda não pago; dados operacionais podem ser corrigidos.
- `paga`: lançamento efetivado; dados financeiros tornam-se históricos e não podem ser reescritos.
- `cancelada`: estado terminal produzido por estorno/cancelamento formal; não volta a `aberta` ou `paga` nesta subfase.

A correção histórica de uma Saída paga é sempre feita por estorno, preservando a linha original e criando `financeiro_estornos` com `origem_tipo = 'saida'`.

## Autoridade de estado

`status` é a fonte exclusiva do estado financeiro da Saída.

`foi_pago` permanece na tabela por compatibilidade de schema, mas não é autoridade de entrada do cliente. O PostgreSQL deve manter a equivalência:

```text
status = 'paga'      => foi_pago = true
status = 'aberta'    => foi_pago = false
status = 'cancelada' => foi_pago = false
```

O cliente não poderá enviar `foi_pago` diretamente.

`data_pagamento` também é controlada pelo backend:

- em `aberta`, deve permanecer `NULL`;
- na transição para `paga`, recebe a data informada e validada quando explicitamente fornecida; na ausência, recebe `CURRENT_DATE` server-side;
- em `cancelada`, o valor histórico de `data_pagamento` não precisa ser apagado para provar que a Saída chegou a ser paga; `foi_pago = false` e `status = 'cancelada'` determinam que não é mais movimento efetivo.

## Protocolo

O protocolo oficial é sempre gerado no PostgreSQL, nunca aceito como autoridade do cliente.

Formato canônico:

```text
SAI-AAAA-0000001
```

O ano vem de `data` da Saída. A geração reutiliza `protocolo_sequence`, já existente no banco tenant, com chave `(prefixo, ano)` e incremento atômico por `INSERT ... ON CONFLICT DO UPDATE`.

O cliente não envia `protocolo` no POST. O banco preenche antes do INSERT e a coluna continua UNIQUE.

Depois da criação:

- `protocolo` é imutável;
- `assinatura_protocolo`, quando não nula, é imutável;
- esta subfase não cria HMAC, segredo de assinatura, `protocolo_auditoria` nem serviço genérico de assinatura, pois o backend Laravel atual não possui consumidor ou infraestrutura correspondente;
- `assinatura_protocolo` permanece `NULL` nos novos lançamentos Laravel até uma onda própria exigir assinatura verificável.

## Campos do lançamento

### Obrigatórios na criação

- `descricao`: string não vazia;
- `valor`: decimal positivo, máximo de duas casas;
- `tipo_despesa`: string não vazia;
- `destino_pagamento`: string não vazia.

### Opcionais na criação

- `data`: data/hora do lançamento; se ausente, `now()` server-side;
- `data_vencimento`: date nullable;
- `forma_pagamento`: string nullable;
- `status`: somente `aberta | paga`; `cancelada` não pode ser criada diretamente pelo POST;
- `data_pagamento`: permitida somente quando `status = 'paga'`.

### Campos proibidos como autoridade externa

Devem estar ausentes do input:

- `id`;
- `protocolo`;
- `assinatura_protocolo`;
- `foi_pago`;
- `caixa_sessao_id`;
- `created_at`;
- `updated_at`.

`caixa_sessao_id` continua sendo atribuído automaticamente pelo trigger do Caixa quando a Saída efetiva é paga em `Dinheiro` ou `PIX` e existe exatamente uma sessão aberta, conforme o contrato já aprovado do Caixa.

## Criação

`POST /api/financeiro/saidas` cria o lançamento em uma única transação tenant.

Regras:

- exige `gestao_financeira`;
- protocolo é server-side;
- `valor > 0` também é protegido no PostgreSQL;
- `status = 'aberta'` é default quando ausente;
- se nascer `paga`, o backend normaliza `foi_pago = true` e `data_pagamento`;
- se nascer `aberta`, `foi_pago = false` e `data_pagamento = NULL`;
- `Dinheiro`/`PIX` pagos podem ser vinculados automaticamente ao Caixa aberto pelo trigger já existente;
- criação com `status = 'cancelada'` é inválida.

## Correção de Saída aberta

`PATCH /api/financeiro/saidas/{id}` permite corrigir somente uma Saída em `status = 'aberta'`.

Enquanto aberta, podem ser alterados:

- `descricao`;
- `valor`;
- `tipo_despesa`;
- `destino_pagamento`;
- `data`;
- `data_vencimento`;
- `forma_pagamento`.

O PATCH também pode receber `status = 'paga'`, que representa a transição formal de pagamento.

Não é permitido:

- trocar protocolo;
- trocar assinatura;
- definir `foi_pago` manualmente;
- definir `caixa_sessao_id` manualmente;
- alterar diretamente para `cancelada`;
- editar Saída já `paga`;
- editar Saída já `cancelada`;
- reabrir estado terminal.

A ação deve bloquear a linha com `SELECT ... FOR UPDATE` antes de validar/transicionar, evitando concorrência entre edição, pagamento e estorno.

## Efetivação do pagamento

A transição `aberta -> paga` acontece pelo mesmo PATCH, sem criar endpoint extra.

Ao efetivar:

- `status = 'paga'`;
- `foi_pago = true`;
- `data_pagamento` é validada ou gerada server-side;
- `forma_pagamento` pode ser definida/ajustada na mesma transação;
- demais campos permitidos de uma Saída ainda aberta podem ser corrigidos na mesma operação antes do fechamento do estado;
- o trigger do Caixa pode preencher `caixa_sessao_id` para `Dinheiro`/`PIX` quando houver sessão elegível.

Após commit como `paga`, os campos de negócio ficam imutáveis.

## Estorno / cancelamento formal

`POST /api/financeiro/saidas/{id}/estorno` é a única operação que leva uma Saída existente a `cancelada`.

Payload:

```json
{
  "motivo": "texto obrigatório"
}
```

Regras:

- exige `gestao_financeira`;
- bloqueia a Saída com `FOR UPDATE`;
- exige motivo não vazio após normalização;
- recusa segundo estorno;
- aceita Saída `aberta` ou `paga`, reproduzindo o comportamento observado no Supabase live;
- altera somente o estado necessário para cancelar a efetividade: `status = 'cancelada'` e `foi_pago = false`;
- preserva valor, descrição, forma, datas, protocolo e vínculo histórico com Caixa;
- cria uma linha em `financeiro_estornos` com `origem_tipo = 'saida'`, `origem_id`, `motivo`, `valor` e `criado_por`;
- a UNIQUE já existente em `(origem_tipo, origem_id)` continua sendo a defesa física contra estorno duplicado.

Uma Saída estornada vinculada a um Caixa aberto ou fechado permanece com `caixa_sessao_id` para rastreabilidade, mas o cálculo do fechamento a exclui por existir `financeiro_estornos` de origem `saida`, conforme a fase Caixa já implementada.

## Proteções PostgreSQL

A nova migration deve endurecer `financeiro_saidas` sem recriar a tabela.

Invariantes obrigatórias:

- protocolo gerado automaticamente e imutável;
- assinatura imutável quando não nula;
- `valor > 0`;
- status limitado a `aberta | paga | cancelada`;
- sincronização de `status`/`foi_pago`;
- `data_pagamento` obrigatória/equivalente quando `paga`;
- Saída `paga` não aceita alteração de campos de negócio;
- Saída `cancelada` não aceita reabertura ou alteração de campos de negócio;
- `DELETE` físico continua bloqueado;
- tentativa de vincular movimento a Caixa fechado continua bloqueada pelo contrato do Caixa;
- `updated_at` é atualizado server-side.

As funções novas devem usar `SECURITY INVOKER`, `SET search_path = ''` e nomes schema-qualified. Nenhum `SECURITY DEFINER` será introduzido nesta subfase.

## Listagem

`GET /api/financeiro/saidas` é consulta tenant read-only e exige `visualizar_financeiro`.

Filtros mínimos:

- `search`: protocolo, descrição, tipo de despesa ou destino;
- `status`: `aberta | paga | cancelada`;
- `date_from` / `date_to`: limites exatos sobre `data`;
- `limit` com teto server-side;
- cursor `(data, id)` para paginação estável, ordem decrescente.

A resposta deve expor os nomes canônicos do backend, sem transformar status em "Sim/Não" nem reconstruir `cliente` a partir da descrição. Compatibilidade de apresentação pertence ao frontend.

Campos de resposta:

- `id`;
- `protocolo`;
- `data`;
- `descricao`;
- `valor`;
- `tipo_despesa`;
- `destino_pagamento`;
- `data_vencimento`;
- `status`;
- `foi_pago`;
- `data_pagamento`;
- `forma_pagamento`;
- `caixa_sessao_id`;
- `created_at`;
- `updated_at`.

## API final desta subfase

```text
GET   /api/financeiro/saidas
POST  /api/financeiro/saidas
PATCH /api/financeiro/saidas/{id}
POST  /api/financeiro/saidas/{id}/estorno
```

Não existe `DELETE /api/financeiro/saidas/{id}`.

## Autorização

A cadeia permanece:

```text
Bearer Supabase
  -> supabase.auth
  -> tenant ativo/autorizado
  -> tenant.permission
  -> domínio no banco físico do laboratório
```

Permissões:

- listar Saídas: `visualizar_financeiro`;
- criar Saída: `gestao_financeira`;
- corrigir/efetivar Saída: `gestao_financeira`;
- estornar Saída: `gestao_financeira`.

O papel `financeiro` já possui as permissões necessárias por causa da fase Caixa. `recepcionista` não ganha acesso financeiro adicional nesta subfase. `admin` permanece autorizado pela regra global.

## Concorrência

Criação de protocolo usa incremento atômico em `protocolo_sequence`.

PATCH e estorno usam transação + `FOR UPDATE` na Saída.

Cenários obrigatórios:

- dois PATCH concorrentes não podem efetivar duas transições conflitantes;
- PATCH concorrente com estorno deve ser serializado pela linha;
- segundo estorno deve falhar sem criar linha adicional em `financeiro_estornos`;
- operação que chega depois de uma Saída se tornar `paga` ou `cancelada` deve reler o estado bloqueado e respeitar a imutabilidade terminal.

Não introduzir optimistic versioning adicional, Redis, fila, worker ou event bus nesta subfase.

## Tratamento de erros

- `404`: Saída inexistente;
- `409`: conflito de estado — já paga/cancelada para edição, estorno duplicado ou transição concorrente incompatível;
- `422`: payload inválido;
- `403`: usuário autenticado sem permissão;
- violações físicas inesperadas do PostgreSQL não devem ser mascaradas como sucesso.

Controllers permanecem finos e mapeiam apenas exceções de domínio conhecidas. Integridade crítica continua no PostgreSQL.

## Testes obrigatórios

### API

- listar vazio;
- criar `aberta` com protocolo server-side;
- criar `paga` com data de pagamento server-side;
- recusar `cancelada` no POST;
- recusar protocolo/foi_pago/caixa_sessao_id fornecidos pelo cliente;
- corrigir campos de Saída aberta;
- efetivar `aberta -> paga`;
- vincular Saída paga em Dinheiro/PIX ao Caixa quando elegível;
- não vincular outras formas;
- não exigir Caixa para tornar a Saída paga;
- impedir edição após `paga`;
- estornar aberta;
- estornar paga;
- impedir estorno duplicado;
- preservar vínculo histórico com Caixa no estorno;
- filtros e cursor da listagem;
- RBAC de leitura/escrita;
- inexistência de rota DELETE.

### PostgreSQL

- geração concorrente/única de protocolo;
- protocolo imutável;
- assinatura não nula imutável;
- valor zero/negativo rejeitado;
- `status`/`foi_pago` coerentes em INSERT/UPDATE direto;
- `paga` exige `data_pagamento` após normalização;
- campos de negócio imutáveis depois de `paga`;
- estado `cancelada` terminal;
- DELETE físico bloqueado;
- Caixa fechado rejeita novo vínculo;
- `updated_at` server-side.

### Regressão

- Caixa continua fechando com Saídas pagas não estornadas;
- Saída estornada continua excluída do saldo do Caixa;
- Financeiro Core de pagamentos/estornos continua verde;
- provisionamento tenant registra a migration nova como schema atual.

## Migration

Criar migration tenant subsequente à `2026_09_10_000700_add_caixa_operacional.php`, destinada apenas ao hardening operacional de Saídas. A migration não recria `financeiro_saidas` nem duplica triggers já corretos do Caixa.

Ela deve:

- adicionar função/trigger de protocolo `SAI-AAAA-NNNNNNN` usando `protocolo_sequence`;
- proteger protocolo/assinatura;
- reforçar `valor > 0` substituindo o check atual `valor >= 0`;
- sincronizar estado efetivo (`status`, `foi_pago`, `data_pagamento`);
- bloquear mutações de negócio após estado terminal;
- manter o bloqueio DELETE já existente;
- atualizar `updated_at` server-side sem criar dois triggers concorrentes para a mesma responsabilidade.

Antes de criar qualquer trigger novo, a implementação deverá revisar os triggers da migration `000700` e evitar duplicação de responsabilidade.

## Documentação oficial e segurança

A implementação deve seguir a documentação vigente de Laravel 13 e PostgreSQL para transações, `lockForUpdate`, validação e constraints.

Como o Supabase é apenas baseline read-only nesta onda, nenhuma policy RLS ou RPC nova será criada nele. A segurança de funções PostgreSQL criadas no Laravel seguirá o padrão já adotado nas fases Financeiro/Totais/Caixa: `SECURITY INVOKER`, `search_path = ''` e referências schema-qualified.

## Fora do escopo

- HMAC/assinatura de protocolo e gestão de segredo;
- `protocolo_auditoria` genérica;
- edição de Saída paga;
- reabertura de Saída cancelada;
- DELETE de Saída;
- anexos/comprovantes;
- fornecedores estruturados;
- categorias fechadas de despesa;
- centro de custo;
- contas recorrentes;
- parcelamento;
- aprovação em múltiplas etapas;
- sangria/suprimento;
- DRE;
- conciliação bancária;
- ERP;
- Convênios/Faturas;
- resumo financeiro/Painel;
- frontend cutover.

## Critério de conclusão

A subfase só pode ser considerada concluída quando, no mesmo SHA final:

- Composer Validate passar;
- Composer Audit passar sem vulnerabilidade conhecida relevante;
- manifesto Supabase permanecer íntegro;
- Pint passar;
- Larastan nível 8 passar;
- Pest completo em PostgreSQL real passar;
- guards de fronteira/escopo passarem;
- testes de provisionamento chegarem à migration nova;
- comparação da branch contra `8c563881ceb21dcf842b5facd26b75ee4206bce9` mostrar somente arquivos do escopo Saídas/Financeiro/documentação/testes/provisionamento.
