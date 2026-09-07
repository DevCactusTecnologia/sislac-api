# Baseline de segurança — SISLAC API

Estes controles valem para todo o backend. Cada controle deve ser verificável,
preferencialmente por teste automatizado ou guard de CI.

## Rede

- Somente 22, 80 e 443 ficam expostas na VPS.
- PostgreSQL, Redis, pgAdmin e o Nginx interno escutam apenas em `127.0.0.1`.
- TLS 1.2+ em conexões externas e HSTS no proxy público.
- `fail2ban` protege SSH.

## Aplicação

- MFA é obrigatório para `admin`, `gestor`, super-admin e suporte quando os
  respectivos fluxos forem habilitados.
- Senhas são armazenadas por hash suportado oficialmente pelo Laravel; a
  compatibilidade com identidades importadas deve ser provada por teste antes
  do corte.
- O SPA first-party usa Laravel Sanctum em modo **stateful**, com sessão,
  cookie HttpOnly e proteção CSRF, conforme a documentação do Laravel 13.
- Bearer tokens ficam restritos a integrações, clientes externos ou automações
  que realmente necessitem de API token, com abilities e expiração explícitas.
- `tokenCan()` nunca substitui Policies/Gates e regras de autorização de
  negócio para o SPA first-party.
- Login regenera a sessão; logout invalida a sessão e regenera o token CSRF.
- Rate limiting de endpoints públicos e autenticação é fail-closed.
- CSP e HSTS no proxy público devem ser iguais ou mais estritos que os do
  frontend em produção.
- `X-Tenant` apenas **seleciona** entre vínculos já autorizados. A autoridade é
  `memberships`; um valor forjado nunca concede acesso.

## Banco

- O papel HTTP (`sislac_app`) não possui `SUPERUSER`, `CREATEDB` ou
  `CREATEROLE`.
- Criar ou remover bancos é responsabilidade exclusiva do provisionamento,
  executado fora do caminho normal de requisição e auditado.
- Auditorias clínicas e financeiras são append-only.
- Resultado assinado é imutável; a regra será preservada e coberta por teste
  de regressão antes da migração do módulo de resultados.
- Backups têm retenção definida e restore precisa ser ensaiado em ambiente
  separado.
- Produção utiliza PostgreSQL; MySQL, MariaDB e SQL Server não fazem parte do
  contrato do SISLAC.

## Dados sensíveis

- Dados clínicos e identificadores pessoais só aparecem em logs quando forem
  estritamente necessários e explicitamente sanitizados.
- Credenciais de integração são cifradas em repouso e nunca retornam em API.
- Segredos da plataforma vivem em configuração segura do ambiente, nunca no
  repositório.
- Testes e fixtures usam exclusivamente dados sintéticos ou anonimizados.

## Isolamento multi-tenant

Casos obrigatórios de regressão:

- usuário de A não lê nem grava B;
- `X-Tenant` forjado retorna negação sem inicializar conexão de B;
- membership suspensa perde acesso imediatamente;
- usuário com vários vínculos só entra no tenant explicitamente selecionado e
  autorizado;
- exceção durante uma requisição não deixa contexto tenant para a seguinte;
- jobs, cache, filesystem e broadcasting carregam contexto tenant explícito.

## Baseline Supabase

O Supabase é referência de comportamento durante a migração, não autoridade
absoluta de segurança. Findings dos advisors são registrados em
`docs/conformance/supabase-runtime-baseline.json` e não são copiados para o
Laravel sem justificativa técnica.

Em particular, funções privilegiadas, políticas RLS subótimas e índices
redundantes devem ser substituídos por controles equivalentes ou melhores,
preservando o resultado funcional esperado.

## LGPD e rastreabilidade

- Acesso a dados de saúde segue menor privilégio e trilha de auditoria.
- Offboarding de laboratório exige evidência de remoção do banco e storage.
- Portabilidade deve gerar pacote verificável sem alterar a fonte original.
- Trilhas da coleta à liberação permanecem preservadas e cobertas por testes
  de regressão antes do corte de cada módulo.

## Gates atuais do CI

- `scripts/check-no-central-in-tenant.sh`: preserva a fronteira Platform ↔ Domain.
- `scripts/check-database-contract.sh`: preserva o contrato PostgreSQL-only.
- `scripts/check-file-size.sh`: bloqueia arquivos anormalmente grandes.
- guard de `.env`: bloqueia configuração sensível comitada.
- Pint e Pest em PostgreSQL 17 real.
- `composer audit --locked`: auditoria de dependências, quando adicionada ao
  workflow da Fase 1A.

## Gates da Fase 1B

- autenticação SPA stateful/CSRF;
- isolamento A/B e proteção contra BOLA/IDOR;
- membership suspensa e tenant forjado;
- cleanup determinístico de contexto;
- rate limiting de autenticação e endpoints públicos;
- Larastan nível 8 como gate obrigatório após instalação legítima do pacote.
