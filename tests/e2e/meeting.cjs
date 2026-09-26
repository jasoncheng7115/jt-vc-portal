// 瀏覽器 e2e：主持人登入 → 建立 → 進入會議；來賓經邀請連結加入。檢查 CSP 無違規、Jitsi API 經 SRI 載入、iframe 建立。
// 用法：node meeting.cjs <base-url> <admin-password>（由 run-e2e.sh 呼叫）
const PW_MOD = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = require(PW_MOD);
const [,, BASE, ADMIN_PW] = process.argv;
let pass = 0, fail = 0;
const ok = (name, cond, info = '') => { if (cond) { pass++; console.log('  ok   ' + name); } else { fail++; console.log('  FAIL ' + name + (info ? ' — ' + info : '')); } };

function watch(page, bag) {
  page.on('console', m => { const t = m.text(); if (/Content Security Policy|Refused to|integrity/i.test(t)) bag.push(t); });
  page.on('pageerror', e => bag.push('pageerror: ' + e.message));
}

(async () => {
  const browser = await chromium.launch();
  const LOCALES = (process.env.E2E_LOCALES || 'zh-TW,en-US').split(',');
  const NO_I18N = !!process.env.E2E_NO_I18N;   // 1.7.0 之前的版本沒有 i18n，略過 html lang 檢查
  for (const locale of LOCALES) {
    console.log(`== ${locale}`);
    const host = await browser.newContext({ locale, extraHTTPHeaders: { 'Accept-Language': locale } });
    const hp = await host.newPage(); const hv = []; watch(hp, hv);
    await hp.goto(BASE + '/jt-login');
    await hp.fill('#email', 'jtvc-admin'); await hp.fill('#password', ADMIN_PW);
    await Promise.all([hp.waitForURL(/\/dashboard/), hp.click('button[type=submit]')]);
    ok('登入後到儀表板', hp.url().includes('/dashboard'));
    const room = 'e2e-' + locale.toLowerCase() + '-' + Date.now().toString(36);
    await hp.fill('input[name=room]', room);
    await Promise.all([hp.waitForURL(/created=/), hp.click('button[name=mode][value=create]')]);
    ok('建立會議室', hp.url().includes('created=' + room));
    // 立即主持（POST 表單）
    await Promise.all([hp.waitForURL(/\/meeting/), hp.click(`.created-panel form[action="/start"] button`)]);
    await hp.waitForFunction(() => document.querySelector('#jaas-container iframe'), null, { timeout: 15000 }).catch(() => {});
    const src = await hp.evaluate(() => { const f = document.querySelector('#jaas-container iframe'); return f ? f.src : ''; });
    ok('會議頁建立 Jitsi iframe', /^https:\/\//.test(src), src.slice(0, 60));
    ok('JitsiMeetExternalAPI 已載入（內附 + SRI）', await hp.evaluate(() => typeof JitsiMeetExternalAPI === 'function'));
    const csp = (await (await hp.request.get(BASE + '/meeting')).headers())['content-security-policy'] || '';
    ok('會議頁有 CSP', csp.includes('frame-src https://'), csp.slice(0, 40));
    // 來賓
    const guest = await browser.newContext({ locale, extraHTTPHeaders: { 'Accept-Language': locale } });
    const gp = await guest.newPage(); const gv = []; watch(gp, gv);
    await gp.goto(BASE + '/room/' + room);
    await gp.waitForSelector('#guest_name', { timeout: 10000 }).catch(() => {});
    ok('來賓看到輸入名稱表單（主持人在線）', await gp.$('#guest_name') !== null);
    if (await gp.$('#guest_name')) {
      await gp.fill('#guest_name', 'E2E Guest');
      await gp.click('button[type=submit]');
      await gp.waitForFunction(() => document.querySelector('#jaas-container iframe'), null, { timeout: 15000 }).catch(() => {});
      ok('來賓會議頁建立 iframe', !!(await gp.$('#jaas-container iframe')));
    }
    const html = await hp.evaluate(() => document.documentElement.lang);
    if (!NO_I18N) ok('html lang 正確', locale === 'en-US' ? html === 'en' : html === 'zh-Hant-TW', html);
    ok('主持人頁無 CSP / SRI 錯誤', hv.length === 0, hv.slice(0, 2).join(' | '));
    ok('來賓頁無 CSP / SRI 錯誤', gv.length === 0, gv.slice(0, 2).join(' | '));
    // 刪除會議室（自訂確認框）→ 登出（POST 按鈕）
    await hp.goto(BASE + '/dashboard');
    const delForm = hp.locator(`form[action="/room-delete"]:has(input[value="${room}"])`);
    if (await delForm.count()) {
      await delForm.locator('button').click();
      await hp.waitForSelector('#confirmModal.open', { timeout: 5000 });
      await Promise.all([hp.waitForURL(/\/dashboard/), hp.click('#confirmOk')]);
      ok('UI 刪除會議室', !(await hp.content()).includes(`value="${room}"`));
    } else ok('UI 刪除會議室', false, '找不到刪除按鈕');
    await Promise.all([hp.waitForURL(/jt-login/), hp.click('form[action="/logout"] button')]);
    ok('登出按鈕（POST）', hp.url().includes('/jt-login'));
    await host.close(); await guest.close();
  }
  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
