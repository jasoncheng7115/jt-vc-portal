#!/bin/bash
# 整合測試：以 app/ 建立拋棄式映像與容器（暫存資料卷、測試用 RSA 金鑰），用 curl 走真實 HTTP 流程。
# 不連正式機、不碰正式資料。用法：tests/run-integration.sh
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
PORT=${PORT:-58190}
NAME=jtvc-itest
IMG=jtvc-itest:latest
DATA=$(mktemp -d); KEYS=$(mktemp -d); JAR=$(mktemp -d)
ADMIN_PW='Itest-Admin-Pass-123'
cleanup() { docker rm -f $NAME >/dev/null 2>&1; rm -rf "$DATA" "$KEYS" "$JAR"; }
trap cleanup EXIT
openssl genrsa -out "$KEYS/private.key" 2048 2>/dev/null; chmod 644 "$KEYS/private.key"
chown 33:33 "$DATA"
docker build -q -t $IMG "${SRC:-$ROOT/app}" >/dev/null || { echo "build failed"; exit 1; }
docker run -d --name $NAME -p 127.0.0.1:$PORT:58189 -e JTVC_ADMIN_PASSWORD="$ADMIN_PW" \
  -v "$KEYS":/var/www/html/keys:ro -v "$DATA":/var/jaas-data $IMG >/dev/null
B="http://127.0.0.1:$PORT"
for i in $(seq 1 30); do curl -s -o /dev/null "$B/" && break; sleep 1; done

