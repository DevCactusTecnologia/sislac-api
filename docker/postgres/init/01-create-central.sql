-- SISLAC — inicialização do cluster PostgreSQL.
--
-- Roda automaticamente uma única vez, na primeira subida do container do
-- serviço `postgres`. Cria o papel de aplicação com privilégio mínimo e o
-- banco `sislac_central`. Bancos de tenants (`sislac_t_XXXX`) NÃO nascem
-- aqui — eles são criados depois pelo pipeline de provisionamento do
-- Laravel (Fase 1).
--
-- ATENÇÃO: o password abaixo vem da variável de ambiente SISLAC_APP_PASSWORD.
-- Em produção o valor está no `.env` do host, nunca no repositório.

\set app_password `echo "$SISLAC_APP_PASSWORD"`

DO $$
BEGIN
   IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'sislac_app') THEN
      EXECUTE format(
         'CREATE ROLE sislac_app WITH LOGIN PASSWORD %L NOCREATEDB NOCREATEROLE NOSUPERUSER NOREPLICATION',
         current_setting('SISLAC_APP_PASSWORD', true)
      );
   END IF;
END
$$;

SELECT 'CREATE DATABASE sislac_central OWNER sislac_app ENCODING UTF8 TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sislac_central')
\gexec

\connect sislac_central

-- Extensões usadas pelo banco central.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS citext;

GRANT ALL ON SCHEMA public TO sislac_app;
