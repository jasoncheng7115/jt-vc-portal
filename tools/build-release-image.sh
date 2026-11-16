#!/bin/bash
# 打包 Release 用 docker image：jt-vc-portal-X.Y.Z-docker-amd64.tar.gz + .sha256 + 兩份 SBOM → github/release/
# 來源 = github/（公開內容），在 dev1 本機建置（不佔用正式機）。建完驗證 image 內無機密。
# 用法：tools/build-release-image.sh 1.6.3
set -euo pipefail
VER=$1
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC=$(mktemp -d)
trap 'rm -rf "$SRC"' EXIT
rsync -a --exclude '.git' --exclude 'release' --exclude '.DS_Store' "$ROOT/github/" "$SRC/"
grep -q "APP_VERSION', '$VER'" "$SRC/config.php" || { echo "config.php APP_VERSION != $VER"; exit 1; }
docker run --rm -v "$SRC":/app -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php" -not -path "./tests/*"); do php -l "$f" >/dev/null || exit 1; done'
docker build --pull -q -t jt-vc-portal:$VER "$SRC" >/dev/null
docker tag jt-vc-portal:$VER jt-vc-portal:latest
# 機密 / 非必要檔案不得進 image
LEAK=$(docker run --rm --entrypoint sh jt-vc-portal:$VER -c 'cd /var/www/html; find . \( -name "*.key" -o -name "*.pem" -o -name "*.pk" -o -name "*.json" -o -name "*.jsonl" -o -name "*.md" -o -path "./docs*" -o -path "./tests*" -o -path "./keys/*" -o -name ".git*" \) 2>/dev/null')
[ -z "$LEAK" ] || { echo "image contains unexpected files:"; echo "$LEAK"; exit 1; }
docker run --rm --entrypoint sh jt-vc-portal:$VER -c 'grep -rIl "BEGIN.*PRIVATE KEY" /var/www/html 2>/dev/null' && { echo "private key found in image"; exit 1; } || true
mkdir -p "$ROOT/github/release"
OUT="$ROOT/github/release/jt-vc-portal-$VER-docker-amd64.tar.gz"
docker save jt-vc-portal:$VER jt-vc-portal:latest | gzip -9 > "$OUT"
( cd "$ROOT/github/release" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )
# SBOM（CycloneDX 1.5）：應用程式層＋映像層，與 CI（tools/ci-build-release.sh）相同做法
python3 "$ROOT/github/tools/make-sbom.py" "$SRC" > "$ROOT/github/release/jt-vc-portal-$VER-sbom.cdx.json"
docker run --rm -e SYFT_FILE_METADATA_SELECTION=none -v /var/run/docker.sock:/var/run/docker.sock \
  anchore/syft:v1.57.1@sha256:03215d0e98b661d0ecbc8575dbc14d65be1743b5de5df6410aecb7e80f95b060 \
  "jt-vc-portal:$VER" -o cyclonedx-json@1.5 --source-name jt-vc-portal --source-version "$VER" -q \
  > "$ROOT/github/release/jt-vc-portal-$VER-image-sbom.cdx.json"
docker rmi jt-vc-portal:$VER jt-vc-portal:latest >/dev/null
ls -la "$ROOT/github/release/jt-vc-portal-$VER"*; cat "$OUT.sha256"
