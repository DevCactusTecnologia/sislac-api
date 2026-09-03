# pgAdmin via túnel SSH

O pgAdmin roda dentro do Docker Compose, escutando apenas em `127.0.0.1:5050`
da VPS. Ninguém na internet consegue vê‑lo. Para acessar do seu navegador,
você abre um túnel SSH que expõe aquela porta na sua máquina local.

## Abrir o túnel

```bash
ssh -N -L 5050:localhost:5050 sislac@<ip-ou-hostname-da-vps>
```

Deixe o terminal aberto — o túnel vive enquanto essa conexão SSH estiver
ativa. Para fechar, `Ctrl+C`.

## Acessar

No navegador do seu computador, abra:

```
http://localhost:5050
```

Login: use `PGADMIN_EMAIL` e `PGADMIN_PASSWORD` do `.env` da VPS.

## Cadastrar o Postgres da VPS no pgAdmin

Na primeira vez, cadastre a conexão dentro do pgAdmin:

1. **Object → Register → Server…**
2. Aba **General**: nome `SISLAC (VPS)`.
3. Aba **Connection**:
   - **Host name/address**: `postgres`  (é o nome do serviço no compose; como
     pgAdmin está na mesma rede Docker, resolve internamente)
   - **Port**: `5432`
   - **Maintenance database**: `sislac_central`
   - **Username**: `sislac_app`
   - **Password**: valor de `DB_PASSWORD` no `.env` da VPS
   - Marque **Save password** para não pedir toda vez.
4. Salve.

Todos os bancos aparecem numa árvore: `sislac_central`, `sislac_t_1001`,
`sislac_t_1002`, … Clique em qualquer um para inspecionar, editar, rodar SQL.

## Dicas

- **Query Tool** (ícone de raio, ou `Alt+Shift+Q`) abre um editor SQL.
- **Dashboard** de cada banco mostra conexões ativas, cache, IOPS.
- Não crie tabelas nem edite dados de paciente pelo pgAdmin em produção; use
  os endpoints da API. O pgAdmin é para inspeção, debug e ocasionais tarefas
  administrativas de plataforma.
- Se preferir uma interface mais rápida para queries do dia a dia, o DBeaver
  costuma render melhor com muitas abas abertas. Ver [CONECTAR_DBEAVER.md](CONECTAR_DBEAVER.md).

## Alternativa: expor num subdomínio

Se você trabalha de vários lugares e o túnel SSH virar atrito, dá para
publicar em `pgadmin.sislac.com.br` com TLS (Let's Encrypt), autenticação do
pgAdmin com MFA e allowlist de IP. **Não recomendado como padrão**: mais
superfície de ataque para dado sensível de saúde. Só ligue se realmente
precisar, e mantenha allowlist ativa.
