# Deploy — CI/CD e auto-deploy no servidor

## Visão geral

```
fix/dev-development --(PR)--> main --(GitHub Actions)--> tag vX.Y.Z + imagem GHCR
                                                              |
                                            timer systemd (5 min)
                                                              |
                                         docker compose pull app + recreate
                                                              |
                                              DEPLOYED_VERSION no servidor
```

- **O servidor faz deploy somente a partir da `main`.**
- A `main` é a única origem de deploy: commits entram por Pull Request.
- O build da imagem acontece **no GitHub Actions**, nunca no servidor.
- O servidor apenas baixa a imagem publicada no GHCR (`docker compose pull`) e recria o container `app`.
- Cada deploy grava a versão efetivamente implantada em `DEPLOYED_VERSION`.

## Versionamento (semver patch automático)

A cada push/merge na `main`, o workflow `.github/workflows/cd.yml`:

1. Calcula a próxima versão patch a partir da última tag `vX.Y.Z` (`v1.0.0`, `v1.0.1`, `v1.0.2`, ...).
2. Cria a tag anotada e uma GitHub Release (`gh release create`).
3. Publica a imagem no GHCR com as tags:
   - `latest`
   - `vX.Y.Z` (mesmo nome da tag Git)
   - `X.Y.Z`
   - `X.Y` (ex.: `1.0`)
   - `sha-<commit>`

O job de versão e o de publicação da imagem rodam no **mesmo workflow**, porque eventos
disparados pelo `GITHUB_TOKEN` não acionam outros workflows.

Push manual de uma tag `v*` também publica a imagem (útil para release/rollback), sem
gerar uma nova versão automática.

## Pré-requisitos no servidor (Ubuntu Server)

```bash
# Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker "$USER"   # relogar depois

# Diretório da aplicação (clone do repositório, branch main)
sudo mkdir -p /opt/sistemas
sudo chown "$USER:$USER" /opt/sistemas
git clone git@github.com:defesatecnologia-lang/SIGMON.git /opt/sistemas/sigmon
cd /opt/sistemas/sigmon
git checkout main
```

> O timer roda como o **dono do diretório** (`stat -c %U`). Assim ele usa a mesma
> chave SSH e o mesmo login do GHCR desse usuário.

Estrutura esperada:

```
/opt/sistemas/sigmon/
├── docker-compose.prod.yml
├── .env                       # criado manualmente (não versionado)
├── DEPLOYED_VERSION           # gerado pelo auto-deploy (não versionado)
├── deploy/systemd/            # service + timer + instalador
├── scripts/server/auto-deploy.sh
└── config/DB/cftv_gml.sql
```

> **Dados do banco:** `docker-compose.prod.yml` monta
> `${DB_DATA_PATH:-/mnt/sstv-dados/mysql}` em `/var/lib/mysql` (não usa volume nomeado).
> Garanta que `/mnt/sstv-dados` esteja montado (ex.: `/etc/fstab`).
> O `01-schema.sql` só é executado quando o diretório de dados está vazio.

### Acesso ao GHCR

Se o pacote da imagem for privado, autentique uma vez **com o usuário dono do
diretório** (é ele que roda o timer):

```bash
# PAT clássico com escopo read:packages
echo "$CR_PAT" | docker login ghcr.io -u SEU_USUARIO --password-stdin
```

> Se você forçar `SIGMON_USER=root` no instalador, faça o login com `sudo docker login`.

Com pacote público não é necessário login.

## Arquivo `.env`

Crie `/opt/sistemas/sigmon/.env` a partir de `.env.prod.example`:

```bash
cp .env.prod.example .env
nano .env
```

Variáveis principais:

```bash
DB_ROOT_PASSWORD=senha_root_forte
DB_PASS=senha_usuario_forte
DB_NAME=cftv_gml
DB_USER=cftv_user

DB_DATA_PATH=/mnt/sstv-dados/mysql

SIGMON_HTTP_ONLY=1
APP_PORT=3000
APP_ALLOWED_ORIGINS=http://10.10.10.208:3000

# latest = atualiza sozinho; vX.Y.Z = versão fixada (rollback)
APP_TAG=latest
GITHUB_REPOSITORY=defesatecnologia-lang/sigmon
```

## Instalação inicial

```bash
cd /opt/sistemas/sigmon

# Schema inicial (usado pelo MySQL só na primeira inicialização)
ls config/DB/cftv_gml.sql

# Login no GHCR (se o pacote for privado)
# echo "$CR_PAT" | docker login ghcr.io -u SEU_USUARIO --password-stdin

# Primeira subida (baixa a imagem publicada)
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --no-build
```

Acesse `http://10.10.10.208:3000/`.

