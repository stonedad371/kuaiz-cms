#!/usr/bin/env bash
# 为 cms.kuaiz.net 安装隔离的静态站点与独立 HTTPS 证书。
set -euo pipefail

DOMAIN="${1:-cms.kuaiz.net}"
SITE_ROOT="${2:-/var/www/cms.kuaiz.net/current}"
NGINX_CONF_DIR="${KEFU_NGINX_CONF_DIR:-/etc/nginx/conf.d}"
ACME_ROOT="${KEFU_ACME_ROOT:-/var/www/certbot}"
CONF_PATH="$NGINX_CONF_DIR/kuaiz-cms-${DOMAIN//./-}.conf"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
CONF_BACKUP=""

restore_nginx() {
  if [ -n "$CONF_BACKUP" ] && [ -f "$CONF_BACKUP" ]; then
    mv "$CONF_BACKUP" "$CONF_PATH"
  elif [ -f "$CONF_PATH" ]; then
    unlink "$CONF_PATH"
  fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
}
trap restore_nginx ERR INT TERM

[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])$ && "$DOMAIN" == *.* ]] || {
  echo "CMS 域名格式不合法" >&2; exit 2;
}
[[ "$SITE_ROOT" == /var/www/*/current ]] || {
  echo "CMS 站点目录必须是 /var/www/<域名>/current" >&2; exit 2;
}
command -v nginx >/dev/null
command -v certbot >/dev/null
test -f "$SITE_ROOT/index.html"

install -d -m 755 "$NGINX_CONF_DIR" "$ACME_ROOT"
if [ -f "$CONF_PATH" ]; then
  CONF_BACKUP="$(mktemp "$NGINX_CONF_DIR/.kuaiz-cms-backup.XXXXXX")"
  cp -a "$CONF_PATH" "$CONF_BACKUP"
fi

cat > "$CONF_PATH" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    access_log /var/log/nginx/kuaiz-cms.access.log;
    location /.well-known/acme-challenge/ { root ${ACME_ROOT}; }
    location / { return 301 https://${DOMAIN}\$request_uri; }
}
EOF
chmod 644 "$CONF_PATH"
nginx -t
systemctl reload nginx

if [ ! -f "$CERT_DIR/fullchain.pem" ]; then
  certbot certonly --webroot -w "$ACME_ROOT" -d "$DOMAIN" \
    --non-interactive --agree-tos --keep-until-expiring
fi

cat > "$CONF_PATH" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    access_log /var/log/nginx/kuaiz-cms.access.log;
    location /.well-known/acme-challenge/ { root ${ACME_ROOT}; }
    location / { return 301 https://${DOMAIN}\$request_uri; }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${DOMAIN};
    root ${SITE_ROOT};
    index index.html;
    access_log /var/log/nginx/kuaiz-cms.access.log;
    error_log /var/log/nginx/kuaiz-cms.error.log;

    ssl_certificate ${CERT_DIR}/fullchain.pem;
    ssl_certificate_key ${CERT_DIR}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL_kuaiz_cms:10m;
    ssl_session_timeout 1d;

    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()" always;
    add_header Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self' https://kuaiz.net; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; upgrade-insecure-requests" always;

    location = /index.html { expires -1; }
    location = /favicon.ico { return 301 /favicon.svg; }
    location = /404.html { internal; }
    location = /releases/current.json { expires -1; try_files \$uri =404; }
    location = /releases/index.json { expires -1; try_files \$uri =404; }
    location ~ ^/releases/[0-9][0-9A-Za-z.-]*/install\.php$ {
        default_type application/octet-stream;
        expires 1y;
        add_header Content-Disposition 'attachment; filename="install.php"' always;
        add_header Strict-Transport-Security "max-age=31536000" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "no-referrer" always;
        try_files \$uri =404;
    }
    location ~ ^/releases/[0-9][0-9A-Za-z.-]*/ {
        expires 1y;
        try_files \$uri =404;
    }
    location ~ ^/trust/ {
        expires 1h;
        try_files \$uri =404;
    }
    location ~* \.(?:css|js|svg|png|jpg|jpeg|webp|ico|woff2)$ {
        expires 1h;
    }
    location / {
        try_files \$uri \$uri/ =404;
    }
    error_page 404 /404.html;
}
EOF
chmod 644 "$CONF_PATH"
nginx -t
systemctl reload nginx

if [ -n "$CONF_BACKUP" ] && [ -f "$CONF_BACKUP" ]; then
  unlink "$CONF_BACKUP"
fi
trap - ERR INT TERM

echo "CMS 官网已安装：https://${DOMAIN}/"
