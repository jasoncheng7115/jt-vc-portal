#!/bin/bash
# CI 用：從指定原始碼目錄建立 Release image 並打包（GitHub Actions 的 release workflow 呼叫）。
# 用法：tools/ci-build-release.sh <版本 X.Y.Z> <原始碼目錄> <輸出目錄>
# 會驗證：APP_VERSION 一致、PHP 語法、image 內無金鑰 / 資料檔 / 文件 / 測試。
set -euo pipefail
VER=$1; SRC=$2; OUT=$3
mkdir -p "$OUT"
grep -q "APP_VERSION', '$VER'" "$SRC/config.php" || { echo "config.php APP_VERSION != $VER"; exit 1; }
# 版控 / CI 設定不屬於 image（舊 tag 的 .dockerignore 可能沒排除，這裡一律先移除）
rm -rf "$SRC/.github" "$SRC/.git" "$SRC/keycloak"
docker run --rm -v "$SRC":/app -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php" -not -path "./tests/*"); do php -l "$f" >/dev/null || { php -l "$f"; exit 1; }; done'
docker build --pull -q -t jt-vc-portal:$VER "$SRC" >/dev/null
docker tag jt-vc-portal:$VER jt-vc-portal:latest
LEAK=$(docker run --rm --entrypoint sh jt-vc-portal:$VER -c 'cd /var/www/html; find . \( -name "*.key" -o -name "*.pem" -o -name "*.pk" -o -name "*.json" -o -name "*.jsonl" -o -name "*.md" -o -path "./docs*" -o -path "./tests*" -o -path "./keys/*" -o -name ".git*" \) 2>/dev/null')
[ -z "$LEAK" ] || { echo "image contains unexpected files:"; echo "$LEAK"; exit 1; }
if docker run --rm --entrypoint sh jt-vc-portal:$VER -c 'grep -rIl "BEGIN.*PRIVATE KEY" /var/www/html 2>/dev/null'; then echo "private key found in image"; exit 1; fi
F="jt-vc-portal-$VER-docker-amd64.tar.gz"
docker save jt-vc-portal:$VER jt-vc-portal:latest | gzip -9 > "$OUT/$F"
( cd "$OUT" && sha256sum "$F" > "$F.sha256" && cat "$F.sha256" )
# SBOM（CycloneDX 1.5，v1.25.3 起每版都附）：應用程式層（內附 / CDN 第三方元件）＋ Docker 映像層（PHP、Apache、Debian 套件）
TOOLS=$(cd "$(dirname "$0")" && pwd)
python3 "$TOOLS/make-sbom.py" "$SRC" > "$OUT/jt-vc-portal-$VER-sbom.cdx.json"
SYFT=anchore/syft:v1.57.1@sha256:03215d0e98b661d0ecbc8575dbc14d65be1743b5de5df6410aecb7e80f95b060   # 釘版本＋digest（供應鏈）
docker run --rm -e SYFT_FILE_METADATA_SELECTION=none -v /var/run/docker.sock:/var/run/docker.sock "$SYFT" \
  "jt-vc-portal:$VER" -o cyclonedx-json@1.5 --source-name jt-vc-portal --source-version "$VER" -q > "$OUT/jt-vc-portal-$VER-image-sbom.cdx.json"
python3 - "$OUT" "$VER" <<'PYCHK'
import json, sys
out, ver = sys.argv[1], sys.argv[2]
app = json.load(open(f'{out}/jt-vc-portal-{ver}-sbom.cdx.json'))
img = json.load(open(f'{out}/jt-vc-portal-{ver}-image-sbom.cdx.json'))
for d in (app, img):
    assert d['bomFormat'] == 'CycloneDX' and d['specVersion'] == '1.5', d.get('specVersion')
assert app['metadata']['component']['version'] == ver, 'app SBOM version'
names = {c['name'] for c in app['components']}
assert {'jitsi-meet-external-api', 'chart.js', 'flatpickr', 'qrcodejs'} <= names, names
assert img['metadata']['component'].get('version') == ver, 'image SBOM version'
php = [c.get('version') for c in img['components'] if c['name'] == 'php-cli']
assert php, 'php-cli missing from image SBOM'
print(f"SBOM ok: app {len(app['components'])} components; image {len(img['components'])} components, PHP {php[0]}")
PYCHK
