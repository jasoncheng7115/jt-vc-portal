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

# 視訊服務健康檢查（v1.16.0）：測試環境不連外，先寫一筆「正常」的快取（時間設在未來，永遠命中）；
# 故障情境在後面另外測（清掉快取、指到連不上的位址）
hcache() { docker exec -u www-data $NAME php -r '$l=$argv[1]; file_put_contents("/var/jaas-data/health-cache.json", json_encode(["jitsi"=>["level"=>$l,"msg"=>"t","at"=>time()+86400]]));' "$1"; }
hcache ok

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
chk "lang/ 字典目錄拒絕存取" "$(code $B/lang/en/auth.php)" 403

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
NOWMS=$(( $(date +%s) * 1000 ))
chk "心跳帶主要發言者時間軸 → 204" "$(code -b $A -H "X-CSRF-Token: $T" -H 'Content-Type: application/json' -d "{\"roster\":[],\"talk\":[{\"n\":\"Amy\",\"s\":$((NOWMS-5000)),\"e\":null}]}" -X POST "$B/host-heartbeat?room=itest-room")" 204
has "時間軸存進房間暫存（v1.13.0）" "$(docker exec $NAME cat /var/jaas-data/auto-allow.json)" '"n": "Amy"'
chk "擁有者離開 → 204" "$(code -b $A --data-urlencode "_csrf=$T" -d room=itest-room $B/host-left)" 204
chk "擁有者從清單進入 → /meeting" "$(loc -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=itest-room&enter=1' $B/start)" "$B/meeting"
has "會議頁帶心跳 CSRF token" "$(curl -s -b $A $B/meeting)" "X-CSRF-Token"
has "會議頁記錄 Jitsi 主要發言者事件（v1.13.0）" "$(curl -s -b $A $B/meeting)" "dominantSpeakerChanged"
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
has "正確密碼也被帳號層鎖定擋下" "$(curl -s -b $V $B/jt-login)" "temporarily locked"
chk "admin 不受 hostb 鎖定影響" "$(login "$JAR/a2" jtvc-admin "$ADMIN_PW" 10.9.2.1)" "$B/dashboard"
echo "== IP 層鎖定畫面：只顯示、沒有可送出的登入表單（v1.12.0，ZAP Anti-CSRF）"
for i in $(seq 1 5); do login "$JAR/ipl" nobody-$i wrong-password 10.9.8.8 >/dev/null; done
LP=$(curl -s -H "X-Real-IP: 10.9.8.8" $B/jt-login)
has "被鎖定的來源看到鎖定訊息" "$LP" 'login-locked'
hasnt "鎖定畫面沒有送往 /verify 的表單" "$LP" 'action="/verify"'

echo "== 來賓"
G="$JAR/guest"
chk "邀請連結 → /guest" "$(loc -c $G -b $G $B/room/itest-room)" "$B/guest"
has "等候主持人頁" "$(curl -s -b $G $B/guest)" "spinner"
has "未持邀請查 room-status 得 unknown" "$(curl -s "$B/room-status?room=itest-room")" '"status":"unknown"'

echo "== 多語系"
CJK='[一-鿿]'
nocjk() { # name body（排除語言名稱「繁體中文」與 JSON \u 跳脫）
  local b; b=$(printf '%s' "$2" | sed 's/繁體中文//g; s/简体中文//g; s/日本語//g; s/한국어//g')
  if printf '%s' "$b" | grep -qP '[\x{4e00}-\x{9fff}]'; then echo "  FAIL $1 (含中文: $(printf '%s' "$b" | grep -oP '.{0,20}[\x{4e00}-\x{9fff}]+.{0,10}' | head -2 | tr '\n' ' '))"; FAIL=$((FAIL+1)); else echo "  ok   $1"; PASS=$((PASS+1)); fi; }
