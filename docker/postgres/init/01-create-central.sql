-- SISLAC — inicialização do cluster PostgreSQL.
--
-- Roda automaticamente uma única vez, na primeira subida do container do
-- serviço `postgres`. Cria o papel de aplicação com privilégio mínimo e o
-- banco `sislac_central`. Bancos de tenants (`sislac_t_XXXX`) NÃO nascem
-- aqui — eles são criados depois pelo pipeline de provisionamento do
-- Laravel.
--
-- ATENÇÃO: a senha abaixo vem de SISLAC_APP_PASSWORD, injetada pelo compose.
-- Em produção o valor está no `.env` do host, nunca no repositório.

\set app_password `echo "$SISLAC_APP_PASSWORD"`

SELECT format(
    'CREATE ROLE sislac_app WITH LOGIN PASSWORD %L NOCREATEDB NOCREATEROLE NOSUPERUSER NOREPLICATION',
    :'app_password'
)
WHERE NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'sislac_app')
\gexec

SELECT 'CREATE DATABASE sislac_central OWNER sislac_app ENCODING UTF8 TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sislac_central')
\gexec

\connect sislac_central

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS citext;

GRANT ALL ON SCHEMA public TO sislac_app;