## Auto-deploy (systemd timer)

Instale o timer uma vez:

```bash
cd /opt/sistemas/sigmon
sudo ./deploy/systemd/install.sh /opt/sistemas/sigmon
```

O instalador:

- marca `scripts/server/auto-deploy.sh` como executável;
- registra `/opt/sistemas/sigmon` como `safe.directory` do Git;
- instala `/etc/systemd/system/sigmon-deploy.{service,timer}`;
- configura o service para rodar como o dono do diretório;
- habilita e inicia o timer (`enable --now`).

O timer roda a cada **5 minutos**. Em cada execução o script:

1. sincroniza o checkout com `origin/main` (quando `SYNC_GIT=1`);
2. executa `docker compose pull app`;
3. compara a imagem em execução com a baixada;
4. se mudou, executa `docker compose up -d --no-build --force-recreate app`;
5. aguarda o healthcheck;
6. grava a versão da imagem em `DEPLOYED_VERSION`.

### Comportamento do `SYNC_GIT`

| Valor | Efeito |
|-------|--------|
| `1` (default no timer) | `git fetch` + checkout/reset para `origin/main`. **Recusa** o deploy se houver alterações locais em arquivos versionados. |
| `0` | Não mexe no Git; apenas baixa/recria a imagem. |

Arquivos **não versionados ou ignorados** (`.env`, `DEPLOYED_VERSION`, arquivos criados
manualmente no servidor) são sempre preservados. Se você editar um arquivo **versionado**
no servidor, o deploy com `SYNC_GIT=1` será bloqueado até resolver o conflito
(commit/stash ou rodar com `SYNC_GIT=0`).

Para alterar o modo, edite o service:

```bash
sudo systemctl edit --full sigmon-deploy.service
# Environment=SYNC_GIT=0
sudo systemctl daemon-reload
```

### Operação

```bash
# Acompanhar deploys em tempo real
journalctl -u sigmon-deploy -f

# Rodar agora (sem esperar o timer)
sudo systemctl start sigmon-deploy.service

# Ver a versão implantada
cat /opt/sistemas/sigmon/DEPLOYED_VERSION

# Estado do timer e do container
systemctl list-timers sigmon-deploy.timer
docker compose -f docker-compose.prod.yml ps
```

## Deploy manual

```bash
cd /opt/sistemas/sigmon
sudo systemctl start sigmon-deploy.service
journalctl -u sigmon-deploy -n 50 --no-pager
```

Ou, sem o timer:

```bash
cd /opt/sistemas/sigmon
git fetch origin && git reset --hard origin/main   # preserva untracked/ignorados
docker compose -f docker-compose.prod.yml pull app
docker compose -f docker-compose.prod.yml up -d --no-build --force-recreate app
```

> `docker compose restart` **não** relê o `.env`. Use `up -d --force-recreate` para
> aplicar uma nova tag.

## Rollback

Fixe a versão desejada no `.env` e rode o deploy:

```bash
cd /opt/sistemas/sigmon
sed -i 's/^APP_TAG=.*/APP_TAG=v1.0.2/' .env
sudo systemctl start sigmon-deploy.service
cat DEPLOYED_VERSION    # v1.0.2
```

Para voltar a acompanhar a `latest`:

```bash
sed -i 's/^APP_TAG=.*/APP_TAG=latest/' .env
sudo systemctl start sigmon-deploy.service
```

## Verificação

```bash
docker compose -f docker-compose.prod.yml ps     # db + app healthy
curl -fsS http://localhost:3000/ >/dev/null && echo OK
cat DEPLOYED_VERSION
docker image inspect ghcr.io/defesatecnologia-lang/sigmon:latest \
  --format '{{ index .Config.Labels "org.opencontainers.image.version" }}'
```

## Solução de problemas

| Sintoma | Causa provável / correção |
|---------|---------------------------|
| `falha no docker compose pull` | Sem rede, pacote privado sem login, ou tag inexistente. Rode `docker login ghcr.io` (mesmo usuário do timer) e verifique `APP_TAG`. |
| `existem alteracoes locais em arquivos versionados` | Arquivo versionado foi editado no servidor. Faça commit/stash ou use `SYNC_GIT=0`. |
| Deploy não roda | `systemctl status sigmon-deploy.timer`; veja `journalctl -u sigmon-deploy`. |
| Imagem nova não aplica | Confirme que o merge chegou na `main` e que o workflow CD publicou a imagem. `docker compose pull app` deve baixar. |
| Container unhealthy | `docker compose logs --tail=100 app`; o script também imprime os últimos logs. |
| `DEPLOYED_VERSION` não atualiza | O deploy falhou antes da etapa final; veja o journal. |
