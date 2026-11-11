# Deploy — CD via GitHub Actions

## Fluxo

1. Push de tag `v*` → job CD builda a imagem Docker (`docker/Dockerfile.nginx`) e publica em `ghcr.io/<repo>/sigmon` (tags semver + sha).
2. Push na `main` → CI executa lint, composer/npm audit, build Docker (Nginx) e Trivy (sem publicar).

> **Nota:** o CD **não** executa deploy automático via SSH. O pull e restart no servidor é feito manualmente (ver "Atualização manual" abaixo). O job `deploy` do workflow apenas publica a imagem no GHCR.

## TLS por padrão (intranet)

O container `app` termina TLS no próprio Nginx:

| Host | Container | Finalidade                     |
|------|-----------|--------------------------------|
| `${APP_PORT:-80}`   | `8080` | Redirect 301 → HTTPS      |
| `${APP_HTTPS_PORT:-443}` | `8443` | HTTPS (TLS) com a aplicação |

- **Sem certificado montado** → o entrypoint gera certificado **self-signed** automaticamente (o browser mostra aviso; aceitável em intranet corporativa; o acesso funciona via `https://<servidor>/`).
- **Com CA interna** → copie `server.crt`/`server.key` da sua CA para o volume `nginx_certs` e reinicie:

```bash
docker cp server.crt sigmon-app-1:/etc/nginx/certs/server.crt
docker cp server.key sigmon-app-1:/etc/nginx/certs/server.key
docker compose -f docker-compose.prod.yml restart app
```

- Os clientes devem confiar na CA interna (ou aceitar o self-signed).
- Cookies de sessão são `Secure` (o Nginx detecta `$https` automaticamente).

## Pré-requisitos no servidor (Ubuntu Server)

```bash
# Diretório da aplicação
sudo mkdir -p /opt/sigmon
sudo chown $USER:$USER /opt/sigmon

# Disco externo para dados do MySQL (ajuste o ponto de montagem conforme o servidor)
# O container do MySQL roda como uid/gid 999.
sudo mkdir -p /mnt/sstv-dados/mysql
sudo chown -R 999:999 /mnt/sstv-dados/mysql
sudo chmod 750 /mnt/sstv-dados/mysql

# Estrutura esperada em /opt/sigmon
/opt/sigmon/
├── docker-compose.prod.yml    # copiado do repositório
├── .env                       # criado manualmente (não versionado)
└── config/
    └── DB/
        └── cftv_gml.sql       # schema inicial (cópia do repo)
```

> **Dados do banco:** `docker-compose.prod.yml` monta `${DB_DATA_PATH:-/mnt/sstv-dados/mysql}` em `/var/lib/mysql` (não usa volume nomeado). Garanta que `/mnt/sstv-dados` esteja montado (ex.: entrada em `/etc/fstab`) antes de subir os containers. O `01-schema.sql` em `docker-entrypoint-initdb.d` só é executado quando o diretório de dados está vazio (primeira inicialização).

## Arquivo `.env`

Crie `/opt/sigmon/.env` (baseado em `.env.prod.example`, com senhas fortes e únicas):

```bash
DB_ROOT_PASSWORD=senha_root_forte
DB_PASS=senha_usuario_forte
DB_NAME=cftv_gml
DB_USER=cftv_user

# Caminho dos dados do MySQL no host (disco externo)
DB_DATA_PATH=/mnt/sstv-dados/mysql

# Acesso HTTPS/HTTP no host (defaults: 443 / 80)
APP_PORT=80
APP_HTTPS_PORT=443

# Sentry (opcional; em branco = desativado)
SENTRY_DSN=

# IPs de proxy confiáveis (vazio = nenhum)
PROXY_TRUSTED_IPS=
```

## Instalação inicial (primeira vez)

```bash
cd /opt/sigmon

# Instalar Docker se não tiver
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER

# Baixar docker-compose.prod.yml e .env de exemplo do repositório
curl -O https://raw.githubusercontent.com/elciomsantos/sigmon/main/docker-compose.prod.yml
curl -o .env.prod.example https://raw.githubusercontent.com/elciomsantos/sigmon/main/.env.prod.example
cp .env.prod.example .env
# editar .env com senhas e DB_DATA_PATH

# Schema inicial (usado pelo MySQL apenas na primeira inicialização do diretório de dados)
mkdir -p config/DB
curl -o config/DB/cftv_gml.sql https://raw.githubusercontent.com/elciomsantos/sigmon/main/config/DB/cftv_gml.sql

# Subir (imagem do GHCR + schema init no MySQL se DB_DATA_PATH estiver vazio)
docker compose -f docker-compose.prod.yml up -d
```

Acesse `https://<servidor>/` (aceite o certificado na primeira vez).

## Testar manualmente

```bash
docker compose -f docker-compose.prod.yml ps   # db + app healthy
curl -k https://localhost/                     # 200 (front controller)
curl -I http://localhost/                      # 301 -> https
```

## Atualização manual (servidor)

Após publicar uma nova imagem no GHCR (via tag `v*`), atualize o servidor:

```bash
cd /opt/sigmon
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

Ou, apontando para uma tag específica:

```bash
# No REPOSITORY ghcr.io, defina APP_TAG (default: latest)
APP_TAG=v1.0.1 docker compose -f docker-compose.prod.yml up -d
```

> **Nota:** o compose usa `image: ghcr.io/elciomsantos/sigmon:${APP_TAG:-latest}`.
> Defina `GITHUB_REPOSITORY` para apontar para outro repositório GHCR.