#!/usr/bin/env bash
# 原子发布 cms-site 静态文件，并安装/刷新独立 Nginx 站点。
set -euo pipefail

REMOTE_HOST="${REMOTE_HOST:?请设置 CMS 官网服务器，例如 root@example.com}"
REMOTE_PORT="${REMOTE_PORT:-22}"
REMOTE_KEY="${REMOTE_KEY:-$HOME/.ssh/id_rsa}"
DOMAIN="${CMS_DOMAIN:-cms.kuaiz.net}"
REMOTE_ROOT="/var/www/$DOMAIN"
RELEASE_ID="$(date +%Y%m%d%H%M%S)"
REMOTE_RELEASE="$REMOTE_ROOT/releases/$RELEASE_ID"
LOCAL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CMS_RELEASE_DIR="${CMS_RELEASE_DIR:-}"
LOCAL_STAGE="$(mktemp -d "${TMPDIR:-/tmp}/kuaiz-cms-site.XXXXXX")"
SSH=(ssh -i "$REMOTE_KEY" -p "$REMOTE_PORT")
PREVIOUS_RELEASE=""
CMS_RELEASE_VERSION=""

cleanup() {
  rm -rf "$LOCAL_STAGE"
}
trap cleanup EXIT

cd "$LOCAL_ROOT"

[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])$ && "$DOMAIN" == *.* ]] || {
  echo "CMS 域名格式不合法" >&2; exit 2;
}
test -f "$LOCAL_ROOT/website/index.html"
test -f "$LOCAL_ROOT/website/styles.css"

rsync -a --delete "$LOCAL_ROOT/website/" "$LOCAL_STAGE/"
install -d -m 755 "$LOCAL_STAGE/contracts"
install -m 644 \
  "$LOCAL_ROOT/contracts/theme-manifest.schema.json" \
  "$LOCAL_STAGE/contracts/theme-manifest-v2.json"
install -m 644 \
  "$LOCAL_ROOT/contracts/extension-manifest.schema.json" \
  "$LOCAL_STAGE/contracts/extension-manifest-v1.json"
install -m 644 "$LOCAL_ROOT/cms-manifest.json" \
  "$LOCAL_STAGE/contracts/cms-manifest.json"
install -m 644 "$LOCAL_ROOT/LICENSE" "$LOCAL_STAGE/license.txt"

if [ -n "$CMS_RELEASE_DIR" ]; then
  CMS_RELEASE_DIR="$(cd "$CMS_RELEASE_DIR" && pwd)"
  uv run python "$LOCAL_ROOT/scripts/verify_php_cms_public_release.py" \
    "$CMS_RELEASE_DIR" >/dev/null
  CMS_RELEASE_VERSION="$(uv run python -c \
    'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8"))["version"])' \
    "$CMS_RELEASE_DIR/releases/current.json")"
  rsync -a "$CMS_RELEASE_DIR/" "$LOCAL_STAGE/"
fi

node --check "$LOCAL_STAGE/release.js"
uv run python "$LOCAL_ROOT/scripts/validate_cms_site.py" "$LOCAL_STAGE"

PREVIOUS_RELEASE="$("${SSH[@]}" "$REMOTE_HOST" \
  "readlink -f '$REMOTE_ROOT/current' 2>/dev/null || true")"
