# Deploy — VPS (Hostinger, Ubuntu 24.04)

Domínio da API: `api.sislac.com.br`. O front (Lovable/Vercel) continua em
`sislac.com.br`; todos os laboratórios usam esse mesmo endereço.

Este guia cobre o **primeiro deploy** de uma VPS zerada. A VPS hospeda:

- **PostgreSQL 16** (via Docker Compose) — todos os bancos: `sislac_central` e
  `sislac_t_XXXX`.
- **Redis 7** — cache, filas, sessão, rate limit.
- **Nginx (público)** — TLS via Let's Encrypt, faz `proxy_pass` para o Nginx
  do compose em `127.0.0.1:8080`.
- **Nginx (interno, no compose)** — serve o Laravel.
- **PHP‑FPM 8.4** com o código Laravel.
- **pgAdmin** — só escutando em `127.0.0.1:5050`, acesso via túnel SSH.

Serviços que o Fase 2 acrescenta: Horizon, Reverb, scheduler, Chromium.

## 1 · Preparar a VPS

```bash
# Como root, uma vez
apt update && apt upgrade -y
apt install -y ca-certificates curl gnupg ufw fail2ban

# Docker Engine + Compose plugin
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" > /etc/apt/sources.list.d/docker.list
apt update && apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Usuário de deploy (evita rodar tudo como root)
adduser sislac
usermod -aG docker sislac

# SSH: entre com `sislac` daqui em diante. Chaves em ~sislac/.ssh/authorized_keys
```

## 2 · Firewall

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp        # SSH
ufw allow 80/tcp        # HTTP (redirect para 443)
ufw allow 443/tcp       # HTTPS
ufw enable
```

Nenhuma outra porta é aberta. Postgres, Redis, pgAdmin e o Nginx interno
escutam apenas em `127.0.0.1` (feito pelo `docker-compose.yml`), então só o
Nginx público na porta 443 fala com o mundo.

## 3 · Clonar e configurar

```bash
su - sislac
git clone git@github.com:DevCactusTecnologia/sislac-api.git
cd sislac-api

cp .env.example .env
nano .env
```

O que muda em relação ao exemplo (valores de produção):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sislac.com.br
FRONTEND_URL=https://sislac.com.br
CORS_ALLOWED_ORIGINS=https://sislac.com.br

# Redis assume cache, sessão e filas
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis

# Senhas: cada uma com `openssl rand -base64 32`
DB_PASSWORD=...
TENANT_DB_PASSWORD=...        # igual a DB_PASSWORD (mesmo papel sislac_app)
DB_ROOT_PASSWORD=...
REDIS_PASSWORD=...
PGADMIN_PASSWORD=...
PDF_SHARE_SECRET=...
INTERNAL_WEBHOOK_SECRET=...
```

Os hosts (`DB_HOST`, `REDIS_HOST`…) podem ficar como no exemplo: dentro do
compose o `docker-compose.yml` já os sobrescreve para `postgres` e `redis`.

```bash
```

## 4 · Subir o compose

```bash
docker compose build
docker compose up -d
docker compose run --rm app composer install --no-dev --optimize-autoloader
docker compose run --rm app php artisan key:generate --force
docker compose run --rm app php artisan config:cache
docker compose run --rm app php artisan route:cache
docker compose run --rm app php artisan event:cache
docker compose run --rm app php artisan migrate --database=central --force
```

A partir da Fase 1, também:

```bash
docker compose run --rm app php artisan tenants:migrate --force
```

## 5 · Nginx público + TLS

Fora do compose (na VPS), um Nginx padrão do sistema faz o TLS e passa para o
container:

```nginx
# /etc/nginx/sites-available/api.sislac.com.br
server {
    listen 80;
    server_name api.sislac.com.br;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.sislac.com.br;

    ssl_certificate     /etc/letsencrypt/live/api.sislac.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.sislac.com.br/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
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

Certificado (só a API precisa de um aqui — o front em `sislac.com.br` tem o
seu próprio, na hospedagem dele; não há subdomínio por laboratório):

```bash
apt install -y nginx certbot python3-certbot-nginx
ln -s /etc/nginx/sites-available/api.sislac.com.br /etc/nginx/sites-enabled/
certbot --nginx -d api.sislac.com.br
```

Renovação automática cai no timer do certbot.

## 6 · Backup

`pgBackRest` para PostgreSQL, com repositório em bucket S3-compatível
(Cloudflare R2 ou Backblaze B2). Configuração completa entra na Fase 1 junto
com o provisionamento.

Rotina mínima até lá:

```bash
# Dump diário do central + de cada tenant, retenção de 30 dias
docker compose exec postgres pg_dumpall -U postgres | gzip > backup-$(date +%F).sql.gz
```

## 7 · Verificações finais

- [ ] `curl https://api.sislac.com.br/api/health` responde 200 com `"status": "ok"`.
- [ ] `docker compose ps` mostra todos os serviços `healthy`.
- [ ] `nmap -p 5432,6379,5050 <ip-da-vps>` só vê `closed` ou `filtered`.
- [ ] Túnel SSH para pgAdmin funciona (ver [PGADMIN.md](PGADMIN.md)).
- [ ] Túnel SSH para Postgres via DBeaver funciona (ver [CONECTAR_DBEAVER.md](CONECTAR_DBEAVER.md)).
- [ ] Restauração de backup ensaiada em ambiente separado.
