#!/bin/bash
# SSO 整合 / e2e 測試：拋棄式 Keycloak（用 SOP 的 configure-realm.sh 設定，本地帳號模式）+ 拋棄式 portal，
# Playwright 走完整流程（含首次設定 OTP 與 TOTP 登入）。不碰任何正式機。用法：tests/run-sso.sh
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC=${SRC:-$ROOT/app}
KCDIR="$ROOT/keycloak"; [ -d "$KCDIR" ] || KCDIR="$ROOT/github/keycloak"
NET=jtvc-sso-net; KCN=jtvc-kc-test; PN=jtvc-sso-portal; KCPORT=58180; PPORT=58195
WORK=$(mktemp -d); DATA=$(mktemp -d); KEYS=$(mktemp -d)
ADMIN_PW='Sso-Admin-Pass-123456'; KC_ADMIN_PW='Kc-Test-Admin-123456'; USER_PW='Test-Pass-12345!'
cleanup() { docker rm -f $KCN $PN >/dev/null 2>&1; docker network rm $NET >/dev/null 2>&1; rm -rf "$WORK" "$DATA" "$KEYS"; }
trap cleanup EXIT
cleanup 2>/dev/null; WORK=$(mktemp -d); DATA=$(mktemp -d); KEYS=$(mktemp -d)
docker network create $NET >/dev/null

echo "== 啟動 Keycloak（測試用）"
docker run -d --name $KCN --network $NET --network-alias kc.test -p 127.0.0.1:$KCPORT:$KCPORT \
  -e KC_HTTP_PORT=$KCPORT -e KC_HOSTNAME=http://kc.test:$KCPORT -e KC_HEALTH_ENABLED=true \
  -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=$KC_ADMIN_PW \
  quay.io/keycloak/keycloak:26.4 start-dev >/dev/null
for i in $(seq 1 60); do curl -sf -o /dev/null http://127.0.0.1:$KCPORT/realms/master && break; sleep 2; done

echo "== 以 SOP 腳本設定 realm（本地帳號模式）"
cp "$KCDIR/configure-realm.sh" "$WORK/"
cat > "$WORK/realm.env" <<EOT
REALM=jtvc
PORTAL_URL=http://127.0.0.1:$PPORT
CLIENT_ID=jt-vc-portal
KC_ISSUER_BASE=http://kc.test:$KCPORT
LDAP_URL=
ADMIN_GROUP=VC-Admins
HOST_GROUP=VC-Hosts
LOCKOUT_FAILURES=5
KC_ADMIN_USER=admin
KC_ADMIN_PASSWORD=$KC_ADMIN_PW
EOT
KCADM="docker exec -i $KCN /opt/keycloak/bin/kcadm.sh" KC_SERVER=http://localhost:$KCPORT SECRET_OUT="$WORK/secret" \
  bash "$WORK/configure-realm.sh" | sed 's/^/   /' || { echo "configure-realm failed"; exit 1; }
SECRET=$(cat "$WORK/secret")
echo "== S25 SOP 腳本可重複執行（client secret 不變、設定不壞）"
KCADM="docker exec -i $KCN /opt/keycloak/bin/kcadm.sh" KC_SERVER=http://localhost:$KCPORT SECRET_OUT="$WORK/secret2" \
  bash "$WORK/configure-realm.sh" >/dev/null || { echo "  FAIL S25 second run failed"; exit 1; }
[ "$(cat "$WORK/secret2")" = "$SECRET" ] && echo "  ok   S25 重跑 configure-realm.sh：成功且 client secret 不變" || { echo "  FAIL S25 client secret changed"; exit 1; }
echo "== S27 KC_ADMIN_URL：master realm 登入頁走內網管理網址，jtvc 對外 issuer 不變"
KCADM="docker exec -i $KCN /opt/keycloak/bin/kcadm.sh" KC_SERVER=http://localhost:$KCPORT SECRET_OUT="$WORK/secret3" KC_ADMIN_URL=https://kc-admin.test:8443 \
  bash "$WORK/configure-realm.sh" > "$WORK/s27.log" 2>&1 || { tail -5 "$WORK/s27.log"; echo "  FAIL S27 run with KC_ADMIN_URL failed"; exit 1; }