EN="Accept-Language: en-US,en;q=0.9"
ZH="Accept-Language: zh-TW,zh;q=0.9"
nocjk "英文瀏覽器：首頁無中文" "$(curl -s -H "$EN" $B/)"
nocjk "英文瀏覽器：登入頁無中文" "$(curl -s -H "$EN" $B/jt-login)"
nocjk "英文瀏覽器：404 頁無中文" "$(curl -s -H "$EN" $B/no-such-page-xyz)"
has "中文瀏覽器：登入頁為中文" "$(curl -s -H "$ZH" $B/jt-login)" "主持人登入"
has "html lang=en" "$(curl -s -H "$EN" $B/)" '<html lang="en"'
has "html lang=zh-Hant-TW" "$(curl -s -H "$ZH" $B/)" '<html lang="zh-Hant-TW"'
has "?lang=zh-TW 覆寫英文瀏覽器" "$(curl -s -H "$EN" "$B/?lang=zh-TW")" '<html lang="zh-Hant-TW"'
L="$JAR/lang"
curl -s -o /dev/null -c $L -b $L "$B/set-lang?l=en&r=/"
has "切換語言寫入 cookie" "$(cat $L)" "jtvc_lang"
has "cookie 優先於瀏覽器語言" "$(curl -s -b $L -H "$ZH" $B/)" '<html lang="en"'
chk "/lang 拒絕外部轉址（//evil）" "$(loc "$B/set-lang?l=en&r=//evil.example.com/")" "$B/"
chk "/lang 拒絕絕對網址" "$(loc "$B/set-lang?l=en&r=https://evil.example.com/")" "$B/"
chk "/lang 站內路徑正常導回" "$(loc "$B/set-lang?l=en&r=/jt-login")" "$B/jt-login"
# 登入後英文逐頁巡查（admin）
curl -s -o /dev/null -b $A -c $A "$B/set-lang?l=en&r=/"
for pg in dashboard accounts audit-log usage settings profile; do nocjk "英文：/$pg 無中文" "$(curl -s -b $A $B/$pg)"; done
has "登入者語言存到個人設定" "$(docker exec $NAME cat /var/jaas-data/users.json)" '"lang": "en"'
curl -s -o /dev/null -b $A -c $A "$B/set-lang?l=zh-TW&r=/"
has "切回中文：儀表板為中文" "$(curl -s -b $A $B/dashboard)" "會議室管理"
JA="Accept-Language: ja-JP,ja;q=0.9"
# 日文頁面：html lang=ja、含假名、不含繁體中文專用字（會錄帳號們這與儀刪頁輸擇關顯）
jacheck() { # name body
  local b; b=$(printf '%s' "$2" | sed 's/繁體中文//g; s/简体中文//g')
  if ! printf '%s' "$b" | grep -qP '[\x{3040}-\x{30ff}]'; then echo "  FAIL $1 (沒有假名)"; FAIL=$((FAIL+1)); return; fi
  if printf '%s' "$b" | grep -qP '[會錄帳號們這與儀刪頁輸擇關顯]'; then echo "  FAIL $1 (含繁中字: $(printf '%s' "$b" | grep -oP '.{0,12}[會錄帳號們這與儀刪頁輸擇關顯].{0,8}' | head -2 | tr '\n' ' '))"; FAIL=$((FAIL+1)); else echo "  ok   $1"; PASS=$((PASS+1)); fi; }
has "日文瀏覽器：html lang=ja" "$(curl -s -H "$JA" $B/)" '<html lang="ja"'
jacheck "日文瀏覽器：登入頁為日文" "$(curl -s -H "$JA" $B/jt-login)"
curl -s -o /dev/null -b $A -c $A "$B/set-lang?l=ja&r=/"
for pg in dashboard accounts audit-log usage settings profile; do jacheck "日文：/$pg" "$(curl -s -b $A $B/$pg)"; done
curl -s -o /dev/null -b $A -c $A "$B/set-lang?l=zh-TW&r=/"
GJ="$JAR/guest-ja"
curl -s -o /dev/null -c $GJ -b $GJ -H "$JA" $B/room/itest-room
jacheck "日文來賓等候頁" "$(curl -s -b $GJ -H "$JA" $B/guest)"
GE="$JAR/guest-en"
curl -s -o /dev/null -c $GE -b $GE -H "$EN" $B/room/itest-room
nocjk "英文來賓等候頁無中文" "$(curl -s -b $GE -H "$EN" $B/guest)"

