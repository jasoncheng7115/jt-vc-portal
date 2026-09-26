#!/bin/bash
# 發版前一次跑完：PHP lint → i18n 檢查 → 單元 → 整合（ZAP 另跑 tests/zap/run-zap.sh）
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
echo "### lint"
docker run --rm -v "$ROOT/app":/app -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php"); do php -l "$f" >/dev/null || { php -l "$f"; exit 1; }; done; echo LINT OK'
echo "### i18n"
docker run --rm -v "$ROOT/app":/app:ro -v "$ROOT/tests":/tests:ro php:8.4-cli php /tests/check-i18n.php /app | tail -3
echo "### unit"
"$ROOT/tests/run-unit.sh"
echo "### integration"
"$ROOT/tests/run-integration.sh"