PASS=0; FAIL=0
chk() { if [ "$2" = "$3" ]; then echo "  ok   $1"; PASS=$((PASS+1)); else echo "  FAIL $1 (expected [$3] got [$2])"; FAIL=$((FAIL+1)); fi; }
has() { if printf '%s' "$2" | grep -q -- "$3"; then echo "  ok   $1"; PASS=$((PASS+1)); else echo "  FAIL $1 (missing [$3])"; FAIL=$((FAIL+1)); fi; }
hasnt() { if printf '%s' "$2" | grep -q -- "$3"; then echo "  FAIL $1 (found [$3])"; FAIL=$((FAIL+1)); else echo "  ok   $1"; PASS=$((PASS+1)); fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
loc()  { curl -s -o /dev/null -w '%{redirect_url}' "$@"; }
csrf() { curl -s -b "$1" -c "$1" "$B$2" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
login() { # jar user pass [ip]
  local t; t=$(csrf "$1" /jt-login)
  curl -s -o /dev/null -w '%{redirect_url}' -b "$1" -c "$1" -H "X-Real-IP: ${4:-10.0.0.1}" \
    --data-urlencode "_csrf=$t" --data-urlencode "email=$2" --data-urlencode "password=$3" "$B/verify"; }

echo "== 基本 / 標頭"
chk "首頁 200" "$(code $B/)" 200
H=$(curl -sI $B/)
has "X-Frame-Options" "$H" "X-Frame-Options: SAMEORIGIN"
has "nosniff" "$H" "X-Content-Type-Options: nosniff"
has "CSP" "$H" "Content-Security-Policy"
CSP=$(printf '%s' "$H" | grep -i '^Content-Security-Policy' )
has "CSP script-src 用 nonce" "$CSP" "script-src 'self' 'nonce-"
hasnt "CSP script-src 無 unsafe-inline" "$(printf '%s' "$CSP" | grep -o "script-src [^;]*")" "unsafe-inline"
hasnt "CSP style-src 無 unsafe-inline" "$(printf '%s' "$CSP" | grep -o "style-src [^;]*")" "unsafe-inline"
R=$(curl -s -D - $B/ | tr -d '\r')
N=$(printf '%s' "$R" | grep -i '^Content-Security-Policy' | grep -o "nonce-[A-Za-z0-9_-]*" | head -1 | sed 's/nonce-//')
has "頁面 script 帶與標頭相同的 nonce" "$R" "nonce=\"$N\""
chk "lib/ 拒絕存取" "$(code $B/lib/store.php)" 403
chk "*.json 拒絕存取" "$(code $B/settings.json)" 403

echo "== 登入路徑不外洩"
chk "GET /verify → 404" "$(code $B/verify)" 404
chk "GET /twofa → 404" "$(code $B/twofa)" 404
chk "GET /twofa-verify → 404" "$(code $B/twofa-verify)" 404
chk "未登入 /logout → 導回 /" "$(loc $B/logout)" "$B/"
chk "未登入 /dashboard → 404" "$(code $B/dashboard)" 404

echo "== 登入"
A="$JAR/admin"
chk "admin 登入成功" "$(login $A jtvc-admin "$ADMIN_PW")" "$B/dashboard"
chk "已登入 /dashboard 200" "$(code -b $A $B/dashboard)" 200
T=$(csrf $A /accounts)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d "username=hostb&display_name=HostB&email=hostb@example.com&password=Itest-HostB-Pass-1&role=host" $B/account-save
HB="$JAR/hostb"
chk "hostb 登入成功" "$(login $HB hostb Itest-HostB-Pass-1 10.0.0.2)" "$B/dashboard"

echo "== 建立會議室 / start 僅 POST"
T=$(csrf $A /dashboard)
chk "POST 建立會議室" "$(loc -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=itest-room&mode=create' $B/start)" "$B/dashboard?created=itest-room"
chk "GET /start 不做事、回儀表板" "$(loc -b $A "$B/start?room=itest-room&mode=host")" "$B/dashboard"
has "GET 後主持人仍未在線" "$(curl -s -b $A "$B/room-status?room=itest-room")" '"host_joined":false'
chk "POST /start 無 CSRF → 403" "$(code -b $A -d 'room=itest-x&mode=create' $B/start)" 403
chk "enter=1 不存在房間 → 回儀表板" "$(loc -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=no-such-room&enter=1' $B/start)" "$B/dashboard"
chk "建立表單同名 → 擋下回儀表板" "$(loc -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=itest-room&mode=create' $B/start)" "$B/dashboard"

echo "== 心跳 / 離開：POST + CSRF + 擁有權"
chk "GET /host-heartbeat → 405" "$(code -b $A "$B/host-heartbeat?room=itest-room")" 405
chk "未登入心跳 → 403" "$(code -X POST "$B/host-heartbeat?room=itest-room")" 403
chk "心跳無 CSRF → 403" "$(code -b $A -X POST "$B/host-heartbeat?room=itest-room")" 403
TB=$(csrf $HB /dashboard)
chk "非擁有者心跳 → 403" "$(code -b $HB -H "X-CSRF-Token: $TB" -X POST "$B/host-heartbeat?room=itest-room")" 403
chk "非擁有者離開 → 403" "$(code -b $HB --data-urlencode "_csrf=$TB" -d room=itest-room $B/host-left)" 403
T=$(csrf $A /dashboard)
chk "擁有者心跳 → 204" "$(code -b $A -H "X-CSRF-Token: $T" -H 'Content-Type: application/json' -d '{"roster":[]}' -X POST "$B/host-heartbeat?room=itest-room")" 204
has "心跳後主持人在線" "$(curl -s -b $A "$B/room-status?room=itest-room")" '"host_joined":true'
chk "擁有者離開 → 204" "$(code -b $A --data-urlencode "_csrf=$T" -d room=itest-room $B/host-left)" 204
chk "擁有者從清單進入 → /meeting" "$(loc -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=itest-room&enter=1' $B/start)" "$B/meeting"
has "會議頁帶心跳 CSRF token" "$(curl -s -b $A $B/meeting)" "X-CSRF-Token"
code -b $A --data-urlencode "_csrf=$T" -d room=itest-room $B/host-left >/dev/null
chk "hostb 進入他人房間 → 擋下" "$(loc -b $HB -c $HB --data-urlencode "_csrf=$TB" -d 'room=itest-room&enter=1' $B/start)" "$B/dashboard"

echo "== 設定頁不回填密鑰"
T=$(csrf $A /settings)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'section=smtp&host=smtp.example.com&port=587&security=starttls&username=u&password=SMTP-SECRET-XYZ&from_email=a@example.com' $B/save-settings
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'section=jaas&mode=selfhosted&sh_domain=meet.example.com&sh_auth=jwt&sh_secret=JWT-SECRET-XYZ' $B/save-settings
P=$(curl -s -b $A $B/settings)
hasnt "頁面不含 SMTP 密碼" "$P" "SMTP-SECRET-XYZ"
hasnt "頁面不含 JWT 共享密鑰" "$P" "JWT-SECRET-XYZ"
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'section=smtp&host=smtp.example.com&port=587&security=starttls&username=u&password=&from_email=a@example.com' $B/save-settings
has "留空送出保留 SMTP 密碼" "$(docker exec $NAME cat /var/jaas-data/settings.json)" "SMTP-SECRET-XYZ"
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'section=jaas&mode=selfhosted&sh_domain=meet.example.com&sh_auth=jwt&sh_secret=' $B/save-settings
has "留空送出保留 JWT 密鑰" "$(docker exec $NAME cat /var/jaas-data/settings.json)" "JWT-SECRET-XYZ"

echo "== 帳號層鎖定（不同 IP 各 1 次，共 10 次）"
V="$JAR/victim"
for i in $(seq 1 10); do login $V hostb wrong-password-$i 10.9.0.$i >/dev/null; done
login $V hostb Itest-HostB-Pass-1 10.9.1.1 >/dev/null
has "正確密碼也被帳號層鎖定擋下" "$(curl -s -b $V $B/jt-login)" "暫時鎖定"
chk "admin 不受 hostb 鎖定影響" "$(login "$JAR/a2" jtvc-admin "$ADMIN_PW" 10.9.2.1)" "$B/dashboard"

echo "== 來賓"
G="$JAR/guest"
chk "邀請連結 → /guest" "$(loc -c $G -b $G $B/room/itest-room)" "$B/guest"
has "等候主持人頁" "$(curl -s -b $G $B/guest)" "spinner"
has "未持邀請查 room-status 得 unknown" "$(curl -s "$B/room-status?room=itest-room")" '"status":"unknown"'

echo
echo "$PASS passed, $FAIL failed"
[ $FAIL -eq 0 ]