if [ -n "$PREVIOUS_RELEASE" ] && [[ "$PREVIOUS_RELEASE" != "$REMOTE_ROOT"/releases/* ]]; then
  echo "CMS 官网当前版本目录不安全" >&2
  exit 2
fi

"${SSH[@]}" "$REMOTE_HOST" \
  "set -e; install -d -o root -g root -m 755 '$REMOTE_ROOT/releases' '$REMOTE_RELEASE'; \
   if [ -n '$PREVIOUS_RELEASE' ] && [ -d '$PREVIOUS_RELEASE/releases' ]; then cp -a '$PREVIOUS_RELEASE/releases' '$REMOTE_RELEASE/'; fi; \
   if [ -n '$PREVIOUS_RELEASE' ] && [ -d '$PREVIOUS_RELEASE/trust' ]; then cp -a '$PREVIOUS_RELEASE/trust' '$REMOTE_RELEASE/'; fi"

rsync -az -e "ssh -i $REMOTE_KEY -p $REMOTE_PORT" \
  "$LOCAL_STAGE/" "$REMOTE_HOST:$REMOTE_RELEASE/"

"${SSH[@]}" "$REMOTE_HOST" \
  "set -e; find '$REMOTE_RELEASE' -type d -exec chmod 755 {} +; find '$REMOTE_RELEASE' -type f -exec chmod 644 {} +; ln -sfn '$REMOTE_RELEASE' '$REMOTE_ROOT/current'"

scp -q -i "$REMOTE_KEY" -P "$REMOTE_PORT" \
  "$LOCAL_ROOT/deploy/setup-cms-domain.sh" "$REMOTE_HOST:/tmp/setup-cms-domain-$RELEASE_ID.sh"
if ! "${SSH[@]}" "$REMOTE_HOST" \
  "set -e; chmod 700 '/tmp/setup-cms-domain-$RELEASE_ID.sh'; '/tmp/setup-cms-domain-$RELEASE_ID.sh' '$DOMAIN' '$REMOTE_ROOT/current'; unlink '/tmp/setup-cms-domain-$RELEASE_ID.sh'"; then
  if [ -n "$PREVIOUS_RELEASE" ]; then
    "${SSH[@]}" "$REMOTE_HOST" "ln -sfn '$PREVIOUS_RELEASE' '$REMOTE_ROOT/current'"
  fi
  exit 1
fi

PUBLIC_CHECKS=("https://$DOMAIN/" "https://$DOMAIN/docs/" \
  "https://$DOMAIN/download/" "https://$DOMAIN/releases/" \
  "https://$DOMAIN/security/" "https://$DOMAIN/contracts/cms-manifest.json")
if [ -n "$CMS_RELEASE_VERSION" ]; then
  PUBLIC_CHECKS+=("https://$DOMAIN/releases/current.json" \
    "https://$DOMAIN/releases/$CMS_RELEASE_VERSION/release.json" \
    "https://$DOMAIN/releases/$CMS_RELEASE_VERSION/install.php")
fi
for url in "${PUBLIC_CHECKS[@]}"; do
  if ! curl --fail --silent --show-error --location --max-time 20 "$url" >/dev/null; then
    if [ -n "$PREVIOUS_RELEASE" ]; then
      "${SSH[@]}" "$REMOTE_HOST" "ln -sfn '$PREVIOUS_RELEASE' '$REMOTE_ROOT/current'"
    fi
    echo "CMS 官网公网复核失败，已恢复上一版：$url" >&2
    exit 1
  fi
done

if [ -n "$CMS_RELEASE_VERSION" ]; then
  INSTALLER_HEADERS="$(curl --fail --silent --show-error --head --max-time 20 \
    "https://$DOMAIN/releases/$CMS_RELEASE_VERSION/install.php")"
  printf '%s' "$INSTALLER_HEADERS" | tr '[:upper:]' '[:lower:]' \
    | grep -q 'content-disposition: attachment; filename="install.php"' || {
      if [ -n "$PREVIOUS_RELEASE" ]; then
        "${SSH[@]}" "$REMOTE_HOST" "ln -sfn '$PREVIOUS_RELEASE' '$REMOTE_ROOT/current'"
      fi
      echo "CMS 安装文件没有使用安全下载响应，已恢复上一版" >&2
      exit 1
    }
fi

"${SSH[@]}" "$REMOTE_HOST" \
  "find '$REMOTE_ROOT/releases' -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | tail -n +6 | cut -d' ' -f2- | xargs -r rm -rf"

echo "CMS 官网发布完成：$RELEASE_ID"
echo "访问：https://$DOMAIN/"
