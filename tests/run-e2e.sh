#!/bin/bash
# 瀏覽器 e2e（Playwright / Chromium）：以 <來源目錄>（預設 app/）建拋棄式容器，JaaS 模式 + 測試金鑰，
# 跑 tests/e2e/meeting.cjs（中、英各一輪）。用法：tests/run-e2e.sh [來源目錄]
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC=${1:-$ROOT/app}
PORT=${PORT:-58192}; NAME=jtvc-e2e; IMG=jtvc-e2e:latest
DATA=$(mktemp -d); KEYS=$(mktemp -d); ADMIN_PW='E2e-Admin-Pass-123456'
cleanup() { docker rm -f $NAME >/dev/null 2>&1; rm -rf "$DATA" "$KEYS"; }
trap cleanup EXIT
openssl genrsa -out "$KEYS/private.key" 2048 2>/dev/null; chmod 644 "$KEYS/private.key"; chown 33:33 "$DATA"
# 預設 JaaS 模式 + 測試用 app id（iframe 會指向 8x8.vc；JWT 無效無妨，只驗前端載入）
printf '{"jaas":{"_v":2,"mode":"jaas","app_id":"vpaas-magic-cookie-e2etest","kid":"e2e","domain":"8x8.vc","site_url":"http://127.0.0.1:%s"}}' "$PORT" > "$DATA/settings.json"; chown 33:33 "$DATA/settings.json"
docker build -q -t $IMG "$SRC" >/dev/null || exit 1
docker run -d --name $NAME -p 127.0.0.1:$PORT:58189 -e JTVC_ADMIN_PASSWORD="$ADMIN_PW" \
  -v "$KEYS":/var/www/html/keys:ro -v "$DATA":/var/jaas-data $IMG >/dev/null
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 1; done
PW=${PLAYWRIGHT_MODULE:-/opt/jt-ipam/frontend/node_modules/.pnpm/playwright@1.60.0/node_modules/playwright}
PLAYWRIGHT_MODULE=$PW node "$ROOT/tests/e2e/meeting.cjs" "http://127.0.0.1:$PORT" "$ADMIN_PW"