echo "== v1.8.0：登出 / session 失效 / 刪除會議室"
chk "GET /logout 不登出（導回儀表板）" "$(loc -b $A $B/logout)" "$B/dashboard"
chk "GET /logout 後仍登入" "$(code -b $A $B/dashboard)" 200
chk "POST /logout 無 CSRF → 403" "$(code -b $A -X POST $B/logout)" 403
# 管理員重設 hostb 密碼 → hostb 既有 session 失效
T=$(csrf $A /accounts)
HBID=$(docker exec $NAME php -r 'require "/var/www/html/lib/users.php"; echo Users::findByLogin("hostb")["id"];')
HB2="$JAR/hostb2"
docker exec -u www-data $NAME php -r 'require "/var/www/html/lib/ratelimit.php"; RateLimit::resetAccount("hostb");'   # 解除前面帳號鎖定測試留下的鎖
chk "hostb 重新登入" "$(login $HB2 hostb Itest-HostB-Pass-1 10.0.5.1)" "$B/dashboard"
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d "id=$HBID&role=host&disabled=0&password=Itest-HostB-Pass-2" $B/account-save
chk "改密碼後 hostb 舊 session 失效" "$(code -b $HB2 $B/dashboard)" 404
chk "hostb 用新密碼登入" "$(login $HB2 hostb Itest-HostB-Pass-2 10.0.5.2)" "$B/dashboard"
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d "id=$HBID&role=host&disabled=0&revoke=1" $B/account-save
chk "強制登出後 hostb session 失效" "$(code -b $HB2 $B/dashboard)" 404
chk "admin 自己不受影響" "$(code -b $A $B/dashboard)" 200
# 刪除會議室：非擁有者不可、擁有者可
chk "hostb 重新登入（刪除測試）" "$(login $HB2 hostb Itest-HostB-Pass-2 10.0.5.3)" "$B/dashboard"
TB=$(csrf $HB2 /dashboard)
curl -s -o /dev/null -b $HB2 -c $HB2 --data-urlencode "_csrf=$TB" -d 'room=itest-room' $B/room-delete
has "非擁有者刪除 → 房間仍在" "$(docker exec $NAME cat /var/jaas-data/auto-allow.json)" '"itest-room"'
chk "room-delete 無 CSRF → 403" "$(code -b $A -d 'room=itest-room' $B/room-delete)" 403
T=$(csrf $A /dashboard)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=itest-room' $B/room-delete
hasnt "擁有者刪除 → 房間已移除" "$(docker exec $NAME cat /var/jaas-data/auto-allow.json)" '"itest-room"'
has "刪除寫入稽核" "$(docker exec $NAME cat /var/jaas-data/audit-log.jsonl)" '"action":"room_delete"'
echo "== 視訊服務健康檢查與故障頁（v1.16.0）"
chk "未登入 /health → 404" "$(code $B/health)" 404
HJ=$(curl -s -b $A $B/health)
has "管理員 /health 回 JSON（Jitsi 狀態）" "$HJ" '"jitsi":{"level":"ok"'
hasnt "/health 不洩漏內部細節" "$HJ" '"detail"'
has "管理員儀表板有系統狀態列" "$(curl -s -b $A $B/dashboard)" 'id="sysHealth"'
T=$(csrf $A /dashboard)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'section=jaas&mode=selfhosted&sh_domain=127.0.0.1:9&sh_auth=none' $B/save-settings
docker exec $NAME rm -f /var/jaas-data/health-cache.json
HJ=$(curl -s -b $A "$B/health?force=1")
has "Jitsi 連不上 → /health 回報 error" "$HJ" '"jitsi":{"level":"error"'
T=$(csrf $A /dashboard)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=itest-down&mode=host' $B/start
chk "Jitsi 故障：主持人進會議 → 503" "$(code -b $A $B/meeting)" 503
has "Jitsi 故障：主持人看到友善的故障頁" "$(curl -s -b $A $B/meeting)" 'id="svcDown"'
GD="$JAR/guest-down"
curl -s -o /dev/null -c $GD -b $GD $B/room/itest-down
GDP=$(curl -s -b $GD $B/guest)
has "Jitsi 故障：來賓看到友善的故障頁（主持人在線）" "$GDP" 'id="svcDown"'
hasnt "故障頁不顯示內部位址" "$GDP" '127.0.0.1:9'
hasnt "故障頁不載入會議畫面" "$GDP" 'JitsiMeetExternalAPI('
hcache ok
chk "Jitsi 正常 + 自建不需 JWT：主持人可進會議（v1.16.0 修正：jwt 為空字串時被導回儀表板）" "$(code -b $A $B/meeting)" 200
# T74 會議室自訂「小畫面也維持較高畫質」：預設關（照 Jitsi 預設依方格大小選畫質）；勾選後主持人與來賓頁都帶較低的畫質門檻、不限全畫質人數
MP=$(curl -s -b $A $B/meeting)
hasnt "T74 預設：會議頁不帶較高畫質門檻" "$MP" "minHeightForQualityLvl"
has "T74 預設：關閉省頻寬自動降載（既有預設）" "$MP" "enableAdaptiveMode: false"
T=$(csrf $A /settings)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'section=meeting_custom&bw_save_off=1&hq_small=1&resolution=1080&default_view=tile' $B/save-settings
MP=$(curl -s -b $A $B/meeting)
has "T74 勾選後：主持人頁帶較低的畫質門檻（小方格 360p、300px 以上 720p）" "$MP" "minHeightForQualityLvl: { 100: 'standard', 300: 'high' }"
has "T74 勾選後：主持人頁不限全畫質人數" "$MP" "maxFullResolutionParticipants: -1"
GQ="$JAR/guest-hq"
curl -s -o /dev/null -c $GQ -b $GQ $B/room/itest-down
curl -s -o /dev/null -c $GQ -b $GQ -d 'guest_name=HQ' $B/guest
GP=$(curl -s -b $GQ $B/guest)
has "T74 勾選後：來賓頁同樣帶較高畫質設定" "$GP" "maxFullResolutionParticipants: -1"
has "T74 系統設定頁有「小畫面也維持較高畫質」選項且已勾選" "$(curl -s -b $A $B/settings)" 'name="hq_small" value="1" checked'
T=$(csrf $A /settings)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'section=meeting_custom&bw_save_off=1&resolution=1080&default_view=tile' $B/save-settings
hasnt "T74 取消勾選後：會議頁不再帶較高畫質門檻" "$(curl -s -b $A $B/meeting)" "minHeightForQualityLvl"
T=$(csrf $A /dashboard)
curl -s -o /dev/null -b $A -c $A --data-urlencode "_csrf=$T" -d 'room=itest-down' $B/room-delete

T=$(csrf $A /dashboard)
chk "POST /logout 帶 CSRF → 登出" "$(loc -b $A -c $A --data-urlencode "_csrf=$T" $B/logout)" "$B/jt-login"
chk "登出後 /dashboard → 404" "$(code -b $A $B/dashboard)" 404

echo
echo "$PASS passed, $FAIL failed"
[ $FAIL -eq 0 ]
