# Deploy — VPS (Ubuntu 24.04)

Este guia descreve somente a fundação atualmente implementada no repositório.
O frontend permanece separado em `sislac.com.br` e a API Laravel é publicada em
`api.sislac.com.br`.

## Arquitetura implantada

A VPS executa via Docker Compose:

- PostgreSQL 17: banco central `sislac_central` e bancos físicos dos laboratórios;
- PHP-FPM 8.4: aplicação Laravel;
- Nginx interno: exposto somente em `127.0.0.1:8080`;
- pgAdmin opcional: perfil `tools`, exposto somente em `127.0.0.1:5050`.

Cache, sessão e fila usam PostgreSQL nesta fundação. O banco de cada novo
laboratório é criado pelo `TenantProvisioner`; não deve ser criado manualmente.

## 1. Preparar a VPS

Como `root`:

```bash
apt update && apt upgrade -y
apt install -y ca-certificates curl gnupg ufw fail2ban git nginx certbot python3-certbot-nginx

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | gpg --dearmor -o /etc/apt/keyrings/docker.gpg

echo "deb [signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
  > /etc/apt/sources.list.d/docker.list

apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

adduser sislac
usermod -aG docker sislac
```

Firewall mínimo:

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

PostgreSQL, pgAdmin e o Nginx do compose permanecem ligados apenas ao loopback.

## 2. Clonar e configurar

Com o usuário de deploy:

```bash
su - sislac
git clone git@github.com:DevCactusTecnologia/sislac-api.git
cd sislac-api
cp .env.example .env
nano .env
```

Defina pelo menos:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sislac.com.br
FRONTEND_URL=https://sislac.com.br
CORS_ALLOWED_ORIGINS=https://sislac.com.br

DB_CONNECTION=central
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sislac_central
DB_USERNAME=sislac_app
DB_PASSWORD=<senha-forte-do-usuario-da-aplicacao>
DB_ROOT_USER=postgres
DB_ROOT_PASSWORD=<senha-forte-administrativa>

TENANT_DB_HOST=127.0.0.1
TENANT_DB_PORT=5432
TENANT_DB_USERNAME=sislac_app
TENANT_DB_PASSWORD=<mesma-senha-de-DB_PASSWORD>

CACHE_STORE=database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
```

Gere as senhas no próprio servidor, por exemplo:

```bash
openssl rand -base64 32
```

As variáveis `SUPABASE_DB_*` são necessárias somente enquanto operações de
transição precisarem consultar a origem Supabase em modo somente leitura. Não
use credencial com permissão de escrita para essa conexão.

## 3. Primeira subida

O script `docker/postgres/init/01-create-central.sql` roda apenas quando o volume
PostgreSQL é criado pela primeira vez. Ele cria `sislac_app` e
`sislac_central`. Os bancos dos laboratórios são criados posteriormente pelo
Laravel.

```bash
docker compose build
docker compose up -d postgres app nginx
docker compose run --rm app composer install --no-dev --optimize-autoloader
docker compose run --rm app php artisan key:generate --force
docker compose run --rm app php artisan migrate --database=central --force
docker compose run --rm app php artisan config:cache
docker compose run --rm app php artisan route:cache
docker compose run --rm app php artisan event:cache
```

Para abrir o pgAdmin localmente na VPS, quando necessário:

```bash
docker compose --profile tools up -d pgadmin
```

A porta `5050` não deve ser publicada para a internet; acesse-a por túnel SSH.

## 4. Nginx público e TLS

Crie `/etc/nginx/sites-available/api.sislac.com.br`:

```nginx
server {
    listen 80;
    server_name api.sislac.com.br;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name api.sislac.com.br;

    ssl_certificate /etc/letsencrypt/live/api.sislac.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.sislac.com.br/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    client_max_body_size 30M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
    }
}
```

Ative o site e emita o certificado:

```bash
ln -s /etc/nginx/sites-available/api.sislac.com.br /etc/nginx/sites-enabled/api.sislac.com.br
nginx -t
systemctl reload nginx
certbot --nginx -d api.sislac.com.br
```

## 5. Atualizações

Em cada deploy de código:

```bash
cd /home/sislac/sislac-api
git pull --ff-only
docker compose build app
docker compose up -d app nginx
docker compose run --rm app composer install --no-dev --optimize-autoloader
docker compose run --rm app php artisan migrate --database=central --force
docker compose run --rm app php artisan config:cache
docker compose run --rm app php artisan route:cache
docker compose run --rm app php artisan event:cache
```

Migrations de tenant são aplicadas pelo fluxo de provisionamento para novos
laboratórios. Qualquer atualização em massa de bancos existentes deve usar o
comando/fluxo explicitamente validado para essa finalidade; não execute SQL
manual em lote.

## 6. Backup

A fundação atual não instala um serviço externo de backup. Até existir uma
solução operacional validada no repositório, faça backup do cluster para um
destino seguro fora da VPS e teste a restauração periodicamente.

Exemplo de dump manual do cluster:

```bash
docker compose exec -T postgres pg_dumpall -U "$DB_ROOT_USER" \
  | gzip > "backup-$(date +%F-%H%M).sql.gz"
```

Não mantenha a única cópia do backup no mesmo servidor.

## 7. Verificação pós-deploy

```bash
docker compose ps
curl --fail --silent --show-error https://api.sislac.com.br/api/health
```

Confirme também:

- `5432`, `5050` e `8080` não estão acessíveis externamente;
- `/admin/login` abre via HTTPS;
- login do Super Admin funciona;
- a listagem de laboratórios abre;
- um provisionamento de homologação cria o banco físico, executa as migrations e termina com o laboratório ativo;
- uma restauração de backup foi ensaiada em ambiente separado.
