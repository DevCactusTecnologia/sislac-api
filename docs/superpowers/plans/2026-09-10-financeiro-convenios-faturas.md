# Financeiro — Convênios e Faturas

## Objetivo

Migrar para o backend Laravel/PostgreSQL tenant o contrato financeiro de convênios já existente no SISLAC, preservando o princípio arquitetural:

> Frontend lê. Backend calcula. Nada é destrutivo.

A implementação deve ser enxuta, transacional e compatível com a operação atual. O Supabase live é somente referência read-only durante esta migração.

## Base

Branch base: `fase-financeiro-saidas-despesas`

SHA base: `1e692e67b9cf729dadc21376218ecab1ddf89601`

Branch: `fase-financeiro-convenios-faturas`

## Contrato observado no live

Objetos canônicos existentes:

- `convenios`;
- `convenio_faturas`;
- `convenio_fatura_itens`;
- `convenio_glosas`;
- `convenio_competencias`;
- `convenio_fatura_resumo` como leitura derivada.

Os atendimentos Laravel já carregam os vínculos necessários:

- `atendimentos.convenio_id`;
- `atendimentos.convenio_nome`;
- `atendimento_exames.cobranca_destino` (`paciente|convenio`);
- `atendimento_exames.convenio_cobranca_id`.

## Sequência de implementação

### Onda 1 — Fundação canônica

Criar schema tenant mínimo com as cinco tabelas persistentes e invariantes essenciais:

- chaves primárias e estrangeiras;
- `convenios.codigo` gerado pelo servidor e único;
- catálogo protegido para o convênio Particular;
- status canônicos de fatura: `aberta`, `fechada`, `paga`, `cancelada`;
- status canônicos de glosa: `aberta`, `reapresentada`, `aceita_perda`, `cancelada`;
- competência no formato `AAAA-MM`, única e com estados `aberta|fechada`;
- um `atendimento_exame_id` só pode pertencer a um item de fatura;
- valores monetários `numeric(14,2)` e não negativos;
- `periodo_inicio <= periodo_fim`;
- DELETE físico de faturas/glosas bloqueado onde houver histórico financeiro.

### Onda 2 — Faturamento mínimo

Implementar operações Laravel:

- listar convênios;
- listar faturas;
- criar fatura a partir de exames elegíveis do convênio;
- fechar fatura;
- registrar pagamento;
- cancelar por fluxo formal sem DELETE físico;
- resource com valores monetários serializados em duas casas.

Elegibilidade do item:

- `cobranca_destino = 'convenio'`;
- `convenio_cobranca_id = convenio_id` da fatura;
- exame não cancelado;
- exame ainda não faturado;
- período compatível com a fatura;
- somente exames finalizados quando esta for a regra operacional já consolidada do módulo.

Totais são derivados no servidor/PostgreSQL. O cliente não pode definir `subtotal`, `total` ou outras somas derivadas como autoridade.

### Onda 3 — Glosa e reapresentação

Implementar glosa parcial/total auditável e reapresentação sem apagar o lançamento original. O resumo de fatura deve derivar:

- total faturado;
- total recebido;
- total glosado;
- total glosado em aberto;
- total reapresentado;
- saldo pendente.

### Onda 4 — Competência

Implementar fechamento mensal e reabertura controlada, bloqueando mutações financeiras de faturas/itens/glosas pertencentes a competência fechada.

## Regras de implementação

- PostgreSQL tenant é a autoridade de persistência e invariantes.
- Actions Laravel orquestram transações; não duplicar cálculo canônico em Controller.
- Controllers finos.
- Form Requests rejeitam explicitamente campos server-owned.
- Leituras derivadas devem preferir Query/VIEW canônica, não livro paralelo.
- `FOR UPDATE` nas transições financeiras concorrentes.
- funções de trigger com `SECURITY INVOKER`, `SET search_path = ''` e referências schema-qualified.
- sem Redis, filas, cache financeiro, event bus ou serviço externo.
- nenhuma escrita no Supabase live.

## TDD e gates

Cada onda começa RED e só fecha após GREEN.

Gates obrigatórios:

1. schema/invariantes PostgreSQL em banco tenant real;
2. API/ações financeiras;
3. autorização;
4. concorrência/imutabilidade quando aplicável;
5. Pint;
6. Larastan nível 8;
7. Pest completo;
8. guards de repositório;
9. Composer validate/audit;
10. comparação final contra a branch base, com `behind_by = 0`.

## Fora do escopo desta fase

- resumo financeiro geral/Painel;
- DRE;
- conciliação bancária;
- centro de custo;
- ERP;
- cutover do frontend;
- cobrança SaaS da plataforma;
- infraestrutura assíncrona não necessária à operação de faturas.
