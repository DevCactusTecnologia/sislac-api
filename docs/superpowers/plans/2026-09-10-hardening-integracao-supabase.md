# Hardening Integração Supabase Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** eliminar divergências comprovadas entre SISLAC Laravel, Supabase live e `sislacprivado`, mantendo Supabase Auth durante a transição e sem dual-write.

**Architecture:** o frontend continua autenticando no Supabase; módulos já migrados passam a ter o Laravel como autoridade operacional quando ocorrer o cutover. Até lá, o Supabase live recebe somente correções necessárias para permanecer compatível com as invariantes já canônicas no Laravel. O mesmo manifesto deve definir quais contratos migrados são verificados offline e no gate live.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 17 / React / TypeScript / Supabase / Pest / SQL regression tests.

## Constraints

- Sem dual-write Supabase + Laravel.
- Sem Redis, fila, event bus ou abstração preventiva.
- Supabase: grants explícitos + RLS; `SECURITY INVOKER` por padrão; `SECURITY DEFINER` somente em boundary controlada.
- Laravel: autorização, validação e transações server-side.
- Nenhum merge em `main` nesta execução.

## Tasks

- [ ] Corrigir A Receber/Resumo no Supabase para excluir exames cancelados, com RED→GREEN e migration versionada.
- [ ] Remover DELETE físico órfão de Saídas e revogar DELETE para browser roles, preservando estorno formal.
- [ ] Tornar `supabase-baseline.json` a fonte única da lista de contratos migrados.
- [ ] Fazer o gate live e o check offline consumirem o mesmo registry de contratos.
- [ ] Manter o monitor de produção fail-closed; a credencial read-only ausente deve ser configurada fora do código.
- [ ] Antes do cutover, portar agregados financeiros restantes e criar importação determinística de usuários/memberships.

## Scope do registry nesta fase

Somente módulos já concluídos e verdes:

1. `pacientes`
2. `atendimentos`
3. `rotina`
4. `financeiro-core`
5. `financeiro-totais-atendimento`
6. `financeiro-caixa-operacional`
7. `financeiro-saidas`

`financeiro-convenios-faturas-core` permanece fora até sua própria branch ficar GREEN.
