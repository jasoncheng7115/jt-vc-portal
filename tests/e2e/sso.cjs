// SSO 真實流程測試（Playwright + 拋棄式 Keycloak）。由 tests/run-sso.sh 呼叫。
// 用法：node sso.cjs <portal-url> <keycloak-base> <user-password> <portal-admin-password>
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { execSync } = require('child_process');
const crypto = require('crypto');
const [,, PORTAL, KC, UPW, APW] = process.argv;
const PN = process.env.PN, KCN = process.env.KCN;
let pass = 0, fail = 0;
const ok = (n, c, i = '') => { c ? (pass++, console.log('  ok   ' + n)) : (fail++, console.log('  FAIL ' + n + (i ? ' — ' + String(i).slice(0, 160) : ''))); };
const sh = (c) => execSync(c, { encoding: 'utf8' });
const phpc = (code) => execSync(`docker exec -i -u www-data ${PN} php`, { input: '<?php ' + code, encoding: 'utf8' });
const cli = (args) => sh(`docker exec -u www-data ${PN} php /var/www/html/sso-cli.php ${args}`);
const audit = () => sh(`docker exec ${PN} cat /var/jaas-data/audit-log.jsonl`);
const usersJson = () => JSON.parse(sh(`docker exec ${PN} cat /var/jaas-data/users.json`)).users;
const kc = (args) => sh(`docker exec -i ${KCN} /opt/keycloak/bin/kcadm.sh ${args}`);

function totp(b32, t = Date.now()) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of b32.replace(/[^A-Z2-7]/gi, '').toUpperCase()) bits += A.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from((bits.match(/.{8}/g) || []).map(b => parseInt(b, 2)));
  const ctr = Buffer.alloc(8); ctr.writeBigUInt64BE(BigInt(Math.floor(t / 1000 / 30)));
  const h = crypto.createHmac('sha1', key).update(ctr).digest(); const o = h[h.length - 1] & 15;
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0');
}
const secrets = {};
const usedWin = {};
// Keycloak 預設不允許同一時間窗內重複使用同一組 TOTP（防重放）→ 等到下一個 30 秒窗再產生
async function freshTotp(user) {
  let w = Math.floor(Date.now() / 30000);
  while (usedWin[user] !== undefined && w <= usedWin[user]) { await new Promise(r => setTimeout(r, 1000)); w = Math.floor(Date.now() / 30000); }
  usedWin[user] = w;
  return totp(secrets[user]);
}

async function ssoLogin(page, user, { expectOtpSetup = false } = {}) {
  await page.goto(PORTAL + '/jt-login');
  await Promise.all([page.waitForURL(/kc\.test/), page.click('a[href="/sso-login"]')]);
  await page.fill('#username', user); await page.fill('#password', UPW);
  await page.click('#kc-login');
  await page.waitForLoadState('domcontentloaded');
  if (await page.$('#totp')) {                                   // 首次登入：Keycloak 強制設定 OTP
    const manual = await page.$('#mode-manual'); if (manual) await manual.click();
    const secret = (await page.textContent('#kc-totp-secret-key')).replace(/\s+/g, '');
    secrets[user] = secret;
    await page.fill('#totp', await freshTotp(user)); await page.fill('#userLabel', 'e2e');
    await Promise.all([page.waitForURL(u => !/login-actions/.test(u.toString()) || /\/(dashboard|jt-login)/.test(u.toString()), { timeout: 15000 }), page.click('input[type=submit],button[type=submit]')]);
    if (expectOtpSetup) ok(`S23 ${user}：首次登入被要求設定 OTP`, true);
  } else if (await page.$('#otp')) {                              // 之後登入：輸入 OTP
    await page.fill('#otp', await freshTotp(user));
    await page.click('#kc-login');
  } else if (expectOtpSetup) ok(`S23 ${user}：首次登入被要求設定 OTP`, false, page.url());
  await page.waitForLoadState('domcontentloaded');
}

