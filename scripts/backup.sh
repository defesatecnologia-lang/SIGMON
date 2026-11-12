#!/bin/bash
# ============================================
# Backup do SIGMON — banco MySQL + uploads
# ============================================
# Faz o dump lógico do banco (serviço `db`) e empacota os uploads
# (anexos/fotos do serviço `app`) usando o docker compose do projeto.
#
# Uso:
#   ./scripts/backup.sh                                   # produção (docker-compose.prod.yml)
#   COMPOSE_FILE=docker-compose.yml ./scripts/backup.sh   # desenvolvimento
#
# Variáveis:
#   COMPOSE_FILE    Arquivo compose a usar (default: docker-compose.prod.yml)
#   BACKUP_DIR      Diretório de destino  (default: /opt/backups/sigmon)
#   RETENTION_DAYS  Dias de retenção      (default: 7)
#
# Cron (diário às 02:00, executar na raiz do projeto):
#   0 2 * * * cd /opt/sigmon && ./scripts/backup.sh >> /var/log/sigmon-backup.log 2>&1
# ============================================
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
BACKUP_DIR="${BACKUP_DIR:-/opt/backups/sigmon}"
RETENTION_DAYS="${RETENTION_DAYS:-7}"
DATE="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="${BACKUP_DIR}/backup.log"

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "ERRO: $COMPOSE_FILE não encontrado (execute a partir da raiz do projeto)." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

compose() {
    docker compose -f "$COMPOSE_FILE" "$@"
}

log "=== Iniciando backup SIGMON ($COMPOSE_FILE) ==="

# --- Banco de dados ---------------------------------------------------------
# As credenciais vêm do próprio container `db` (MYSQL_USER/MYSQL_PASSWORD/
# MYSQL_DATABASE definidos no compose), evitando duplicar segredos no host.
DB_FILE="${BACKUP_DIR}/sigmon-db_${DATE}.sql.gz"
log "Dump do banco (serviço 'db')..."
if ! compose exec -T db sh -c \
    'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --quick --routines --triggers --events \
        -u"${MYSQL_USER:-root}" "${MYSQL_DATABASE:-cftv_gml}"' \
    | gzip > "$DB_FILE"; then
    log "ERRO: falha no dump do banco"
    rm -f "$DB_FILE"
    exit 1
fi

if ! gzip -t "$DB_FILE" 2>/dev/null; then
    log "ERRO: dump do banco corrompido"
    rm -f "$DB_FILE"
    exit 1
fi
log "Banco salvo: $DB_FILE ($(du -h "$DB_FILE" | cut -f1))"

# --- Uploads (anexos, fotos de manutenção) ----------------------------------
UP_FILE="${BACKUP_DIR}/sigmon-uploads_${DATE}.tar.gz"
log "Dump dos uploads (serviço 'app')..."
if ! compose exec -T app tar czf - -C /var/www/html/public/uploads . > "$UP_FILE"; then
    log "ERRO: falha no backup dos uploads"
    rm -f "$UP_FILE"
    exit 1
fi
log "Uploads salvos: $UP_FILE ($(du -h "$UP_FILE" | cut -f1))"

# --- Retenção ---------------------------------------------------------------
log "Removendo backups com mais de ${RETENTION_DAYS} dias..."
while IFS= read -r old; do
    log "Removido: $old"
done < <(find "$BACKUP_DIR" -type f \( -name "*.sql.gz" -o -name "*.tar.gz" \) \
    -mtime +"${RETENTION_DAYS}" -print -delete)

log "=== Backup concluído ==="
