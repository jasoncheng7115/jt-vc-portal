#!/bin/bash
# CI 用：從指定原始碼目錄建立 Release image 並打包（GitHub Actions 的 release workflow 呼叫）。
# 用法：tools/ci-build-release.sh <版本 X.Y.Z> <原始碼目錄> <輸出目錄>
# 會驗證：APP_VERSION 一致、PHP 語法、image 內無金鑰 / 資料檔 / 文件 / 測試。
set -euo pipefail
VER=$1; SRC=$2; OUT=$3
mkdir -p "$OUT"
grep -q "APP_VERSION', '$VER'" "$SRC/config.php" || { echo "config.php APP_VERSION != $VER"; exit 1; }
docker run --rm -v "$SRC":/app -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php" -not -path "./tests/*"); do php -l "$f" >/dev/null || { php -l "$f"; exit 1; }; done'
docker build --pull -q -t jt-vc-portal:$VER "$SRC" >/dev/null
docker tag jt-vc-portal:$VER jt-vc-portal:latest
LEAK=$(docker run --rm --entrypoint sh jt-vc-portal:$VER -c 'cd /var/www/html; find . \( -name "*.key" -o -name "*.pem" -o -name "*.pk" -o -name "*.json" -o -name "*.jsonl" -o -name "*.md" -o -path "./docs*" -o -path "./tests*" -o -path "./keys/*" -o -name ".git*" \) 2>/dev/null')
[ -z "$LEAK" ] || { echo "image contains unexpected files:"; echo "$LEAK"; exit 1; }
if docker run --rm --entrypoint sh jt-vc-portal:$VER -c 'grep -rIl "BEGIN.*PRIVATE KEY" /var/www/html 2>/dev/null'; then echo "private key found in image"; exit 1; fi
F="jt-vc-portal-$VER-docker-amd64.tar.gz"
docker save jt-vc-portal:$VER jt-vc-portal:latest | gzip -9 > "$OUT/$F"
( cd "$OUT" && sha256sum "$F" > "$F.sha256" && cat "$F.sha256" )
