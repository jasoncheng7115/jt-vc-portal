#!/usr/bin/env bash
# 逐字稿與摘要整合測試（v1.12.0，項目 T13–T30；v1.16.0 起含 T54–T57 會議主要語言與台語）：拋棄式 portal ＋ JTLW 官方 mock 伺服器 ＋ 假 Jibri 錄影服務。
# 不碰任何正式系統。JTLW mock 來自 JTLW 交付的 docs-share/jtlw/jtlw-mock（維護者本機才有；沒有就略過）。
# 用法：tests/run-transcribe.sh
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC=${SRC:-$ROOT/app}
MOCK=${JTLW_MOCK_DIR:-$ROOT/docs-share/jtlw/jtlw-mock}
[ -f "$MOCK/mock_server.py" ] || { echo "SKIP: JTLW mock not found ($MOCK)"; exit 0; }
NET=jtvc-tx-net; PN=jtvc-tx-portal; JN=jtvc-tx-jtlw; BN=jtvc-tx-jibri; PPORT=58196
ADMIN_PW='Tx-Admin-Pass-123456'; HOST_PW='Tx-Host-Pass-123456'; KEY='jtlw_jtvc_testkey'
pass=0; fail=0
ok()   { echo "  ok   $1"; pass=$((pass+1)); }
bad()  { echo "  FAIL $1${2:+ — $2}"; fail=$((fail+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got [$2] want [$3]"; fi; }
cleanup() { docker rm -f $PN $JN $BN >/dev/null 2>&1; docker network rm $NET >/dev/null 2>&1; rm -rf "${DATA:-}" "${JDATA:-}" "${KEYS:-}"; }
[ -n "${KEEP:-}" ] || trap cleanup EXIT
cleanup; DATA=$(mktemp -d); JDATA=$(mktemp -d); KEYS=$(mktemp -d)
docker network create $NET >/dev/null

echo "== 準備：JTLW mock、假 Jibri、portal"
if ! docker image inspect jtvc-jtlw-mock >/dev/null 2>&1; then
  printf 'FROM python:3.12-slim\nRUN pip install --no-cache-dir fastapi uvicorn jsonschema python-multipart\n' | docker build -q -t jtvc-jtlw-mock - >/dev/null || { echo "cannot build mock image"; exit 1; }
fi
docker run -d --name $JN --network $NET --network-alias jtlw -v "$MOCK":/mock:ro -w /mock -e JTLW_MOCK_API_KEY=$KEY jtvc-jtlw-mock python mock_server.py --port 8990 --speed 20 >/dev/null
NOW=$(date +%s)
mkrec() { head -c 20000 /dev/urandom > "$JDATA/$1.mp4"; }
for r in rec-live01 rec-ok01 rec-sum01 rec-sum02 rec-fail01 rec-q01 rec-slow01 rec-auto01 rec-old01 rec-dup01 rec-gone01 rec-other01 rec-lang01 rec-retry01; do mkrec $r; done
python3 - "$JDATA" "$NOW" <<'PY'
import json,sys
d,now=sys.argv[1],int(sys.argv[2])
rows=[("rec-ok01","room-man",now-200),("rec-sum01","room-man",now-190),("rec-fail01","room-man",now-180),("rec-q01","room-man",now-170),
      ("rec-slow01","room-man",now-160),("rec-auto01","room-auto",now-150),("rec-old01","room-auto",now-100000),("rec-dup01","room-man",now-140),
      ("rec-gone01","room-man",now-130),("rec-sum02","room-man",now-125),("rec-other01","room-other",now-120),("rec-lang01","room-man",now-115),("rec-retry01","room-man",now-110)]
recs=[{"id":i,"room":r,"file":f"{r}.mp4","size":20000,"mtime":m,"status":"ok","duration":120} for i,r,m in rows]
recs.append({"id":"rec-live01","room":"room-man","file":"room-man-live.mp4","size":4000,"mtime":now-5,"status":"recording","duration":0})
json.dump(recs,open(f"{d}/recs.json","w"))
PY
chmod -R a+rwX "$JDATA"
docker run -d --name $BN --network $NET --network-alias jibri -v "$ROOT/tests/stub":/stub:ro -v "$JDATA":/data php:8.4-cli php -S 0.0.0.0:9080 /stub/jibri.php >/dev/null
openssl genrsa -out "$KEYS/private.key" 2048 2>/dev/null; chmod 644 "$KEYS/private.key"; chown 33:33 "$DATA"
docker build -q -t jtvc-tx-portal "$SRC" >/dev/null || exit 1
docker run -d --name $PN --network $NET --network-alias portal -p 127.0.0.1:$PPORT:58189 -e JTVC_ADMIN_PASSWORD="$ADMIN_PW" \
  -v "$KEYS":/var/www/html/keys:ro -v "$DATA":/var/jaas-data jtvc-tx-portal >/dev/null
for i in $(seq 1 40); do curl -s -o /dev/null http://127.0.0.1:$PPORT/ && docker exec $JN python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8990/api/v1/health')" 2>/dev/null && break; sleep 1; done

php_() { docker exec -i -u www-data $PN php; }
php_ <<EOF
<?php
require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php';
Settings::setSection('jaas', ['_v'=>2,'mode'=>'selfhosted','domain'=>'meet.example.com','sh_auth'=>'none','site_url'=>'http://portal:58189']);
Settings::setJibri('http://jibri:9080', 'jibri-test');
Users::bootstrap();
\$none = Users::create(['username'=>'hnone','email'=>'hnone@example.com','password'=>'$HOST_PW','role'=>'host']);
\$man  = Users::create(['username'=>'hman','email'=>'hman@example.com','password'=>'$HOST_PW','role'=>'host']);
\$auto = Users::create(['username'=>'hauto','email'=>'hauto@example.com','password'=>'$HOST_PW','role'=>'host']);
\$oth  = Users::create(['username'=>'hother','email'=>'hother@example.com','password'=>'$HOST_PW','role'=>'host']);
Users::update(\$man['id'], ['transcribe'=>'manual']); Users::update(\$auto['id'], ['transcribe'=>'auto']); Users::update(\$oth['id'], ['transcribe'=>'manual']);
\$now = $NOW;
foreach ([['room-man', \$man['id']], ['room-auto', \$auto['id']], ['room-other', \$oth['id']]] as [\$room, \$owner]) {
  // room-man 有 Jitsi 主要發言者時間軸（整場都是 Amy）→ 逐字稿頁應建議 Amy；其他房間沒有 → 顯示「沒有時間軸」提示
  \$talk = \$room === 'room-man' ? [['n'=>'Amy','s'=>(\$now-100000-3600)*1000,'e'=>(\$now-50)*1000]] : [];
  Store::appendLine(Rooms::MEETINGS_FILE, ['ts'=>\$now-50,'room'=>\$room,'start'=>\$now-100000-3600,'end'=>\$now-50,'dur'=>1,'owner'=>\$owner,'owner_name'=>\$room,'attendees'=>1,'peak'=>1,'participants'=>[['name'=>'Amy','in'=>\$now-3000,'out'=>\$now-60],['name'=>'Ben','in'=>\$now-3000,'out'=>\$now-60]],'transcribe'=>null,'talk'=>\$talk]);
}
Settings::setTranscribe(['enabled'=>true,'jtlw_url'=>'http://jtlw:8990','jtlw_key'=>'$KEY','language'=>'zh-Hant','profile_id'=>'meeting.balanced','summarize'=>true]);
// auto_since：讓 rec-old01（很久以前錄的）不在自動範圍內
\$d = Settings::getSection('transcribe'); \$d['auto_since'] = \$now - 50000; Settings::setSection('transcribe', \$d);
EOF
worker() { docker exec ${1:+-e JTVC_JTLW_MOCK_SCENARIO=$1} -u www-data $PN php /var/www/html/transcribe-worker.php -v 2>&1 | tail -1; }
status() { docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; \$e=Transcripts::get('$1'); echo \$e ? \$e['$2'] ?? '' : 'none';"; }
enq() { docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; foreach (Recordings::listRecordings() as \$r) if (\$r['id']==='$1') echo Transcripts::enqueue(\$r,'manual',Users::findByLogin('hman')) ? 'ok' : 'no';"; }
until_done() {  # $1 rec  $2 scenario  → 跑 worker 直到終態（最多 40 輪）
  for i in $(seq 1 40); do worker "$2" >/dev/null; s=$(status "$1" status); case $s in done|partial|failed|cancelled) echo "$s"; return;; esac; sleep 1; done; echo "timeout:$(status "$1" status)"; }

echo "== T13 註冊 webhook（portal → JTLW）並存下密鑰"
php_ <<'EOF'
<?php
require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php';
$wh = Jtlw::createWebhook(rtrim(SITE_URL, '/') . '/jtlw-webhook');
Settings::setTranscribe(['webhook_endpoint_id' => $wh['endpoint_id'], 'webhook_secret' => $wh['secret']], true);
EOF
WS=$(docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; echo Settings::getTranscribe()['webhook_secret'] !== '' && Settings::getTranscribe()['webhook_endpoint_id'] !== '' ? 'yes' : 'no';")
chk "T13 webhook 已註冊（endpoint_id 與 secret 已存）" "$WS" yes

echo "== T14 手動產生 → 上傳 → 送件 → 完成 → 取回逐字稿與摘要 → ACK"
chk "T14 enqueue" "$(enq rec-ok01)" ok
chk "T14 最終狀態 done" "$(until_done rec-ok01 success)" done
F=$(docker exec $PN sh -c 'ls /var/jaas-data/transcripts/rec-ok01/ | sort | tr "\n" " "')
chk "T14 存下 summary.json / summary.md / transcript.json" "$F" "summary.json summary.md transcript.json "
chk "T14 summary_status ok" "$(status rec-ok01 summary_status)" ok
chk "T14 已 ACK（JTLW 端內容已刪）" "$(status rec-ok01 acked)" 1
JOB=$(status rec-ok01 job_id)
CLR=$(docker exec $JN python -c "
import urllib.request,urllib.error,json
r=urllib.request.Request('http://127.0.0.1:8990/api/v1/jobs/$JOB/summary',headers={'Authorization':'Bearer $KEY'})
try: urllib.request.urlopen(r); print('still')
except urllib.error.HTTPError as e: print(json.loads(e.read())['error']['details'].get('reason'))")
chk "T14 ACK 後 JTLW 摘要回 content_cleared" "$CLR" content_cleared
SEGS=$(docker exec $PN php -r '$t=json_decode(file_get_contents("/var/jaas-data/transcripts/rec-ok01/transcript.json"),true); $s=$t["segments"]; echo count($s)>0 && isset($s[0]["start_ms"],$s[0]["speaker"],$s[0]["text"]) ? "ok" : "bad";')
chk "T14 逐字稿段落含時間、發言者、文字" "$SEGS" ok
HINT=$(docker exec $JN python -c "
import urllib.request,json
r=urllib.request.Request('http://127.0.0.1:8990/api/v1/jobs/$JOB',headers={'Authorization':'Bearer $KEY'})
j=json.load(urllib.request.urlopen(r)); print(j.get('external_ref',{}).get('system'),j.get('external_ref',{}).get('job_id'))")
chk "T14 external_ref 帶 system=jtvc 與錄影 id" "$HINT" "jtvc rec-ok01"

echo "== T15 webhook 事件：驗簽通過、去重記錄"
sleep 2
EV=$(docker exec $PN php -r '$d=json_decode(@file_get_contents("/var/jaas-data/transcripts-events.json"),true); echo is_array($d) && count($d)>0 ? "yes" : "no";')
chk "T15 portal 收到並記錄 JTLW 的 webhook 事件（簽章驗證通過）" "$EV" yes
BADSIG=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-JTLW-Timestamp: $(date +%s)" -H 'X-JTLW-Signature: v1=deadbeef' --data '{"event_id":"evt_x"}' http://127.0.0.1:$PPORT/jtlw-webhook)
chk "T15 簽章錯誤 → 401" "$BADSIG" 401
GETWH=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$PPORT/jtlw-webhook)
chk "T15 GET webhook → 405" "$GETWH" 405

echo "== T16 摘要失敗 → partial（逐字稿在）→ 重做摘要"
enq rec-sum01 >/dev/null
chk "T16 狀態 partial" "$(until_done rec-sum01 summary_failed)" partial
chk "T16 逐字稿仍存下" "$(docker exec $PN sh -c 'test -f /var/jaas-data/transcripts/rec-sum01/transcript.json && echo yes')" yes
chk "T16 partial 先不 ACK（才能重做摘要）" "$(status rec-sum01 acked)" ""
RS=$(docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; echo Transcripts::retrySummary('rec-sum01') ? 'ok':'no';")
chk "T16 retry 送出" "$RS" ok
chk "T16 重做後 done" "$(until_done rec-sum01 success)" done

echo "== T39 摘要因 LLM 暫時故障失敗 → 排定自動重做 → 時間到自動重做 → 完成"
enq rec-sum02 >/dev/null
chk "T39 先是 partial" "$(until_done rec-sum02 summary_failed)" partial
RA=$(docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; echo Transcripts::get('rec-sum02')['summary_retry_at'] > time() ? 'scheduled':'no';")
chk "T39 已排定自動重做" "$RA" scheduled
docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; Store::update(Transcripts::INDEX_FILE, function (\$d) { \$d['rec-sum02']['summary_retry_at'] = time() - 1; return \$d; }, []);"
chk "T39 時間到自動重做後完成" "$(until_done rec-sum02 success)" done
chk "T39 記錄自動重做次數" "$(status rec-sum02 summary_retries)" 1

echo "== T17 辨識失敗 → failed、不自動重送"
enq rec-fail01 >/dev/null
chk "T17 狀態 failed" "$(until_done rec-fail01 failed)" failed
chk "T17 錯誤代碼 asr_failed" "$(status rec-fail01 error_code)" asr_failed
echo "== T61 可重試的辨識失敗：排定自動重試，時間到直接 retry（錄影還在 JTLW，不重傳）"
chk "T61 rec-fail01 也已排定自動重試" "$([ "$(status rec-fail01 job_retry_at)" -gt "$(date +%s)" ] && echo yes)" yes
enq rec-retry01 >/dev/null
chk "T61 第一次辨識失敗" "$(until_done rec-retry01 failed)" failed
chk "T61 已排定自動重試" "$([ "$(status rec-retry01 job_retry_at)" -gt "$(date +%s)" ] && echo yes)" yes
docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; Store::update(Transcripts::INDEX_FILE, function (\$d) { \$d['rec-retry01']['job_retry_at'] = 1; return \$d; }, []);"
UPL=$(status rec-retry01 upload_id)
chk "T61 時間到自動 retry 後完成" "$(until_done rec-retry01 success)" done
chk "T61 記錄自動重試次數" "$(status rec-retry01 job_retries)" 1
chk "T61 沒有重新上傳（沿用同一個 upload）" "$(status rec-retry01 upload_id)" "$UPL"
# rec-fail01 當成「重試次數用完」：瀏覽器測試（T40）要看的是永久失敗的樣子
docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; Store::update(Transcripts::INDEX_FILE, function (\$d) { \$d['rec-fail01']['job_retry_at'] = 0; \$d['rec-fail01']['job_retries'] = 3; return \$d; }, []);"

echo "== T18 佇列滿（429）→ 退回 pending 並排定重試"
enq rec-q01 >/dev/null; worker queue_full >/dev/null
chk "T18 狀態 pending" "$(status rec-q01 status)" pending
chk "T18 attempts=1" "$(status rec-q01 attempts)" 1
NT=$(docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; echo Transcripts::get('rec-q01')['next_try_at'] > time() ? 'later':'now';")
chk "T18 下次重試時間在未來（退避）" "$NT" later

echo "== T19 取消（slow）"
enq rec-slow01 >/dev/null; worker slow >/dev/null
CAN=$(docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; echo Transcripts::cancel('rec-slow01') ? 'ok':'no';")
chk "T19 送出取消" "$CAN" ok
chk "T19 最終 cancelled" "$(until_done rec-slow01 slow)" cancelled

echo "== T20 自動產生：帳號 auto、啟用之後錄的才處理"
worker >/dev/null
chk "T20 rec-auto01（auto 帳號）自動排入" "$([ "$(status rec-auto01 trigger)" = auto ] && echo yes)" yes
chk "T20 rec-old01（啟用前錄的）不處理" "$(status rec-old01 status)" none
chk "T20 rec-other01（manual 帳號）不自動" "$(status rec-other01 status)" none
chk "T20 自動那件最後完成" "$(until_done rec-auto01 success)" done

echo "== T21 重複事件（duplicate）只處理一次"
enq rec-dup01 >/dev/null
chk "T21 狀態 done" "$(until_done rec-dup01 duplicate)" done

echo "== T22 錄影不在了 → 刪除本地結果與 JTLW 紀錄"
enq rec-gone01 >/dev/null; until_done rec-gone01 success >/dev/null
python3 - "$JDATA" <<'PY'
import json,sys
p=sys.argv[1]+"/recs.json"; d=json.load(open(p)); json.dump([r for r in d if r["id"]!="rec-gone01"],open(p,"w"))
PY
worker >/dev/null
chk "T22 索引已移除" "$(status rec-gone01 status)" none
chk "T22 本地檔案已刪" "$(docker exec $PN sh -c 'test -d /var/jaas-data/transcripts/rec-gone01 && echo still || echo gone')" gone

echo "== 瀏覽器：權限、檢視頁、改名、引用跳播、下載"
export PN
PW_MOD=${PLAYWRIGHT_MODULE:-/opt/jt-ipam/frontend/node_modules/.pnpm/playwright@1.60.0/node_modules/playwright}
PLAYWRIGHT_MODULE=$PW_MOD node "$ROOT/tests/e2e/transcribe.cjs" "http://127.0.0.1:$PPORT" "$ADMIN_PW" "$HOST_PW" | tee /tmp/.tx-e2e.$$
P=$(grep -oE '^[0-9]+ passed' /tmp/.tx-e2e.$$ | grep -oE '^[0-9]+'); F=$(grep -oE '[0-9]+ failed' /tmp/.tx-e2e.$$ | tail -1 | grep -oE '^[0-9]+'); rm -f /tmp/.tx-e2e.$$
pass=$((pass + ${P:-0})); fail=$((fail + ${F:-1}))

echo "== T56 台語：選台語後送件用台語專用模式、不要求發言者分離"
chk "T56 在錄影記錄選「台語」後排入，語言為 nan-Hant" "$(status rec-lang01 language)" nan-Hant
chk "T56 完成" "$(until_done rec-lang01 success)" done
JOBL=$(status rec-lang01 job_id)
PLAN=$(docker exec $JN python -c "
import urllib.request,json
r=urllib.request.Request('http://127.0.0.1:8990/api/v1/jobs/$JOBL',headers={'Authorization':'Bearer $KEY'})
j=json.load(urllib.request.urlopen(r)); t=j.get('tasks'); t=sorted(t.keys() if isinstance(t,dict) else t)
print(j.get('profile_id'), ','.join(t))")
chk "T56 送件內容：台語模式、沒有 diarize（有摘要）" "$PLAN" "transcribe.taiwanese correct,summarize,transcribe"
chk "T56 逐字稿取回（標示沒有發言者分離）" "$(docker exec -u www-data $PN php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; \$t=Transcripts::result('rec-lang01'); echo count(\$t['segments'] ?? []) > 0 && !empty(\$t['diarize_skipped']) ? 'yes' : 'no';")" yes

cat > /tmp/.tx-nan.$$.cjs <<'JS'
const { chromium } = require(process.env.PLAYWRIGHT_MODULE);
const [,, BASE, PW] = process.argv;
(async () => { const b = await chromium.launch(); const p = await b.newPage({ locale: 'zh-TW', extraHTTPHeaders: { 'Accept-Language': 'zh-TW' } });
  await p.goto(BASE + '/jt-login'); await p.fill('#email', 'hman'); await p.fill('#password', PW);
  await Promise.all([p.waitForURL(/dashboard/), p.click('form[action="/verify"] button[type=submit]')]);
  await p.goto(BASE + '/transcript?id=rec-lang01'); await p.waitForTimeout(800);
  const r = await p.evaluate(() => ({
    note: !!document.getElementById('txNanNote'),
    spkCol: [...document.querySelectorAll('#txSegs .mt-s')].some(e => getComputedStyle(e).display !== 'none'),
    spkStats: !document.getElementById('txSpkWrap').hidden,
    suggest: !document.getElementById('txSuggest').hidden,
    lang: document.querySelector('.tx-meta').textContent.includes('台語（閩南語）為主') }));
  const pdf = await (await p.request.get(BASE + '/transcript-download?id=rec-lang01&f=html')).text();
  console.log([r.note, !r.spkCol, !r.spkStats, !r.suggest, r.lang, pdf.includes('僅供參考')].map(x => x ? 'y' : 'n').join(''));
  await b.close(); })();
JS
NAN=$(PLAYWRIGHT_MODULE=$PW_MOD node /tmp/.tx-nan.$$.cjs "http://127.0.0.1:$PPORT" "$HOST_PW" 2>&1 | tail -1); rm -f /tmp/.tx-nan.$$.cjs
chk "T57 台語逐字稿頁：摘要標示僅供參考、不顯示發言者欄 / 發言統計 / 發言者建議、顯示主要語言；匯出也標示" "$NAN" yyyyyy

echo
echo "TRANSCRIBE: $pass passed, $fail failed"
[ $fail -eq 0 ]
