#!/bin/bash
# T93：發版 SBOM（CycloneDX 1.5）。tools/make-sbom.py 產生的應用程式 SBOM 要列出所有第三方元件，
# 版本 / 雜湊要與原始碼一致、不可含機密；IMAGE=<映像> 時另用 syft 產映像 SBOM 並比對 PHP 版本。
#   tests/test-sbom.sh            （SRC 預設 github/）
#   IMAGE=jt-vc-portal:1.25.3 tests/test-sbom.sh
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC=${SRC:-$ROOT/github}
GEN="$ROOT/tools/make-sbom.py"; [ -f "$GEN" ] || GEN="$ROOT/github/tools/make-sbom.py"
SYFT=anchore/syft:v1.57.1@sha256:03215d0e98b661d0ecbc8575dbc14d65be1743b5de5df6410aecb7e80f95b060
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0
chk() { if [ "$2" = "$3" ]; then echo "  ok   $1"; PASS=$((PASS+1)); else echo "  FAIL $1 (expected $3 got $2)"; FAIL=$((FAIL+1)); fi; }

python3 "$GEN" "$SRC" > "$T/app.json" 2>"$T/err"; chk "make-sbom.py 執行成功" "$?" 0
python3 - "$T/app.json" "$SRC" > "$T/res" <<'PY'
import base64, glob, hashlib, json, os, re, sys
d = json.load(open(sys.argv[1])); src = sys.argv[2]
def out(k, v): print(f"{k}\t{v}")
out('format', f"{d['bomFormat']} {d['specVersion']}")
ver = re.search(r"define\('APP_VERSION',\s*'([^']+)'\)", open(f'{src}/config.php').read()).group(1)
out('version', 'match' if d['metadata']['component']['version'] == ver else d['metadata']['component']['version'])
comps = {c['name']: c for c in d['components']}
# 原始碼裡每個 CDN 套件（名稱@版本）都要在 SBOM，且 SRI 雜湊一致
want, sris = set(), set()
for php in glob.glob(f'{src}/**/*.php', recursive=True):
    if '/tests/' in php: continue
    t = open(php, encoding='utf-8', errors='replace').read()
    for m in re.finditer(r'cdn\.jsdelivr\.net/npm/([\w.-]+)@([\w.-]+)[^>]*?integrity=["\']sha384-([A-Za-z0-9+/=]+)', t):
        want.add((m.group(1), m.group(2))); sris.add(base64.b64decode(m.group(3)).hex())
have = {(c['name'], c['version']) for c in d['components']}
out('cdn', 'all' if want and want <= have else f'missing {sorted(want - have)}')
got = {h['content'] for c in d['components'] for h in c.get('hashes', []) if h['alg'] == 'SHA-384'}
out('cdn_sri', 'match' if sris <= got else 'mismatch')
# Jitsi IFrame API：SHA-384 要等於 Jaas::EXTERNAL_API_SRI
sri = re.search(r"EXTERNAL_API_SRI = 'sha384-([^']+)'", open(f'{src}/lib/jaas.php').read()).group(1)
j = comps.get('jitsi-meet-external-api', {})
out('jitsi', 'match' if {'alg': 'SHA-384', 'content': base64.b64decode(sri).hex()} in j.get('hashes', []) else 'mismatch')
f = comps.get('Noto Sans TC Regular', {})
out('font', f"{f.get('licenses', [{}])[0].get('license', {}).get('id')} {'ok' if re.match(r'^\d+\.\d+$', f.get('version', '')) else f.get('version')}")
out('licenses', 'all' if all(c.get('licenses') and 'NOASSERTION' not in json.dumps(c['licenses']) for c in d['components']) else 'missing')
out('deps', 'ok' if len(d['dependencies'][0]['dependsOn']) == len(d['components']) else 'bad')
PY
r() { awk -F'\t' -v k="$1" '$1==k{print $2}' "$T/res"; }
chk "CycloneDX 1.5" "$(r format)" "CycloneDX 1.5"
chk "SBOM 版本＝APP_VERSION" "$(r version)" match
chk "原始碼的 CDN 套件全部列出" "$(r cdn)" all
chk "CDN 套件 SHA-384＝頁面 SRI" "$(r cdn_sri)" match
chk "Jitsi IFrame API SHA-384＝EXTERNAL_API_SRI" "$(r jitsi)" match
chk "字型授權 OFL-1.1、版本取自字型檔" "$(r font)" "OFL-1.1 ok"
chk "每個元件都有授權" "$(r licenses)" all
chk "dependencies 涵蓋全部元件" "$(r deps)" ok
chk "應用程式 SBOM 不含機密 / 內網位址" "$(grep -cE '192\.168\.|vpaas-magic-cookie-[0-9]|PRIVATE KEY|github_pat_' "$T/app.json")" 0

if [ -n "${IMAGE:-}" ]; then
  docker run --rm -e SYFT_FILE_METADATA_SELECTION=none -v /var/run/docker.sock:/var/run/docker.sock "$SYFT" \
    "$IMAGE" -o cyclonedx-json@1.5 --source-name jt-vc-portal --source-version test -q > "$T/img.json"
  chk "syft 映像 SBOM 產生成功" "$?" 0
  PHPV=$(docker run --rm --entrypoint php "$IMAGE" -r 'echo PHP_VERSION;')
  chk "映像 SBOM 的 php-cli 版本＝映像實際 PHP" "$(python3 -c "import json,sys;d=json.load(open(sys.argv[1]));print([c.get('version') for c in d['components'] if c['name']=='php-cli'][0])" "$T/img.json")" "$PHPV"
  chk "映像 SBOM 列出 apache2 與 Debian" "$(python3 -c "import json,sys;d=json.load(open(sys.argv[1]));n={c['name'] for c in d['components']};print('apache2' in n and 'debian' in n)" "$T/img.json")" True
  chk "映像 SBOM 不含逐檔清單（體積 < 2 MB）" "$([ "$(stat -c %s "$T/img.json")" -lt 2000000 ] && echo yes || echo no)" yes
  chk "映像 SBOM 不含機密 / 內網位址" "$(grep -cE '192\.168\.|vpaas-magic-cookie-[0-9]|PRIVATE KEY|github_pat_' "$T/img.json")" 0
fi
echo "SBOM: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
