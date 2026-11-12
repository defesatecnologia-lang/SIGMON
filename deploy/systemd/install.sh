#!/usr/bin/env bash
#
# Instala/atualiza o timer systemd de auto-deploy do SIGMON.
#
# Uso (no servidor):
#   sudo ./deploy/systemd/install.sh [/opt/sistemas/sigmon]
#
# O service roda como o dono do diretorio (para usar a chave SSH e o login
# do GHCR desse usuario). Para forcar outro usuario:
#   sudo SIGMON_USER=root ./deploy/systemd/install.sh /opt/sistemas/sigmon
#
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute com sudo: sudo $0 [diretorio]" >&2
    exit 1
fi

SIGMON_DIR="${1:-/opt/sistemas/sigmon}"
SIGMON_USER="${SIGMON_USER:-$(stat -c %U "$SIGMON_DIR" 2>/dev/null || echo root)}"
SCRIPT="$SIGMON_DIR/scripts/server/auto-deploy.sh"
UNIT_DIR="/etc/systemd/system"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"

[ -d "$SIGMON_DIR" ] || { echo "Diretorio nao encontrado: $SIGMON_DIR" >&2; exit 1; }
[ -f "$SCRIPT" ] || { echo "Script nao encontrado: $SCRIPT" >&2; exit 1; }

chmod +x "$SCRIPT"

# Permite que outro usuario (ex.: root) use um checkout de dono diferente.
if ! git config --system --get-all safe.directory 2>/dev/null | grep -qxF "$SIGMON_DIR"; then
    git config --system --add safe.directory "$SIGMON_DIR"
fi

# Ajusta caminho e usuario do service.
for unit in sigmon-deploy.service sigmon-deploy.timer; do
    sed -e "s#/opt/sistemas/sigmon#${SIGMON_DIR}#g" \
        -e "s#^User=.*#User=${SIGMON_USER}#" \
        "$SRC_DIR/$unit" > "$UNIT_DIR/$unit"
    chmod 0644 "$UNIT_DIR/$unit"
done

if [ "$SIGMON_USER" != "root" ] && ! id -nG "$SIGMON_USER" | tr ' ' '\n' | grep -qx docker; then
    echo "AVISO: '$SIGMON_USER' nao esta no grupo docker." >&2
    echo "       Rode: sudo usermod -aG docker $SIGMON_USER && relogar" >&2
fi

systemctl daemon-reload
systemctl enable --now sigmon-deploy.timer

echo "Timer instalado (usuario=$SIGMON_USER, dir=$SIGMON_DIR). Proxima execucao:"
systemctl list-timers sigmon-deploy.timer --no-pager || true
echo
echo "Acompanhe com: journalctl -u sigmon-deploy -f"
