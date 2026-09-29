#!/bin/bash
# jibri-recordings-api 本機測試：假錄影檔 + 臨時 token，驗證授權與 Range（206 / 416）。
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
D=$(mktemp -d); mkdir -p "$D/rec1"; head -c 1000 /dev/urandom > "$D/rec1/test.mp4"
# 路徑安全用的測試資料：隱藏目錄、指向外部的符號連結、檔名含空白與引號的錄影
OUT=$(mktemp -d); head -c 100 /dev/urandom > "$OUT/secret.mp4"; ln -s "$OUT" "$D/linkrec"
mkdir -p "$D/.hidden" "$D/rec2"; head -c 100 /dev/urandom > "$D/.hidden/x.mp4"; head -c 100 /dev/urandom > "$D/rec2/a b\"c.mp4"
SRV="$ROOT/jibri-recordings-api/server.py"; [ -f "$SRV" ] || SRV="$ROOT/github/jibri-recordings-api/server.py"
REC_DIR=$D API_TOKEN=tok PORT=19080 ALLOW_IPS=127.0.0.1 python3 "$SRV" & PID=$!
trap 'kill $PID 2>/dev/null; rm -rf "$D" "$OUT"' EXIT
sleep 1
PASS=0; FAIL=0
chk() { if [ "$2" = "$3" ]; then echo "  ok   $1"; PASS=$((PASS+1)); else echo "  FAIL $1 (expected $3 got $2)"; FAIL=$((FAIL+1)); fi; }
g() { curl -s -o /dev/null -w "%{http_code}:%{size_download}" -H "Authorization: Bearer ${T:-tok}" "$@"; }
chk "無 token → 403" "$(T=bad g http://127.0.0.1:19080/api/ping | cut -d: -f1)" 403
chk "ping 200" "$(g http://127.0.0.1:19080/api/ping | cut -d: -f1)" 200
chk "完整檔 200 / 1000 bytes" "$(g http://127.0.0.1:19080/api/recordings/rec1/file)" "200:1000"
chk "Range 0-99 → 206 / 100" "$(g -H 'Range: bytes=0-99' http://127.0.0.1:19080/api/recordings/rec1/file)" "206:100"
chk "Range 990- → 206 / 10" "$(g -H 'Range: bytes=990-' http://127.0.0.1:19080/api/recordings/rec1/file)" "206:10"
chk "Range 超出檔案 → 416" "$(g -H 'Range: bytes=5000-' http://127.0.0.1:19080/api/recordings/rec1/file | cut -d: -f1)" 416
chk "Range 顛倒 → 416" "$(g -H 'Range: bytes=50-10' http://127.0.0.1:19080/api/recordings/rec1/file | cut -d: -f1)" 416
chk "路徑穿越 → 404" "$(g 'http://127.0.0.1:19080/api/recordings/..%2F..%2Fetc/file' | cut -d: -f1)" 404
chk "id 為 .. → 404" "$(g 'http://127.0.0.1:19080/api/recordings/../file' --path-as-is | cut -d: -f1)" 404
chk "隱藏目錄（.hidden）→ 404" "$(g 'http://127.0.0.1:19080/api/recordings/.hidden/file' | cut -d: -f1)" 404
chk "指向外部的符號連結 → 404" "$(g 'http://127.0.0.1:19080/api/recordings/linkrec/file' | cut -d: -f1)" 404
chk "刪除 .. → 不接受（404）" "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H 'Authorization: Bearer tok' --path-as-is 'http://127.0.0.1:19080/api/recordings/..')" 404
[ -f "$OUT/secret.mp4" ] && [ -d "$D/rec1" ] && chk "刪除攻擊後檔案都還在" ok ok || chk "刪除攻擊後檔案都還在" missing ok
CD=$(curl -s -D - -o /dev/null -H 'Authorization: Bearer tok' 'http://127.0.0.1:19080/api/recordings/rec2/file?dl=1' | tr -d '\r' | grep -i '^content-disposition:')
chk "下載檔名只留安全字元（無引號 / 空白）" "$CD" 'Content-Disposition: attachment; filename="a_b_c.mp4"'
echo; echo "$PASS passed, $FAIL failed"; [ $FAIL -eq 0 ]
