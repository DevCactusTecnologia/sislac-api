# Atendimentos Laravel — Design aprovado

## Objetivo

Migrar o agregado de Atendimentos para o backend Laravel definitivo sem regressão funcional e sem antecipar as ondas de Rotina, Resultados, Financeiro, Convênios, Caixa, Estoque ou integrações laboratoriais.

## Fonte de verdade

- Frontend React/Vite atual e Supabase/PostgreSQL ativo são baseline de comportamento durante a transição.
- Laravel é o backend definitivo.
- Cada laboratório usa PostgreSQL físico próprio via `stancl/tenancy`.
- O banco central não recebe domínio clínico.
- Nenhuma escrita será feita no Supabase nesta onda.

## Decisão arquitetural

Atendimentos é um agregado transacional composto por `atendimentos`, `atendimento_exames` e `atendimento_pagamentos`. Criação e edição não podem ser decompostas em CRUDs independentes porque o comportamento atual garante atomicidade, idempotência, preservação da identidade/status das amostras, recomputação de status e consistência dos totais.

A onda implementa a fundação completa do agregado e suas APIs, mas o cutover do frontend permanece bloqueado até Rotina e Financeiro cobrirem as invariantes que hoje ainda estão acopladas ao Supabase.

## Schema tenant

Criar no banco de cada laboratório:

- `protocolo_sequence`;
- `atendimentos`;
- `atendimento_exames`;
- `atendimento_pagamentos`;
- `atendimento_audit` append-only.

O schema deve preservar as colunas atuais necessárias à concordância futura. Campos especializados de Coleta/Análise/Resultado, laboratório de apoio, PDF, POP, estoque, caixa e convênios podem existir estruturalmente, mas suas regras especializadas permanecem fora desta onda.

## Protocolo

- 7 dígitos, sequencial e gerado exclusivamente no PostgreSQL.
- O valor enviado pelo cliente é ignorado.
- Protocolo é imutável após criação.
- A geração precisa suportar concorrência sem duplicação.

## Idempotência

`atendimentos.idempotency_key` é UUID opcional com unicidade parcial. Repetir criação com a mesma chave retorna o mesmo atendimento e não duplica exames/pagamentos.

## Status e totais derivados

`status_atendimento`, `status_pagamento`, `subtotal`, `total`, `desconto_total` e `acrescimo_total` não são editáveis diretamente pela API.

Rótulos compatíveis:

- Atendimento: `Pedido Realizado`, `Amostra Coletada`, `Amostra Analisada`, `Resultado Liberado`, `Cancelado`.
- Pagamento: `Pagamento pendente`, `Pagamento parcial`, `Pagamento efetuado`, `Pagamento cancelado`, `Faturado ao convênio`.

A recomputação baseia-se em `atendimento_exames` e `atendimento_pagamentos`, ignorando pagamentos `estornado` e exames `cancelado` onde aplicável.

## API

- `GET /api/atendimentos`
- `GET /api/atendimentos/kpis`
- `GET /api/atendimentos/{id}`
- `GET /api/atendimentos/protocolo/{protocolo}`
- `POST /api/atendimentos`
- `PATCH /api/atendimentos/{id}`

Não existe `DELETE /api/atendimentos`. Cancelamento é evento de negócio.

### Listagem

Cursor composto `(data, id)`, ordenação `data DESC, id DESC`, página entre 10 e 200 e filtros:

- `status`;
- `pagamento`;
- `unidade_id`;
- `data_inicio`;
- `data_fim`;
- `q`.

O Laravel deve aceitar `data_inicio`/`data_fim` mesmo que o RPC Supabase ativo ainda não declare esses parâmetros, porque a UI atual já os envia.

### KPIs

Retornar:

- `total`;
- `aguardando_coleta`;
- `em_analise`;
- `pendentes`;
- `finalizados`;
- `receita_total`.

Os KPIs ignoram apenas o filtro ativo de status quando a UI solicitar o universo base.

## Criação

`POST /api/atendimentos` executa uma única transação com:

1. atendimento;
2. exames;
3. pagamentos.

Qualquer falha causa rollback integral. Exames terceirizados podem iniciar em `digitado`; internos em `pendente`. `valor_original` usa `valor` como fallback.

## Edição

`PATCH /api/atendimentos/{id}` é transacional e deve:

- ignorar tentativa de alterar `status_atendimento`, `status_pagamento`, protocolo e totais derivados;
- preservar `ordem`, status clínico e `valor_original` da mesma ocorrência de exame;
- casar ocorrência por identidade (`exame_id` ou nome normalizado) + `amostra_seq`;
- impedir que uma nova amostra herde o estado da anterior;
- permitir substituição explícita da lista de exames;
- suportar cancelamento do atendimento sem exclusão física.

Nesta onda, alterações que dependam de faturas fechadas, caixa, estorno financeiro ou regras de Rotina não habilitam cutover do frontend. O código Laravel não deve fingir equivalência onde essas dependências ainda não foram migradas.

## Autorização

- leitura: `visualizar_atendimentos`;
- criação: `criar_atendimento`;
- edição: `editar_atendimento`;
- cancelamento: `cancelar_atendimento`;
- operação exclusivamente financeira: `registrar_pagamento`.

Autorização é server-side via middleware existente `tenant.permission` e validação adicional quando o tipo de operação muda dentro do PATCH.

## Auditoria

`atendimento_audit` é append-only no banco do laboratório. Registrar criação, edição e cancelamento com:

- entidade/operação/ação;
- `atendimento_id` e `registro_id` quando aplicável;
- protocolo/paciente/exame;
- `old_value`/`new_value` JSONB;
- `changed_by` e `changed_by_email` quando disponíveis;
- `changed_at`;
- justificativa.

Não criar segundo framework genérico de auditoria e não usar banco central para auditoria clínica.

## Fora do escopo

- registrar coleta;
- analisar amostras;
- inserir/liberar/retificar resultados;
- Fluxo/Rotina configurável;
- integrações de laboratórios de apoio;
- consumo de estoque;
- vínculo automático de caixa;
- estornos;
- reconciliação de faturas de convênio;
- alterações no frontend React;
- alterações no Supabase;
- Redis, filas, WebSocket, CQRS, event bus, repositories genéricos ou DTOs preventivos.

## Gate de cutover

A conclusão desta onda significa "backend Atendimentos implementado e validado", não "frontend já migrado". O cutover só pode ocorrer depois de Rotina e Financeiro/Convênios/Caixa cobrirem as invariantes dependentes hoje existentes no Supabase.

## Gates de qualidade

No mesmo SHA final:

- migrations tenant passam em PostgreSQL 17;
- protocolo concorrente e imutabilidade testados;
- criação atômica e idempotente testada;
- rollback integral testado;
- filtros, cursor, datas e KPIs testados;
- edição preserva estado clínico;
- cancelamento e permissões testados;
- auditoria testada;
- provisionamento de novo tenant inclui as novas migrations;
- Composer Validate/Audit, Pint, Larastan nível 8, Pest e guards ficam verdes.
