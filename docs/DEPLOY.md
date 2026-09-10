# Deploy — VPS (Ubuntu 24.04)

Este guia descreve somente a fundação atualmente implementada. O frontend permanece separado em `sislac.com.br` e a API Laravel em `api.sislac.com.br`.

## Arquitetura implantada

A VPS executa via Docker Compose:

- PostgreSQL 17: banco central `sislac_central` e bancos físicos dos laboratórios;
- PHP-FPM 8.4: aplicação Laravel;
- Nginx interno: somente loopback;
- pgAdmin opcional: perfil `tools`, somente loopback.

Cache e sessão usam PostgreSQL. A fila é `sync`; não há worker nem tabelas persistentes de jobs nesta fundação.

## 1. Preparar e clonar

Instale Docker/Nginx/TLS conforme a política da VPS e exponha somente 22, 80 e 443. Com o usuário de deploy:

```bash
git clone git@github.com:DevCactusTecnologia/sislac-api.git
cd sislac-api
cp .env.example .env
nano .env
```

O repositório é de uso interno e deve estar privado antes do deploy definitivo.

## 2. Variáveis essenciais

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sislac.com.br
FRONTEND_URL=https://sislac.com.br
CORS_ALLOWED_ORIGINS=https://sislac.com.br,https://www.sislac.com.br

DB_CONNECTION=central
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sislac_central
DB_USERNAME=sislac_app
DB_PASSWORD=<senha-forte>
DB_ROOT_USER=postgres
DB_ROOT_PASSWORD=<senha-administrativa>

TENANT_DB_HOST=127.0.0.1
TENANT_DB_PORT=5432
TENANT_DB_USERNAME=sislac_app
TENANT_DB_PASSWORD=<senha-do-app>

# Autenticação clínica transitória
SUPABASE_URL=https://<project-ref>.supabase.co
SUPABASE_PUBLISHABLE_KEY=<publishable-key>

# Concordância/migração read-only
SUPABASE_DB_HOST=<host-suportado-pelo-ambiente>
SUPABASE_DB_PORT=5432
SUPABASE_DB_DATABASE=postgres
SUPABASE_DB_USERNAME=supabase_read_only_user
SUPABASE_DB_PASSWORD=<senha-da-role-read-only>
SUPABASE_DB_SSLMODE=require

CACHE_STORE=database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=sync
```

Não use `service_role`/secret key para validar o Bearer clínico. A publishable key identifica o projeto; o access token do usuário é validado server-side pelo Supabase Auth.

Para `supabase_source`, use somente credencial PostgreSQL read-only. O projeto atual já possui `supabase_read_only_user`; antes de produção, confirme novamente que ela mantém `default_transaction_read_only=on` e ausência de privilégios de escrita.

## 3. Primeira subida

```bash
docker compose build
docker compose up -d postgres
docker compose run --rm app composer install --no-dev --optimize-autoloader
docker compose run --rm app php artisan key:generate --force
docker compose run --rm app php artisan migrate --database=central --force
```

Crie/promova o primeiro Super Admin de forma explícita:

```bash
docker compose run --rm app php artisan admin:super-user SEU_EMAIL
```

Depois:

```bash
docker compose run --rm app php artisan optimize
docker compose up -d app nginx
```

## 4. Validação antes de corte

A integridade offline já pertence ao CI. A prova live deve ser executada apenas no ambiente confiável configurado com a credencial read-only:

```bash
docker compose run --rm app php artisan contract:supabase-live
```

Para auditar banco central criado por versões anteriores, sem remover nada:

```bash
docker compose run --rm app php artisan platform:audit-unused-tables
```

Se `plans` ou `subscriptions` aparecerem, revise dados/dependências antes de qualquer cleanup físico. O comando não executa `DROP`.

## 5. Nginx/TLS

A borda pública deve sobrescrever headers de proxy recebidos do cliente. Exemplo mínimo:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Port 443;
}
```

TLS 1.2+ e HSTS devem ser configurados no Nginx público.

## 6. Atualizações

```bash
cd /home/sislac/sislac-api
git pull --ff-only
docker compose build app
docker compose run --rm app composer install --no-dev --optimize-autoloader
docker compose run --rm app php artisan migrate --database=central --force
docker compose run --rm app php artisan optimize
docker compose up -d app nginx
```

Migrations tenant são aplicadas pelo fluxo explicitamente validado para os bancos dos laboratórios. Não execute SQL manual em lote.

## 7. Verificação pós-deploy

```bash
docker compose ps
curl --fail --silent --show-error https://api.sislac.com.br/api/health
```

Confirme também:

- portas PostgreSQL/pgAdmin/Nginx interno não estão públicas;
- `/admin/login` funciona via HTTPS;
- Super Admin abre a listagem de laboratórios;
- endpoint clínico sem Bearer retorna 401;
- endpoint clínico com token Supabase válido e usuário central correlacionado chega à autorização tenant;
- `contract:supabase-live` retorna Pacientes conforme usando a credencial read-only;
- um provisionamento de homologação cria o banco físico e termina ativo;
- restauração de backup foi ensaiada em ambiente separado.
