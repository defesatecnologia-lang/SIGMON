#!/bin/sh
# ============================================
# Entrypoint do container app (Nginx + PHP-FPM).
# Roda como www-data (uid 33) — nao escala privilegio.
#
# Modos:
#   - SIGMON_HTTP_ONLY=1 (padrao neste deploy por IP): HTTP na 8080 servindo a app.
#   - SIGMON_HTTP_ONLY=0: HTTPS na 8443 + redirect 301 de 8080 -> HTTPS.
#
# Em modo TLS, garante certificados em /etc/nginx/certs/:
#   - usa o montado/CA interna se existir (server.crt/server.key);
#   - caso contrario gera self-signed (fallback para intranet).
# ============================================
set -e

CONF_DIR="${CONF_DIR:-/run/nginx_conf}"
CERT_DIR="/etc/nginx/certs"
CERT_FILE="$CERT_DIR/server.crt"
KEY_FILE="$CERT_DIR/server.key"
LOCATIONS_CONF="/etc/nginx/locations.conf"
HTTP_ONLY="${SIGMON_HTTP_ONLY:-1}"
# Normaliza o valor: remove CR (arquivos .env editados no Windows) e espacos.
# Sem isso, "1\r" nao casa com o case abaixo e o container sobe em modo TLS,
# devolvendo 301 em todas as requisicoes HTTP.
HTTP_ONLY=$(printf '%s' "$HTTP_ONLY" | tr -d '\r' | tr -d '[:space:]')
echo "[entrypoint] SIGMON_HTTP_ONLY='$HTTP_ONLY'"

mkdir -p "$CONF_DIR" "$CERT_DIR"

case "$HTTP_ONLY" in
    1|true|TRUE|yes|YES|on|ON)
        cat > "$CONF_DIR/server.conf" <<EOF
server {
    listen 8080 default_server;
    server_name _;

    include $LOCATIONS_CONF;
}
EOF
        echo "[entrypoint] Modo HTTP_ONLY ativo — servindo aplicacao em 8080 (sem redirect TLS)."
        ;;
    *)
        if [ ! -s "$CERT_FILE" ] || [ ! -s "$KEY_FILE" ]; then
            echo "[entrypoint] Nenhum certificado em $CERT_DIR — gerando self-signed (substitua por CA interna se necessario)."
            openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
                -keyout "$KEY_FILE" -out "$CERT_FILE" \
                -subj "/C=BR/O=Sistema Cameras e Alarmes/CN=sigmon" \
                >/dev/null 2>&1
            chmod 600 "$KEY_FILE"
            chmod 644 "$CERT_FILE"
        fi

        cat > "$CONF_DIR/server.conf" <<EOF
server {
    listen 8080 default_server;
    server_name _;
    return 301 https://\$host\$request_uri;
}

server {
    listen 8443 ssl default_server;
    server_name _;

    ssl_certificate     $CERT_FILE;
    ssl_certificate_key $KEY_FILE;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;
    ssl_prefer_server_ciphers off;

    include $LOCATIONS_CONF;
}
EOF
        echo "[entrypoint] Modo TLS ativo — HTTPS em 8443 com redirect de 8080."
        ;;
esac

echo "[entrypoint] Config de servidor gerada em $CONF_DIR/server.conf"

nginx -g 'daemon off;' &
exec php-fpm --nodaemonize