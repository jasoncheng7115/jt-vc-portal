#!/bin/bash
# OWASP ZAP 弱掃（發版前必跑；High / Medium 必須為 0）。
# 對「本機拋棄式測試容器」掃描：未登入 + 已登入（admin cookie）各跑 spider + 被動 + 主動掃描。
# 報告輸出到 /opt/jt-vc-portal/zap-reports/（本機，不進 GitHub）。用法：tests/zap/run-zap.sh <版本>
set -u
VER=${1:-dev}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT=$ROOT/zap-reports
PORT=${PORT:-58191}
NAME=jtvc-zap
IMG=jtvc-zap:latest
DATA=$(mktemp -d); KEYS=$(mktemp -d); JAR=$(mktemp)
ADMIN_PW='Zap-Admin-Pass-123456'
cleanup() { docker rm -f $NAME >/dev/null 2>&1; rm -rf "$DATA" "$KEYS" "$JAR"; }
trap cleanup EXIT
openssl genrsa -out "$KEYS/private.key" 2048 2>/dev/null; chmod 644 "$KEYS/private.key"; chown 33:33 "$DATA"
docker build -q -t $IMG "${SRC:-$ROOT/app}" >/dev/null || exit 1
docker run -d --name $NAME -p 127.0.0.1:$PORT:58189 -e JTVC_ADMIN_PASSWORD="$ADMIN_PW" \
  -v "$KEYS":/var/www/html/keys:ro -v "$DATA":/var/jaas-data $IMG >/dev/null
B="http://127.0.0.1:$PORT"
for i in $(seq 1 30); do curl -s -o /dev/null "$B/" && break; sleep 1; done

# 啟用 SSO（指向不存在的 IdP），讓 /sso-login、/sso-callback 納入掃描範圍（S22）
docker exec -i -u www-data $NAME php <<'PHP'
<?php require '/var/www/html/lib/settings.php';
Settings::setOidc(['enabled' => true, 'issuer' => 'http://127.0.0.1:9/realms/zap', 'client_id' => 'zap', 'client_secret' => 'zap', 'require_https' => false, 'host_groups' => 'VC-Hosts']);
// 逐字稿（v1.12.0）：啟用並設 webhook 密鑰，讓 /jtlw-webhook、/transcript*、/transcript-action 納入掃描（T31）
Settings::setJibri('http://127.0.0.1:9', 'zap');
Settings::setTranscribe(['enabled' => true, 'jtlw_url' => 'http://127.0.0.1:9', 'jtlw_key' => 'jtlw_zap_x', 'webhook_secret' => 'whsec_zap', 'webhook_endpoint_id' => 'wh_zap']);
PHP
# 登入取得 admin session cookie，並預先建立一間會議室讓掃描有內容
T=$(curl -s -c $JAR -b $JAR $B/jt-login | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -c $JAR -b $JAR --data-urlencode "_csrf=$T" -d "email=jtvc-admin" --data-urlencode "password=$ADMIN_PW" $B/verify
T=$(curl -s -c $JAR -b $JAR $B/dashboard | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -c $JAR -b $JAR --data-urlencode "_csrf=$T" -d 'room=zap-room&mode=create' $B/start
SID=$(awk '$6=="JTSESS"{print $7}' $JAR)
[ -n "$SID" ] || { echo "login failed"; exit 1; }

mk() { # name authed
  local n=$1 authed=$2
  cat > /tmp/zap-$n.yaml <<YAML
env:
  contexts:
    - name: jtvc
      urls: ["$B"]
      includePaths: ["$B/.*"]
      excludePaths: ["$B/logout.*", "$B/account-delete.*", "$B/recordings-action.*"]
  parameters:
    failOnError: false
    progressToStdout: false
jobs:
  - type: spider
    parameters: { context: jtvc, url: "$B/", maxDuration: 3 }
  - type: spider
    parameters: { context: jtvc, url: "$B/jt-login", maxDuration: 2 }
  - type: spider
    parameters: { context: jtvc, url: "$B/dashboard", maxDuration: 3 }
  - type: spider
    parameters: { context: jtvc, url: "$B/room/zap-room", maxDuration: 2 }
  - type: spider
    parameters: { context: jtvc, url: "$B/sso-callback?state=zap&code=zap", maxDuration: 1 }
  - type: spider
    parameters: { context: jtvc, url: "$B/transcript?id=zap-rec-0001", maxDuration: 1 }
  - type: spider
    parameters: { context: jtvc, url: "$B/transcript-download?id=zap-rec-0001&f=txt", maxDuration: 1 }
  - type: spider
    parameters: { context: jtvc, url: "$B/jtlw-webhook", maxDuration: 1 }
  - type: passiveScan-wait
    parameters: { maxDuration: 5 }
  - type: activeScan
    parameters: { context: jtvc, maxRuleDurationInMins: 2, maxScanDurationInMins: 20 }
  - type: report
    parameters: { template: traditional-md, reportDir: "$OUT", reportFile: "zap-$VER-$n.md", reportTitle: "jt-vc-portal $VER ZAP ($n)" }
  - type: report
    parameters: { template: traditional-html, reportDir: "$OUT", reportFile: "zap-$VER-$n.html", reportTitle: "jt-vc-portal $VER ZAP ($n)" }
YAML
}
mk anon 0
mk authed 1
echo "== ZAP 未登入掃描"
/snap/bin/zaproxy -cmd -autorun /tmp/zap-anon.yaml >/tmp/zap-anon.log 2>&1
echo "== ZAP 已登入掃描"
ZAP_AUTH_HEADER=Cookie ZAP_AUTH_HEADER_VALUE="JTSESS=$SID" ZAP_AUTH_HEADER_SITE="127.0.0.1" \
  /snap/bin/zaproxy -cmd -autorun /tmp/zap-authed.yaml >/tmp/zap-authed.log 2>&1
for n in anon authed; do
  f=$OUT/zap-$VER-$n.md
  echo "== $n：$f"
  grep -A8 '^| Risk Level' "$f" | head -8
done