iss() { curl -s "http://127.0.0.1:$KCPORT/realms/$1/.well-known/openid-configuration" | python3 -c 'import sys,json;print(json.load(sys.stdin)["issuer"])'; }
MI=$(iss master); JI=$(iss jtvc)
[ "$MI" = "https://kc-admin.test:8443/realms/master" ] && echo "  ok   S27 master issuer = 管理網址（$MI）" || { echo "  FAIL S27 master issuer $MI"; exit 1; }
[ "$JI" = "http://kc.test:$KCPORT/realms/jtvc" ] && [ "$(cat "$WORK/secret3")" = "$SECRET" ] && echo "  ok   S27 jtvc issuer 不受影響、client secret 不變" || { echo "  FAIL S27 jtvc issuer $JI"; exit 1; }
echo "== S28 多語系：依瀏覽器語言顯示繁中 / 日文 / 英文登入頁（jtvc 與 master）"
RU=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1],safe=""))' "http://127.0.0.1:$PPORT/sso-callback")
AUTH="http://127.0.0.1:$KCPORT/realms/jtvc/protocol/openid-connect/auth?client_id=jt-vc-portal&response_type=code&scope=openid&redirect_uri=$RU&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&state=s28&nonce=s28"
MAUTH="http://127.0.0.1:$KCPORT/realms/master/protocol/openid-connect/auth?client_id=security-admin-console&response_type=code&scope=openid&redirect_uri=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1],safe=""))' "https://kc-admin.test:8443/admin/master/console/")&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&state=s28"
pl() { curl -s -H "Accept-Language: $2" "$1" | grep -oE '<html[^>]*lang="[^"]*"' | grep -oE 'lang="[^"]*"' | head -1; }
for c in "zh-TW,zh;q=0.9:zh-Hant" "ja:ja" "en-US:en" "fr-FR:en"; do
  al=${c%%:*}; want=${c##*:}
  got=$(pl "$AUTH" "$al"); [ "$got" = "lang=\"$want\"" ] && echo "  ok   S28 jtvc Accept-Language $al → $want" || { echo "  FAIL S28 jtvc $al → $got"; exit 1; }
done
for c in "zh-TW:繁體中文" "en-US:Traditional Chinese (繁體中文)" "ja:繁体字中国語 (繁體中文)"; do
  al=${c%%:*}; want=${c#*:}
  curl -s -H "Accept-Language: $al" "$AUTH" | grep -qF "$want" && echo "  ok   S28 語言選單顯示「$want」（$al）" || { echo "  FAIL S28 語言選單缺「$want」（$al）"; exit 1; }
done
curl -s -H "Accept-Language: zh-TW" "$MAUTH" | grep -qF "繁體中文" && echo "  ok   S28 master 語言選單顯示「繁體中文」" || { echo "  FAIL S28 master 語言選單"; exit 1; }
got=$(pl "$MAUTH" "zh-TW"); [ "$got" = 'lang="zh-Hant"' ] && echo "  ok   S28 master（管理員登入）zh-TW → zh-Hant" || { echo "  FAIL S28 master zh-TW → $got"; exit 1; }
KC="docker exec -i $KCN /opt/keycloak/bin/kcadm.sh"
for u in alice:VC-Admins bob:VC-Hosts carol: dave:VC-Hosts; do
  n=${u%%:*}; g=${u#*:}
  $KC create users -r jtvc -s username=$n -s enabled=true -s email=$n@example.com -s emailVerified=true -s firstName=$n -s lastName=Test >/dev/null
  $KC set-password -r jtvc --username $n --new-password "$USER_PW" >/dev/null
  [ "$n" = alice ] && { AUID=$($KC get users -r jtvc -q username=alice --fields id --format csv --noquotes); $KC update users/$AUID -r jtvc -s 'attributes.displayName=["陳愛麗"]' >/dev/null; }
  if [ -n "$g" ]; then
    UID_=$($KC get users -r jtvc -q username=$n --fields id --format csv --noquotes); GID=$($KC get groups -r jtvc -q search=$g --fields id --format csv --noquotes | head -1)
    $KC update users/$UID_/groups/$GID -r jtvc -s realm=jtvc -s userId=$UID_ -s groupId=$GID -n >/dev/null
  fi
done

echo "== 啟動 portal（測試用）"
openssl genrsa -out "$KEYS/private.key" 2048 2>/dev/null; chmod 644 "$KEYS/private.key"; chown 33:33 "$DATA"
docker build -q -t jtvc-sso-portal "$SRC" >/dev/null || exit 1
docker run -d --name $PN --network $NET -p 127.0.0.1:$PPORT:58189 -e JTVC_ADMIN_PASSWORD="$ADMIN_PW" \
  -v "$KEYS":/var/www/html/keys:ro -v "$DATA":/var/jaas-data jtvc-sso-portal >/dev/null
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:$PPORT/ && break; sleep 1; done
docker exec -u www-data $PN php -r "
require '/var/www/html/lib/settings.php'; require '/var/www/html/lib/users.php';
Settings::setSection('jaas', ['_v'=>2,'mode'=>'jaas','app_id'=>'vpaas-magic-cookie-test','kid'=>'t','domain'=>'8x8.vc','site_url'=>'http://127.0.0.1:$PPORT']);
Settings::setOidc(['enabled'=>true,'issuer'=>'http://kc.test:$KCPORT/realms/jtvc','client_id'=>'jt-vc-portal','client_secret'=>'$SECRET','require_https'=>false,'admin_groups'=>'VC-Admins','host_groups'=>'VC-Hosts','name_claim'=>'display_name']);
Users::bootstrap();
Users::create(['username'=>'dave','email'=>'dave-local@example.com','password'=>'Local-Dave-12345','role'=>'host']);
"
export PN KCN
PW_MOD=${PLAYWRIGHT_MODULE:-/opt/jt-ipam/frontend/node_modules/.pnpm/playwright@1.60.0/node_modules/playwright}
PLAYWRIGHT_MODULE=$PW_MOD node "$ROOT/tests/e2e/sso.cjs" "http://127.0.0.1:$PPORT" "http://kc.test:$KCPORT" "$USER_PW" "$ADMIN_PW" | tee "$WORK/e2e.out"
rc=${PIPESTATUS[0]}
echo "== S30 顯示名稱：Keycloak 使用者屬性 displayName → claim display_name → portal 顯示名稱"
DN=$(docker exec $PN php -r '$d=json_decode(file_get_contents("/var/jaas-data/users.json"),true); foreach($d["users"] as $u) if($u["username"]==="alice") echo $u["display_name"];')
BN=$(docker exec $PN php -r '$d=json_decode(file_get_contents("/var/jaas-data/users.json"),true); foreach($d["users"] as $u) if($u["username"]==="bob") echo $u["display_name"];')
[ "$DN" = "陳愛麗" ] && echo "  ok   S30 alice 顯示名稱取自 display_name（$DN）" || { echo "  FAIL S30 alice display_name=$DN"; rc=1; }
[ "$BN" = "bob" ] && echo "  ok   S30 沒有 displayName 的 bob 退回帳號名稱" || { echo "  FAIL S30 bob display_name=$BN"; rc=1; }
# 最後一行統一輸出總計（run-all.sh 只取最後一行）：sso.cjs 的結果 + S30 的 2 項
P=$(grep -oE '^[0-9]+ passed' "$WORK/e2e.out" | grep -oE '^[0-9]+'); F=$(grep -oE '[0-9]+ failed' "$WORK/e2e.out" | tail -1 | grep -oE '^[0-9]+')
S30F=$([ "$DN" = "陳愛麗" ] && echo 0 || echo 1); S30F=$((S30F + $([ "$BN" = "bob" ] && echo 0 || echo 1)))
echo "SSO: $(( ${P:-0} + 2 - S30F )) passed, $(( ${F:-1} + S30F )) failed"
exit $rc
