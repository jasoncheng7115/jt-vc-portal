#!/bin/bash
# 更新內附的 Jitsi IFrame API（external_api.js）並重算 SRI。
# 來源預設 8x8 官方（與 JaaS 各租戶路徑內容相同）；自建也相容（IFrame API 向下相容）。
# 用法：tools/update-jitsi-external-api.sh [來源 URL]
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
URL=${1:-https://8x8.vc/external_api.js}
DST="$ROOT/app/assets/vendor/jitsi-external-api.js"
TMP=$(mktemp); curl -fsSL "$URL" -o "$TMP"
head -c 200 "$TMP" | grep -q JitsiMeetExternalAPI || { echo "not an external_api.js"; exit 1; }
mv "$TMP" "$DST"
SRI="sha384-$(openssl dgst -sha384 -binary "$DST" | openssl base64 -A)"
sed -i "s|const EXTERNAL_API_SRI = '[^']*';|const EXTERNAL_API_SRI = '$SRI';|" "$ROOT/app/lib/jaas.php"
echo "updated: $SRI"
