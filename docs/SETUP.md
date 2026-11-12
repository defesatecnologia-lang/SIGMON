# Setup — SIGMON

Guia de instalação em Linux (produção e desenvolvimento). Cobre a configuração
do `.env`, dependências (Composer + npm), build do frontend (Vite) e as duas
formas de execução: Docker (recomendado) ou servidor web nativo.

---

## 1. Arquitetura de Configuração

```
Instalação manual (Linux)
└── /etc/sigmon/
    └── .env

Docker (Linux)
└── <raiz do projeto>/
    └── .env          # lido pelo docker compose
```

> O arquivo `.env` **não é versionado**. Na instalação manual ele fica fora da
> raiz do projeto (`/etc/sigmon/.env`); no Docker ele fica na raiz, mas está
> coberto pelo `.gitignore`.
>
> Em containers, as variáveis também podem ser passadas diretamente pelo
> ambiente do Compose — é o que o `docker-compose.prod.yml` faz.

---

## 2. Pré-requisitos

| Ferramenta | Versão mínima | Notas |
|-------------|---------------|-------|
| PHP         | 8.4+          | Extensões: pdo_mysql, apcu, openssl, mbstring, fileinfo |
| Composer    | 2.4+          | Para `composer install` e `composer audit` |
| Node.js     | 20 LTS+       | Para `npm install && npm run build` |
| MySQL/MariaDB | 8.4 LTS / 10.5+ | Schema em `config/DB/cftv_gml.sql` |
| Apache      | 2.4+          | mod_rewrite, mod_deflate, mod_brotli (opcional) |
| Docker + Compose | Engine 24+ / Compose v2 | Alternativa recomendada para produção |

---

## 3. Opção A — Docker (recomendado)

### 3.1 Produção (Nginx + PHP-FPM + MySQL)

```bash
# 1) Clone o repositório
git clone https://github.com/defesatecnologia-lang/sigmon.git
cd sigmon

# 2) Crie o .env a partir do template de produção
cp .env.prod.example .env
chmod 600 .env

# 3) Edite as variáveis obrigatórias
#    DB_PASS, DB_ROOT_PASSWORD, APP_PORT, APP_ALLOWED_ORIGINS e DB_DATA_PATH
#    Gere senhas fortes com: openssl rand -hex 24
nano .env

# 4) Crie o diretório de dados do MySQL (UID 999 do container)
sudo mkdir -p /mnt/sstv-dados/mysql
sudo chown 999:999 /mnt/sstv-dados/mysql

# 5) Suba os containers
docker compose -f docker-compose.prod.yml up -d --build

# 6) Acompanhe os logs
docker compose -f docker-compose.prod.yml logs -f app
```

Acesse `http://<IP>:<APP_PORT>` (padrão `3000`).

### 3.2 Desenvolvimento (Apache + PHP 8.5 + MySQL 8.4)

```bash
docker compose up -d
# Acessar: http://localhost
```

O `docker-compose.yml` usa `network_mode: host` (Linux): o MySQL fica em
`127.0.0.1:3306` e o Apache na porta 80 do host. O código é montado via bind
mount, então alterações refletem sem rebuild.

---

## 4. Opção B — Instalação manual (Linux)

```bash
# 1) Clone o repositório
git clone https://github.com/defesatecnologia-lang/sigmon.git
cd sigmon

# 2) Crie a pasta externa de configuração
sudo mkdir -p /etc/sigmon
sudo chown www-data:www-data /etc/sigmon
sudo chmod 700 /etc/sigmon

# 3) Copie e edite o .env
sudo cp .env.example /etc/sigmon/.env
sudo chown www-data:www-data /etc/sigmon/.env
sudo chmod 600 /etc/sigmon/.env
sudo nano /etc/sigmon/.env   # preencha as credenciais

# 4) Instale dependências PHP
composer install --no-interaction --prefer-dist

# 5) Instale dependências Node e gere o build do frontend
npm install
npm run build

# 6) Importe o schema do banco
mysql -u root -p < config/DB/cftv_gml.sql
#    Confirme o estado das migrations:
php database/migrate.php --status

# 7) Ajuste permissões de storage/cache
sudo chown -R www-data:www-data storage
sudo chmod -R 775 storage

# 8) Reinicie o Apache
sudo systemctl restart apache2
```

Acesse: `http://sigmon.local` (ou o IP/host configurado no VirtualHost).

> Para desenvolvimento rápido sem Apache, use o servidor embutido:
> `php -S localhost:3080 -t public/` e acesse `http://localhost:3080`.

---

## 5. Variáveis do `.env`

```env
SIGMON_ENV=production                   # development | testing | production
DB_HOST=localhost                       # no Docker: db
DB_PORT=3306
DB_NAME=cftv_gml
DB_USER=cftv_user
DB_PASS=sua_senha_forte                 # mínimo 32 caracteres
DB_ROOT_PASSWORD=senha_root_forte       # usado pelo Compose
APP_TIMEZONE=America/Sao_Paulo
SIGMON_SESSION_TIMEOUT=3600             # segundos de inatividade
SIGMON_SESSION_ABSOLUTE_TIMEOUT=28800   # 8h — expiração absoluta
SIGMON_CSP_ALLOW_INLINE_STYLES=0        # mantenha 0 (CSP estrita)
SIGMON_HTTP_ONLY=1                      # 1 = HTTP direto; 0 = HTTPS em 8443
APP_PORT=3000                           # porta HTTP publicada no host
APP_ALLOWED_ORIGINS=http://10.10.10.208:3000
DB_DATA_PATH=/mnt/sstv-dados/mysql      # dados do MySQL (Compose de produção)
```

---

## 6. Solução de Problemas

### "Arquivo de configuração não encontrado"
- Confirme `ls -l /etc/sigmon/.env` (permissão `600`).
- No Docker, verifique se o `.env` está na raiz do projeto e se as variáveis
  chegaram ao container: `docker compose -f docker-compose.prod.yml config`.

### "Configurações ausentes: DB_PASS"
O `.env` existe mas não contém a variável `DB_PASS`. Adicione a linha.

### "composer: command not found"
```bash
sudo apt install composer -y
```

### "npm: command not found"
Instale Node.js 20 LTS+ (via nvm ou apt).

### Build do Vite falha
- Apague `node_modules` e `dist`: `rm -rf node_modules dist`
- Reinstale: `npm install && npm run build`

### `php database/migrate.php --status` mostra itens pendentes
Rode `php database/migrate.php` para aplicar. Se a base foi criada pelo
`cftv_gml.sql` e ainda houver pendências, confirme se o dump importado está
completo (inclui `CREATE TABLE migrations` + `INSERT INTO migrations`).

### Docker: permissão negada no diretório de dados
O MySQL roda como UID/GID `999`:
```bash
sudo chown -R 999:999 /mnt/sstv-dados/mysql
```

---

## 7. Segurança

- **Nunca** commite arquivos `.env`
- **Nunca** coloque credenciais no código
- Use senhas fortes (mínimo 32 caracteres)
- Em produção, defina `SIGMON_ENV=production`
- Permissões restritivas: `chmod 600 .env`
- Em produção, ative OPcache — valide com `php scripts/check-opcache.php`
  (exit 1 se OPcache estiver OFF)

---

## 8. Próximos passos

- `php scripts/check-opcache.php` — smoke test OPcache
- `composer audit` — checar vulnerabilidades em dependências PHP
- Configurar VirtualHost do Apache/Nginx apontando para `public/`
- Ver `docs/SECURITY_ENV.md` para detalhes de segurança do `.env`
- Ver `README.md` para visão geral, API e estrutura do projeto
