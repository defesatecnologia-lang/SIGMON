# Deploy — CI/CD e atualização manual no servidor

## Visão geral

```
fix/dev-development --(PR)--> main --(GitHub Actions)--> tag vX.Y.Z + imagem GHCR
                                                              |
                                              atualização manual no servidor
                                                              |
                                        ./scripts/server/deploy.sh
                                                              |
                                              DEPLOYED_VERSION no servidor
```

- **O servidor faz deploy somente a partir da `main`.**
- A `main` é a única origem de deploy: commits entram por Pull Request.
- O build da imagem acontece **no GitHub Actions**, nunca no servidor.
- Não há timer/systemd: a atualização é executada manualmente no terminal.
- Cada atualização grava a versão efetivamente implantada em `DEPLOYED_VERSION`.

## Versionamento (semver patch automático)

A cada push/merge na `main`, o workflow `.github/workflows/cd.yml`:

1. Calcula a próxima versão patch a partir da última tag `vX.Y.Z` (`v1.0.0`, `v1.0.1`, ...).
2. Cria a tag anotada e uma GitHub Release.
3. Publica a imagem no GHCR com as tags:
   - `latest`
   - `vX.Y.Z` (mesmo nome da tag Git)
   - `X.Y.Z`
   - `X.Y` (ex.: `1.0`)
   - `sha-<commit>`

A tag Git é criada pelo CI no merge. O comando de atualização no servidor **não**
cria tag Git: ele apenas baixa a imagem e registra a versão em `DEPLOYED_VERSION`.

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

Estrutura esperada:

```
/opt/sistemas/sigmon/
├── docker-compose.prod.yml
├── .env                       # criado manualmente (não versionado)
├── DEPLOYED_VERSION           # gerado pelo deploy (não versionado)
├── scripts/server/deploy.sh
└── config/DB/cftv_gml.sql
```

> **Dados do banco:** `docker-compose.prod.yml` monta
> `${DB_DATA_PATH:-/mnt/sstv-dados/mysql}` em `/var/lib/mysql` (não usa volume nomeado).
> Garanta que `/mnt/sstv-dados` esteja montado (ex.: `/etc/fstab`).
> O `01-schema.sql` só é executado quando o diretório de dados está vazio.

### Acesso ao GHCR

Se o pacote da imagem for privado, autentique uma vez com o usuário que roda o deploy:

```bash
# PAT clássico com escopo read:packages
echo "$CR_PAT" | docker login ghcr.io -u SEU_USUARIO --password-stdin
```

Com pacote público não é necessário login no servidor.

Se o CI falhar no push com `permission_denied: write_package`, há duas opções:

1. Em **Package settings → Manage Actions access**, adicione o repositório
   `defesatecnologia-lang/sigmon` com papel **Write**.
2. Crie um PAT clássico com escopo `write:packages` e salve como secret
   `GHCR_TOKEN` no repositório. Opcionalmente defina `GHCR_USERNAME` com o
   dono do token. O workflow usa esse token automaticamente quando ele existe.

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

# latest = acompanha a versão mais nova; vX.Y.Z = versão fixada (rollback)
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

## Atualização via terminal

Sempre a partir da `main`:

```bash
cd /opt/sistemas/sigmon
git fetch origin
git reset --hard origin/main      # preserva arquivos não versionados/ignorados

./scripts/server/deploy.sh
cat DEPLOYED_VERSION
```

O script `deploy.sh`:

1. lê `APP_TAG` do `.env` (default `latest`);
2. executa `docker compose pull app`;
3. compara a imagem em execução com a baixada;
4. se mudou, executa `docker compose up -d --no-build --force-recreate app`;
5. aguarda o healthcheck;
6. grava a versão da imagem em `DEPLOYED_VERSION`.

Arquivos **não versionados ou ignorados** (`.env`, `DEPLOYED_VERSION`, arquivos
criados manualmente no servidor) são preservados pelo `git reset --hard`.

### Migrations automáticas no deploy

As migrations pendentes são aplicadas **automaticamente na primeira requisição**
após a atualização do container (`inc/auto_migrate.php`, protegido por
`GET_LOCK('cftv_migrate')`). Nada precisa ser executado à mão.

Exceção importante: a migration `2026_11_14_101000` repara textos gravados com
acentos "duplicados" (`AmbulÃ¢ncias` em vez de `Ambulâncias`). Ela é conservadora
— só altera valores que voltam a ficar UTF-8 válidos —, mas, se a base tiver
muitos dados antigos, compile antes a simulação:

```bash
# dentro do container app (roda após a atualização, sem alterar nada)
docker compose -f docker-compose.prod.yml exec app php scripts/repair-mojibake.php --dry-run
```

### Sem o script

```bash
cd /opt/sistemas/sigmon
git fetch origin && git reset --hard origin/main
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
./scripts/server/deploy.sh
cat DEPLOYED_VERSION    # v1.0.2
```

Para voltar a acompanhar a `latest`:

```bash
sed -i 's/^APP_TAG=.*/APP_TAG=latest/' .env
./scripts/server/deploy.sh
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
| `falha no docker compose pull` | Sem rede, pacote privado sem login, ou tag inexistente. Rode `docker login ghcr.io` e verifique `APP_TAG`. |
| CI falha com `write_package` | Adicione o repositório em **Manage Actions access** com Write, ou configure o secret `GHCR_TOKEN` com `write:packages`. |
| Imagem nova não aplica | Confirme que o merge chegou na `main` e que o workflow CD publicou a imagem. `docker compose pull app` deve baixar. |
| Container unhealthy | `docker compose logs --tail=100 app`; o script também imprime os últimos logs. |
| `DEPLOYED_VERSION` não atualiza | O deploy falhou antes da etapa final; veja a saída do script. |
