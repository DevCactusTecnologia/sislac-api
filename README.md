# SISLAC — API

Backend do SISLAC em **Laravel 13 / PHP 8.4**, responsável pela camada HTTP, regras de negócio, autorização e integrações. O **Supabase permanece como infraestrutura de dados**, fornecendo PostgreSQL, Auth e Storage.

## Arquitetura

```text
Frontend React / Vercel
        |
        | Bearer Supabase
        v
Laravel API
  - valida identidade
  - aplica permissões
  - abre contexto RLS transacional
  - executa regras de negócio
        |
        v
Supabase
  - PostgreSQL
  - Auth
  - Storage
```

O Laravel não mantém um segundo banco de aplicação, não provisiona bancos por laboratório e não replica autenticação ou armazenamento já fornecidos pelo Supabase. A conexão PostgreSQL padrão aponta para o projeto Supabase por uma role dedicada ao backend, sem privilégios administrativos e sem bypass de RLS.

A cadeia das rotas protegidas é:

```text
supabase.auth -> supabase.db -> permission:<permissão> -> domínio
```

`supabase.auth` valida o Bearer token no Supabase Auth. `supabase.db` abre uma transação e aplica ao PostgreSQL o contexto do usuário autenticado. A autorização reutiliza a função canônica `public.has_permission` do banco.

## Módulos atuais

- Pacientes;
- Atendimentos;
- Rotina / Fluxo Operacional;
- Financeiro de pacientes;
- Caixa operacional;
- Saídas / despesas.

As regras críticas permanecem transacionais e as invariantes que pertencem ao banco continuam protegidas no PostgreSQL.

## Desenvolvimento

Pré-requisitos:

- PHP 8.4;
- Composer 2;
- extensões PHP exigidas pelo `composer.json`;
- PostgreSQL 17 para a suíte de integração.

Instalação:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Para executar a aplicação, configure `.env` com a URL/chave pública do Supabase e a conexão PostgreSQL do ambiente de desenvolvimento ou homologação. Nunca use produção para testes automatizados.

Health check:

```text
GET /api/health
```

## Testes e qualidade

O CI sobe PostgreSQL 17 descartável e carrega `tests/Fixtures/supabase-test-schema.sql`, reproduzindo apenas o contrato necessário do Supabase para testes.

Gates obrigatórios:

```bash
composer validate --strict --no-interaction
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest --parallel
bash scripts/check-backend-scope.sh
bash scripts/check-database-contract.sh
bash scripts/check-file-size.sh
```

Nenhuma alteração deve entrar em `main` com gate vermelho.

## Estrutura

```text
app/
├─ Domain/       # regras de negócio
├─ Http/         # controllers, requests, middleware e resources
└─ Platform/     # integração enxuta com serviços externos/Supabase

routes/          # API e health check
tests/           # testes e fixture PostgreSQL
docs/            # arquitetura, segurança e contratos
scripts/         # guards determinísticos do CI
```

Não adicionar infraestrutura, cache distribuído, filas, workers ou abstrações sem consumidor real.

## Documentação

- `docs/ARCHITECTURE.md` — arquitetura e limites;
- `docs/SEGURANCA.md` — autenticação, RLS e credenciais;
- `docs/DEPLOY.md` — deploy da API;
- `docs/contracts/` — contratos versionados dos módulos migrados.

## Licença

Uso interno da Devcactus Tecnologia. Segredos e arquivos `.env` nunca devem ser versionados.