(async () => {
  const b = await chromium.launch({ args: ['--host-resolver-rules=MAP kc.test 127.0.0.1'] });

  console.log('== S21 / S01 基本');
  let r = await (await b.newPage()).request.get(PORTAL + '/sso-cli.php');
  ok('S21 sso-cli.php 網頁存取 → 404', r.status() === 404);
  const cliShow = cli('show');
  ok('S21 sso-cli show 顯示設定', /SSO enabled\s+: yes/.test(cliShow), cliShow);

  console.log('== S05 / S17 登入頁與授權請求');
  const p1 = await (await b.newContext()).newPage();
  let authReq = null;
  p1.on('request', q => { if (q.url().includes('/protocol/openid-connect/auth')) authReq = new URL(q.url()); });
  const lg = await p1.goto(PORTAL + '/jt-login');
  ok('S05 登入頁有 SSO 按鈕', !!(await p1.$('a[href="/sso-login"]')));
  ok('S17 CSP form-action 含 IdP 來源', (lg.headers()['content-security-policy'] || '').includes(`form-action 'self' ${KC}`), lg.headers()['content-security-policy']);
  await Promise.all([p1.waitForURL(/kc\.test/), p1.click('a[href="/sso-login"]')]);
  ok('S05 授權請求帶 PKCE S256', authReq && authReq.searchParams.get('code_challenge_method') === 'S256' && (authReq.searchParams.get('code_challenge') || '').length >= 43);
  ok('S05 授權請求帶 state / nonce', authReq && (authReq.searchParams.get('state') || '').length >= 32 && (authReq.searchParams.get('nonce') || '').length >= 32);
  ok('S05 redirect_uri 固定為 /sso-callback', authReq && authReq.searchParams.get('redirect_uri') === PORTAL + '/sso-callback');

  console.log('== S11/S12/S23 管理員 alice（首次登入設定 OTP）');
  let cb = null;
  p1.on('request', q => { if (q.url().startsWith(PORTAL + '/sso-callback')) cb = q.url(); });
  await p1.fill('#username', 'alice'); await p1.fill('#password', UPW); await p1.click('#kc-login');
  await p1.waitForLoadState('domcontentloaded');
  ok('S23 alice：首次登入被要求設定 OTP', !!(await p1.$('#totp')), p1.url());
  if (await p1.$('#totp')) {
    const manual = await p1.$('#mode-manual'); if (manual) await manual.click();
    secrets.alice = (await p1.textContent('#kc-totp-secret-key')).replace(/\s+/g, '');
    await p1.fill('#totp', await freshTotp('alice')); await p1.fill('#userLabel', 'e2e');
    await Promise.all([p1.waitForURL(/\/dashboard/, { timeout: 20000 }).catch(() => {}), p1.click('input[type=submit],button[type=submit]')]);
  }
  ok('S11 alice 登入後到儀表板', p1.url().includes('/dashboard'), p1.url());
  let alice = usersJson().find(u => u.username === 'alice');
  ok('S12 自動建立 SSO 帳號（auth=oidc、綁 sub）', alice && alice.auth === 'oidc' && alice.oidc_sub && alice.oidc_iss === KC + '/realms/jtvc');
  ok('S11 VC-Admins → admin', alice && alice.role === 'admin');
  ok('S18 稽核 sso_login', /"action":"sso_login"[^\n]*"actor":"alice"/.test(audit()));
  const acc = await p1.goto(PORTAL + '/accounts');
  ok('S13 帳號管理顯示 SSO 徽章', (await p1.content()).includes('badge-sso'));
  await p1.goto(PORTAL + '/profile');
  ok('S13 個人設定不顯示密碼與 2FA 表單', !(await p1.$('input[name=current_password]')) && !(await p1.$('a[href="/profile?setup=1"]')));

  console.log('== S06 回呼重放');
  const failsBefore = (audit().match(/"action":"sso_fail"/g) || []).length;
  await p1.goto(cb);
  const lastFail = audit().trim().split('\n').filter(l => l.includes('"action":"sso_fail"')).pop() || '';
  ok('S06 重放同一個回呼網址 → 拒絕並記稽核', (audit().match(/"action":"sso_fail"/g) || []).length === failsBefore + 1 && /expired|state/.test(lastFail), lastFail);

  console.log('== S16 登出（同步登出 IdP）');
  await p1.goto(PORTAL + '/dashboard');
  let sawEnd = false; p1.on('request', q => { if (q.url().includes('/protocol/openid-connect/logout')) sawEnd = q.url(); });
  await p1.click('form[action="/logout"] button');
  await p1.waitForLoadState('domcontentloaded');
  if (await p1.$('#kc-logout')) { await p1.click('#kc-logout'); await p1.waitForLoadState('domcontentloaded'); }
  ok('S16 導向 IdP end_session 並帶 id_token_hint', sawEnd && sawEnd.includes('id_token_hint='), sawEnd);
  r = await p1.request.get(PORTAL + '/dashboard', { maxRedirects: 0 });
  ok('S16 portal session 已清除', r.status() === 404);

  console.log('== S23 第二次登入需輸入 OTP；S11 主持人 bob');
  const p2 = await (await b.newContext()).newPage();
  await ssoLogin(p2, 'alice');
  ok('S23 alice 再次登入（輸入 TOTP）成功', p2.url().includes('/dashboard'), p2.url());
  const p3 = await (await b.newContext()).newPage();
  await ssoLogin(p3, 'bob', { expectOtpSetup: true });
  ok('S11 VC-Hosts → host', (usersJson().find(u => u.username === 'bob') || {}).role === 'host');

  console.log('== S11 不在群組 carol；S12 帳號名稱衝突 dave');
  const p4 = await (await b.newContext()).newPage();
  await ssoLogin(p4, 'carol');
  ok('S11 不在任何群組 → 拒絕登入', /jt-login/.test(p4.url()) && !usersJson().some(u => u.username === 'carol'), p4.url());
  ok('S18 失敗訊息為通用文字（不洩漏原因）', !(await p4.content()).includes('not in any allowed group'));
  ok('S18 稽核記錄原因', /"action":"sso_fail"[^\n]*not in any allowed group/.test(audit()));
  const p5 = await (await b.newContext()).newPage();
  await ssoLogin(p5, 'dave');
  ok('S12 與本地帳號同名 → 拒絕（不自動併入）', /jt-login/.test(p5.url()) && usersJson().filter(u => u.username === 'dave').length === 1 && /"action":"sso_fail"[^\n]*conflicts/.test(audit()), p5.url());

  console.log('== S12 停用的 SSO 帳號');
  const bobId = usersJson().find(u => u.username === 'bob').id;
  phpc(`require '/var/www/html/lib/users.php'; Users::update('${bobId}', ['disabled' => true]);`);
  r = await p3.request.get(PORTAL + '/dashboard', { maxRedirects: 0 });
  ok('S12 停用後既有 session 立即失效', r.status() === 404);
  const p6 = await (await b.newContext()).newPage();
  await ssoLogin(p6, 'bob');
  ok('S12 停用後無法再以 SSO 登入', /jt-login/.test(p6.url()) && /"action":"sso_fail"[^\n]*disabled/.test(audit()), p6.url());

  console.log('== S13 SSO 帳號不能用密碼登入');
  const p7 = await (await b.newContext()).newPage();
  await p7.goto(PORTAL + '/jt-login');
  await p7.fill('#email', 'alice'); await p7.fill('#password', 'anything-at-all-123');
  await p7.click('form[action="/verify"] button[type=submit]');
  ok('S13 SSO 帳號以密碼登入 → 失敗', /jt-login/.test(p7.url()));

  console.log('== S14 / S15 僅限單一登入');
  phpc(`require '/var/www/html/lib/settings.php'; $o = Settings::getOidc(); $o['sso_only'] = true; $o['local_login_cidrs'] = '10.99.99.0/24'; $o['client_secret'] = ''; Settings::setOidc($o);`);
  const p8 = await (await b.newContext()).newPage();
  await p8.goto(PORTAL + '/jt-login');
  ok('S14 非允許 IP：看不到本地密碼表單', !(await p8.$('form[action="/verify"]')) && !!(await p8.$('a[href="/sso-login"]')));
  // 先在允許 IP 狀態取得有效 CSRF token，再切回不允許 → 證明「即使帶有效 token，非允許 IP 仍被擋」
  const gw0 = sh(`docker network inspect jtvc-sso-net -f '{{(index .IPAM.Config 0).Gateway}}'`).trim();
  phpc(`require '/var/www/html/lib/settings.php'; $o = Settings::getOidc(); $o['local_login_cidrs'] = '${gw0}'; $o['client_secret'] = ''; Settings::setOidc($o);`);
  const csrf = await p8.evaluate(() => fetch('/jt-login').then(r => r.text()).then(t => (t.match(/name="_csrf" value="([^"]+)"/) || [])[1] || ''));
  phpc(`require '/var/www/html/lib/settings.php'; $o = Settings::getOidc(); $o['local_login_cidrs'] = '10.99.99.0/24'; $o['client_secret'] = ''; Settings::setOidc($o);`);
  r = await p8.request.post(PORTAL + '/verify', { form: { _csrf: csrf || 'x', email: 'jtvc-admin', password: APW }, maxRedirects: 0 });
  const dashAfter = await p8.request.get(PORTAL + '/dashboard', { maxRedirects: 0 });
  ok('S14 非允許 IP 直接 POST /verify 也被擋', !dashAfter.ok() && /本地登入不允許此來源|local sign-in not allowed/.test(audit()), `verify=${r.status()} dashboard=${dashAfter.status()}`);
  const gw = sh(`docker network inspect jtvc-sso-net -f '{{(index .IPAM.Config 0).Gateway}}'`).trim();
  phpc(`require '/var/www/html/lib/settings.php'; $o = Settings::getOidc(); $o['local_login_cidrs'] = '${gw}'; $o['client_secret'] = ''; Settings::setOidc($o);`);
  // 前面各失敗情境（carol / dave / 重放 / 停用 / 密碼…）已讓同一來源累積 ≥5 次失敗而被鎖定（fail2ban 正常運作）；
  // 緊急用管理員情境前先清除該來源的失敗計數
  ok('S19 同一來源累積失敗後被鎖定（fail2ban 生效）', /"locked":true/.test(phpc(`require '/var/www/html/lib/ratelimit.php'; echo json_encode(RateLimit::status('${gw}'));`)));
  phpc(`require '/var/www/html/lib/ratelimit.php'; RateLimit::reset('${gw}');`);
  const p9 = await (await b.newContext()).newPage();
  await p9.goto(PORTAL + '/jt-login');
  await p9.fill('#email', 'jtvc-admin'); await p9.fill('#password', APW);
  await Promise.all([p9.waitForURL(/dashboard/, { timeout: 10000 }).catch(() => {}), p9.click('form[action="/verify"] button[type=submit]')]);
  ok('S14 允許 IP：緊急用本地管理員可登入', p9.url().includes('/dashboard'), p9.url());
  // S15：無本地管理員時不可開啟僅限 SSO（以設定頁送出驗證）
  phpc(`require '/var/www/html/lib/users.php'; foreach (Users::all() as $u) if (($u['auth'] ?? 'local') !== 'oidc' && $u['role'] === 'admin') Users::update($u['id'], ['role' => 'host']);`);
  await p2.goto(PORTAL + '/settings');
  const t2 = await p2.evaluate(() => (document.querySelector('#card-sso input[name=_csrf]') || {}).value);
  r = await p2.request.post(PORTAL + '/save-settings', { form: { _csrf: t2, section: 'oidc', enabled: '1', issuer: KC + '/realms/jtvc', client_id: 'jt-vc-portal', admin_groups: 'VC-Admins', host_groups: 'VC-Hosts', sso_only: '1', local_login_cidrs: '' }, maxRedirects: 0 });
  await p2.goto(PORTAL + '/settings');
  ok('S15 沒有本地管理員時不可開啟僅限 SSO', (await p2.content()).includes('alert-error'));
  r = await p2.request.post(PORTAL + '/save-settings', { form: { _csrf: t2, section: 'oidc', enabled: '1', issuer: KC + '/realms/jtvc', client_id: 'jt-vc-portal', admin_groups: 'VC-Admins', host_groups: 'VC-Hosts', sso_only: '', require_https: '' }, maxRedirects: 0 });
  phpc(`require '/var/www/html/lib/users.php'; $u = Users::findByLogin('jtvc-admin'); Users::update($u['id'], ['role' => 'admin']);`);
  await p2.goto(PORTAL + '/settings');
  const t3 = await p2.evaluate(() => (document.querySelector('#card-sso input[name=_csrf]') || {}).value);
  r = await p2.request.post(PORTAL + '/save-settings', { form: { _csrf: t3, section: 'oidc', action: 'test', enabled: '1', issuer: KC + '/realms/jtvc', client_id: 'jt-vc-portal', admin_groups: 'VC-Admins', host_groups: 'VC-Hosts', local_login_cidrs: 'not-an-ip', sso_only: '1' }, maxRedirects: 0 });
  await p2.goto(PORTAL + '/settings');
  ok('S15 CIDR 格式錯誤被擋', (await p2.content()).includes('alert-error'));
  await p2.goto(PORTAL + '/settings');
  const t4 = await p2.evaluate(() => (document.querySelector('#card-sso input[name=_csrf]') || {}).value);
  r = await p2.request.post(PORTAL + '/save-settings', { form: { _csrf: t4, section: 'oidc', action: 'test', enabled: '1', issuer: KC + '/realms/jtvc', client_id: 'jt-vc-portal', admin_groups: 'VC-Admins', host_groups: 'VC-Hosts' }, maxRedirects: 0 });
  await p2.goto(PORTAL + '/settings');
  ok('S20 儲存並測試連線成功（取得 JWKS）', (await p2.content()).includes('alert-success'));
  ok('S20 設定頁不回填 client secret', !(await p2.content()).includes(JSON.parse(phpc(`require '/var/www/html/lib/settings.php'; echo json_encode(Settings::getOidc()['client_secret']);`))));

  console.log('== S19 來源鎖定後不能發起 SSO');
  const ip = gw;
  phpc(`require '/var/www/html/lib/ratelimit.php'; for ($i = 0; $i < 5; $i++) RateLimit::fail('${ip}');`);
  const pa = await (await b.newContext()).newPage();
  r = await pa.request.get(PORTAL + '/sso-login', { maxRedirects: 0 });
  ok('S19 被鎖定來源 /sso-login 不導向 IdP', r.status() === 302 && !(r.headers().location || '').includes('kc.test'), r.headers().location);
  phpc(`require '/var/www/html/lib/ratelimit.php'; RateLimit::reset('${ip}');`);

  console.log('== S24 Keycloak 端：暴力破解偵測、PKCE 強制');
  const pb = await (await b.newContext()).newPage();
  for (let i = 0; i < 5; i++) {
    await pb.goto(PORTAL + '/sso-login'); await pb.waitForURL(/kc\.test/);
    await pb.fill('#username', 'alice'); await pb.fill('#password', 'wrong-' + i); await pb.click('#kc-login');
    await pb.waitForLoadState('domcontentloaded');
  }
  const aid = kc(`get users -r jtvc -q username=alice --fields id --format csv --noquotes`).trim();
  const bf = JSON.parse(kc(`get attack-detection/brute-force/users/${aid} -r jtvc`));
  ok('S24 Keycloak：連續 5 次錯誤 → 帳號暫時鎖定', bf.disabled === true, JSON.stringify(bf));
  kc(`delete attack-detection/brute-force/users/${aid} -r jtvc`);
  const cid = kc(`get clients -r jtvc -q clientId=jt-vc-portal --fields id --format csv --noquotes`).trim();
  const cl = JSON.parse(kc(`get clients/${cid} -r jtvc`));
  ok('S24 client 強制 PKCE S256、只允許授權碼流程', cl.attributes['pkce.code.challenge.method'] === 'S256' && cl.standardFlowEnabled && !cl.implicitFlowEnabled && !cl.directAccessGrantsEnabled && !cl.publicClient);
  ok('S24 redirect URI 僅 /sso-callback', JSON.stringify(cl.redirectUris) === JSON.stringify([PORTAL + '/sso-callback']));
  const pk = await (await b.newContext()).newPage();
  const noPkce = await pk.goto(`${KC}/realms/jtvc/protocol/openid-connect/auth?client_id=jt-vc-portal&response_type=code&scope=openid&redirect_uri=${encodeURIComponent(PORTAL + '/sso-callback')}&state=x&nonce=y`);
  ok('S24 未帶 PKCE 的授權請求被 Keycloak 拒絕', /code_challenge|pkce|error/i.test(pk.url() + (await pk.content())), pk.url());
  const badRedirect = await pk.goto(`${KC}/realms/jtvc/protocol/openid-connect/auth?client_id=jt-vc-portal&response_type=code&scope=openid&redirect_uri=${encodeURIComponent('https://evil.example.com/cb')}&state=x&nonce=y&code_challenge=abcdefghijklmnopqrstuvwxyzabcdefghijklmnopq&code_challenge_method=S256`);
  ok('S24 非註冊的 redirect_uri 被拒絕', !pk.url().startsWith('https://evil.example.com') && /redirect/i.test(await pk.content()));

  console.log('== S01 停用 SSO 後端點 404');
  cli('disable');
  r = await pk.request.get(PORTAL + '/sso-login', { maxRedirects: 0 });
  ok('S21/S01 sso-cli disable 後 /sso-login → 404', r.status() === 404);
  r = await pk.request.get(PORTAL + '/sso-callback?state=x&code=y', { maxRedirects: 0 });
  ok('S01 停用時 /sso-callback → 404', r.status() === 404);
  await pk.goto(PORTAL + '/jt-login');
  ok('S01 停用時登入頁沒有 SSO 按鈕', !(await pk.$('a[href="/sso-login"]')));

  await b.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
