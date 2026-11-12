#!/usr/bin/env bash
#
# Deploy manual do SIGMON no servidor.
#
# A imagem é construída/publicada pelo workflow .github/workflows/cd.yml a
# cada merge na main. Este script NÃO builda nada e NÃO mexe no Git: ele
# apenas baixa a imagem publicada, recria o container "app" quando necessário
# e registra a versão implantada em DEPLOYED_VERSION.
#
# Uso (no servidor, após atualizar o checkout):
#   cd /opt/sistemas/sigmon
#   git fetch origin && git reset --hard origin/main
#   ./scripts/server/deploy.sh
#
# Variáveis de ambiente (opcionais):
#   SIGMON_DIR      diretório do projeto       (default: /opt/sistemas/sigmon)
#   COMPOSE_FILE    arquivo compose            (default: docker-compose.prod.yml)
#   HEALTH_TIMEOUT  segundos aguardando health (default: 120)
#
set -euo pipefail

SIGMON_DIR="${SIGMON_DIR:-/opt/sistemas/sigmon}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-120}"

log() {
    printf '[%s] %s\n' "$(date -Is)" "$*"
}

die() {
    log "ERRO: $*" >&2
    exit 1
}

cd "$SIGMON_DIR" || die "diretorio nao encontrado: $SIGMON_DIR"
[ -f "$COMPOSE_FILE" ] || die "arquivo compose nao encontrado: $SIGMON_DIR/$COMPOSE_FILE"
[ -f .env ] || die "arquivo .env nao encontrado em $SIGMON_DIR"

compose() {
    docker compose -f "$COMPOSE_FILE" "$@"
}

# Le uma variavel simples do .env sem "source" (nao executa conteudo
# arbitrario e nao quebra com valores contendo espacos).
env_value() {
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" .env | tail -n1 | tr -d "\"'[:space:]"
}

APP_TAG="$(env_value APP_TAG)"
APP_TAG="${APP_TAG:-latest}"
GITHUB_REPOSITORY="$(env_value GITHUB_REPOSITORY)"
GITHUB_REPOSITORY="${GITHUB_REPOSITORY:-defesatecnologia-lang/sigmon}"
IMAGE_REF="ghcr.io/${GITHUB_REPOSITORY}:${APP_TAG}"

log "Iniciando deploy (dir=$SIGMON_DIR, imagem=$IMAGE_REF)"

# ---------------------------------------------------------------------------
# 1. Estado atual e pull da imagem
# ---------------------------------------------------------------------------
current_container="$(compose ps -q app 2>/dev/null || true)"
before_image=""
if [ -n "$current_container" ]; then
    before_image="$(docker inspect --format '{{.Image}}' "$current_container" 2>/dev/null || true)"
fi

log "Baixando imagem do GHCR..."
if ! compose pull app; then
    die "falha no docker compose pull (verifique rede e 'docker login ghcr.io')"
fi

target_image="$(docker image inspect --format '{{.Id}}' "$IMAGE_REF" 2>/dev/null || true)"
[ -n "$target_image" ] || die "imagem nao encontrada apos o pull: $IMAGE_REF"

if [ -n "$current_container" ] && [ "$before_image" = "$target_image" ]; then
    log "Imagem inalterada (${target_image:7:12}); nada a recriar."
else
    if [ -z "$current_container" ]; then
        log "Container app ausente; subindo."
    else
        log "Nova imagem detectada (${before_image:7:12} -> ${target_image:7:12}); recriando app."
    fi
    # --no-build garante que nada seja compilado no servidor.
    compose up -d --no-build --force-recreate app
fi

# ---------------------------------------------------------------------------
# 2. Aguarda o healthcheck
# ---------------------------------------------------------------------------
container="$(compose ps -q app 2>/dev/null || true)"
if [ -n "$container" ]; then
    waited=0
    while :; do
        status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || echo unknown)"
        case "$status" in
            healthy|running)
                log "Container app: $status"
                break
                ;;
            unhealthy|exited|dead)
                log "ERRO: container app ficou '$status' apos o deploy." >&2
                compose logs --tail=50 app >&2 || true
                exit 1
                ;;
        esac
        if [ "$waited" -ge "$HEALTH_TIMEOUT" ]; then
            log "AVISO: healthcheck nao ficou pronto em ${HEALTH_TIMEOUT}s (status: $status)." >&2
            break
        fi
        sleep 5
        waited=$((waited + 5))
    done
fi

# ---------------------------------------------------------------------------
# 3. Registra a versao implantada
# ---------------------------------------------------------------------------
version="$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.version" }}' "$IMAGE_REF" 2>/dev/null || true)"
case "$version" in
    ""|"<no value>")
        if [ "$APP_TAG" != "latest" ]; then
            version="$APP_TAG"
        else
            version="sha-${target_image:7:12}"
        fi
        ;;
esac

# Normaliza para o mesmo formato da tag Git (vX.Y.Z).
case "$version" in
    sha-*|v*) ;;
    *) version="v$version" ;;
esac

printf '%s\n' "$version" > DEPLOYED_VERSION.tmp
mv DEPLOYED_VERSION.tmp DEPLOYED_VERSION

log "Deploy concluido. Versao implantada: $version (imagem ${target_image:7:12})"
