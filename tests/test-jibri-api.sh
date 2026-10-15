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

# T80（v1.21.0）波形：真的錄影檔（前 5 秒 440 Hz、後 5 秒靜音）＋ metadata.json＝已完成
if command -v ffmpeg >/dev/null 2>&1; then
  mkdir -p "$D/rec3"; ffmpeg -loglevel error -y -f lavfi -i "sine=frequency=440:duration=5" -af "apad=pad_dur=5" -c:a aac -f mp4 "$D/rec3/meet.mp4" && echo '{}' > "$D/rec3/metadata.json"
  cp "$D/rec3/meet.mp4" "$D/rec4src.mp4"
  PJ=$(curl -s -H 'Authorization: Bearer tok' http://127.0.0.1:19080/api/recordings/rec3/peaks)
  chk "T80 已完成的錄影 → 波形 1200 段、0–1" "$(echo "$PJ" | python3 -c 'import json,sys;d=json.load(sys.stdin);p=d["peaks"];print(d["ok"],d["n"],len(p),min(p)>=0 and max(p)<=1)')" "True 1200 1200 True"
  chk "T80 有聲音的前半段明顯比靜音的後半段高" "$(echo "$PJ" | python3 -c 'import json,sys;p=json.load(sys.stdin)["peaks"];a=sum(p[100:500])/400;b=sum(p[700:1100])/400;print(a>0.05 and b<a/20)')" True
  chk "T80 算好存成快取 .peaks.json" "$([ -f "$D/rec3/.peaks.json" ] && echo yes)" yes
  chk "T80 再要一次直接讀快取（內容相同）" "$(curl -s -H 'Authorization: Bearer tok' http://127.0.0.1:19080/api/recordings/rec3/peaks | python3 -c 'import json,sys;print(json.load(sys.stdin)["peaks"]=='"$(echo "$PJ" | python3 -c 'import json,sys;print(json.load(sys.stdin)["peaks"])')"')')" True
  chk "T80 波形快取不影響列表（仍是一筆完成的錄影）" "$(curl -s -H 'Authorization: Bearer tok' http://127.0.0.1:19080/api/recordings | python3 -c 'import json,sys;r=[x for x in json.load(sys.stdin)["recordings"] if x["id"]=="rec3"];print(len(r),r[0]["status"],r[0]["file"])')" "1 ok meet.mp4"
  chk "T80 刪除錄影 → 波形快取一起刪除" "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H 'Authorization: Bearer tok' http://127.0.0.1:19080/api/recordings/rec3)$([ -e "$D/rec3/.peaks.json" ] || echo ' gone')" "200 gone"
  mkdir -p "$D/rec3" && cp "$D/rec4src.mp4" "$D/rec3/meet.mp4" 2>/dev/null; true
else
  echo "  SKIP T80 波形（本機沒有 ffmpeg）"
fi
chk "T80 錄製中 / 未完成（沒有 metadata.json）→ 409" "$(g http://127.0.0.1:19080/api/recordings/rec1/peaks | cut -d: -f1)" 409
chk "T80 不存在的錄影 → 404" "$(g http://127.0.0.1:19080/api/recordings/nope/peaks | cut -d: -f1)" 404
chk "T80 路徑穿越 → 404" "$(g 'http://127.0.0.1:19080/api/recordings/..%2F..%2Fetc/peaks' | cut -d: -f1)" 404
chk "T80 波形要 token" "$(T=bad g http://127.0.0.1:19080/api/recordings/rec3/peaks | cut -d: -f1)" 403
# 沒有 ffmpeg、也找不到 Jibri 容器 → 503 no_decoder（不影響其他功能）
mkdir -p "$D/rec4"; cp "$D/rec4src.mp4" "$D/rec4/m.mp4" 2>/dev/null && echo '{}' > "$D/rec4/metadata.json"
env -i PATH=/nonexistent REC_DIR=$D API_TOKEN=tok PORT=19081 ALLOW_IPS=127.0.0.1 "$(command -v python3)" "$SRV" & PID2=$!
sleep 1
if [ -f "$D/rec4/m.mp4" ]; then chk "T80 沒有解碼器 → 503 no_decoder" "$(curl -s -H 'Authorization: Bearer tok' http://127.0.0.1:19081/api/recordings/rec4/peaks | python3 -c 'import json,sys;print(json.load(sys.stdin).get("error"))')" no_decoder; fi
kill $PID2 2>/dev/null
echo; echo "$PASS passed, $FAIL failed"; [ $FAIL -eq 0 ]
