# Baseline de segurança — SISLAC API

Estes controles valem para todo o backend, não só para a fase em que aparecem.
Alguns já existem nesta Fase 0; a maioria entra nas fases seguintes. O
importante é que cada um seja **verificável** — de preferência por um teste
automatizado ou um script no CI.

## Rede

- Nenhuma porta além de 22, 80 e 443 aberta na VPS.
- Postgres, Redis, pgAdmin e Nginx interno escutam apenas em `127.0.0.1`.
- TLS 1.2+ em todas as conexões externas; HSTS ativo.
- `fail2ban` protegendo SSH.

## Aplicação

- MFA obrigatório para papéis `admin`, `gestor` e plataforma (super‑admin,
  suporte).
- Senhas em bcrypt (compatível com os hashes atuais do Supabase Auth).
- Sanctum com tokens Bearer curtos + refresh; nunca em cookie de terceiros.
- Rate limit fail‑closed nas rotas públicas: se o Redis cai, a rota devolve
  503, nunca libera passagem.
- CSP e HSTS no Nginx público, iguais ou mais estritos que o `vercel.json`
  atual do `sislacprivado`.
- Cabeçalho `X‑Tenant` **seleciona** o laboratório; o **vínculo** em
  `memberships` é quem **autoriza**. Um `X‑Tenant` forjado nunca dá acesso.

## Banco

- Papel de aplicação (`sislac_app`) sem privilégio de superusuário, sem
  `CREATEDB` nem `CREATEROLE`. Só o pipeline de provisionamento usa o
  superusuário, e apenas para criar/apagar bancos.
- Auditoria append‑only (triggers no Postgres) preservada em cada banco de
  laboratório.
- Resultado imutável após assinatura: trigger de bloqueio ativo e hash
  impresso no laudo.
- Backup automático de todos os bancos, retenção 30 dias, ensaio de restore
  mensal em ambiente separado.
- Disco criptografado (LUKS ou pelo provedor).

## Dados sensíveis

- CPF, telefone e e‑mail de paciente com cast `encrypted` no Eloquent, com
  índice cego para busca.
- Credenciais de integração (labs de apoio, gateway de pagamento) com cast
  `encrypted` também.
- Segredos da plataforma (Meta, Stripe/Asaas) só em variável de ambiente do
  host — jamais no banco, jamais no repositório.

## LGPD

- Consentimento registrado no banco do laboratório com data, IP e finalidade.
- Eliminação de um laboratório com evidência: drop do banco e purga do
  storage, registrada em `platform_audit`.
- Portabilidade: pipeline de saída gera dump + pacote do storage para entrega
  ao laboratório.
- Relatório de acessos sob demanda.

## RDC 978/2025 (ANVISA)

- Trilha completa da coleta à liberação preservada no banco do laboratório.
- Cobertura por teste de regressão a cada migration nova (fase 3+).
- Assinatura digital do laudo (ICP‑Brasil A1) prevista para a Fase 2.

## O que já é verificado pelo CI hoje

- `scripts/check-no-central-in-tenant.sh` — impede código de tenant importar
  Platform e vice‑versa.
- `scripts/check-file-size.sh` — impede dump de dados ou binário acidental.

## O que entra no CI a partir da Fase 1

- Teste de isolamento (`app A` tentando ler `app B` com `X‑Tenant` forjado e
  com IDs válidos do B → 403/404).
- Presença de MFA para admin em rotas sensíveis.
- Rate limit fail‑closed em todas as rotas públicas.
