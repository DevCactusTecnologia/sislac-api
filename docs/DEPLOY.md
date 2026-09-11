# Deploy — SISLAC API

## Topologia

A API Laravel pode ser hospedada em um VPS Linux simples. O banco, autenticação e arquivos permanecem no Supabase; portanto o servidor da API não precisa hospedar PostgreSQL, painel de banco ou stack de containers para a aplicação funcionar.

```text
Internet
   |
Nginx / TLS
   |
PHP 8.4 + Laravel
   |
Supabase PostgreSQL / Auth / Storage
```

O frontend permanece separado, por exemplo em Vercel.

## Servidor recomendado

- Ubuntu 24.04 LTS;
- Nginx;
- PHP 8.4 FPM com extensões exigidas pelo Composer;
- Composer 2;
- Git;
- certificado TLS válido.

Exponha somente as portas necessárias, normalmente 22, 80 e 443. Banco de dados não deve ficar publicado pelo VPS porque ele não roda no servidor da API.

## Instalação

```bash
git clone git@github.com:DevCactusTecnologia/sislac-api.git
cd sislac-api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate --force
```

Configure o `.env` antes de iniciar a aplicação.

Variáveis essenciais:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sislac.com.br

DB_CONNECTION=pgsql
DB_HOST=<host PostgreSQL do Supabase>
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=<role dedicada da API>
DB_PASSWORD=<segredo fora do Git>
DB_SSLMODE=require

SUPABASE_URL=<url do projeto>
SUPABASE_PUBLISHABLE_KEY=<publishable key>

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

FRONTEND_URL=https://sislac.com.br
CORS_ALLOWED_ORIGINS=https://sislac.com.br,https://www.sislac.com.br
```

A role PostgreSQL da API deve ser dedicada ao backend, sem `BYPASSRLS`, sem superuser e sem privilégios de administração do cluster.

## Laravel

Depois da configuração:

```bash
php artisan optimize:clear
php artisan optimize
```

Não execute migrations Laravel contra produção como procedimento padrão desta arquitetura. O schema de produção pertence ao projeto Supabase e mudanças de banco devem seguir o fluxo versionado e revisado do próprio projeto de dados.

## Nginx

O document root deve apontar para `public/`. Use PHP-FPM 8.4, limite de upload coerente com a aplicação e encaminhe os headers padrão de proxy/HTTPS. Habilite TLS moderno e redirecione HTTP para HTTPS.

O endpoint para prova básica de disponibilidade é:

```bash
curl --fail --silent --show-error https://api.sislac.com.br/api/health
```

## Atualização

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan optimize
sudo systemctl reload php8.4-fpm
sudo systemctl reload nginx
```

Antes de atualizar produção, o SHA deve ter CI verde.

## Validação pós-deploy

Verifique:

1. `GET /api/health` responde com sucesso;
2. rota protegida sem Bearer retorna não autorizado;
3. Bearer válido é reconhecido;
4. usuário sem permissão recebe bloqueio de autorização;
5. uma operação homologada lê/escreve no Supabase esperado;
6. erros de aplicação não deixam transações abertas;
7. logs não contêm tokens ou credenciais.

## Segredos

Nunca versione `.env`, senha PostgreSQL, token de usuário, chave administrativa ou credencial SSH. Use secrets do provedor/servidor com permissões mínimas.

## Rollback

Aplicação:

```bash
git checkout <sha-anterior-aprovado>
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan optimize
sudo systemctl reload php8.4-fpm
```

Mudanças de schema/dados exigem plano de rollback próprio e nunca devem ser revertidas cegamente junto com o código.
