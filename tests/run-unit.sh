#!/bin/sh
# 單元測試：在拋棄式容器內跑，資料目錄為暫存目錄（不碰任何正式資料）。
# 用法：tests/run-unit.sh
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DATA=$(mktemp -d)
KEYS=$(mktemp -d)
openssl genrsa -out "$KEYS/private.key" 2048 2>/dev/null
chmod 644 "$KEYS/private.key"
trap 'rm -rf "$DATA" "$KEYS"' EXIT
docker run --rm -v "$ROOT/app":/app:ro -v "$ROOT/tests":/tests:ro -v "$DATA":/var/jaas-data \
  -v "$KEYS":/var/www/html/keys:ro php:8.4-cli sh -c '
  rc=0
  for f in /tests/unit/test_*.php; do echo "== $(basename $f)"; php "$f" || rc=1; done
  exit $rc'
