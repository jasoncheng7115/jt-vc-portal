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
  const LOCALES = (process.env.E2E_LOCALES || 'zh-TW,en-US,ja-JP').split(',');
  const NO_I18N = !!process.env.E2E_NO_I18N;   // 1.7.0 之前的版本沒有 i18n，略過 html lang 檢查
  for (const locale of LOCALES) {
    console.log(`== ${locale}`);
    const host = await browser.newContext({ locale, extraHTTPHeaders: { 'Accept-Language': locale } });
    const hp = await host.newPage(); const hv = []; watch(hp, hv);
    await hp.goto(BASE + '/jt-login');
    ok('語言選單預設收合、只顯示目前語言', !(await hp.isVisible('#topbarLangMenu')) && (await hp.$$('#topbarLangBtn')).length === 1);
    await hp.click('#topbarLangBtn');
    ok('語言選單點了才展開，列出所有語言並標示目前語言', (await hp.$$eval('#topbarLangMenu a[hreflang]', as => as.length)) === 3 && await hp.isVisible('#topbarLangMenu a.active'));
    await hp.keyboard.press('Escape');
    ok('語言選單按 Esc 收合', !(await hp.isVisible('#topbarLangMenu')));
    await hp.fill('#email', 'jtvc-admin'); await hp.fill('#password', ADMIN_PW);
    await Promise.all([hp.waitForURL(/\/dashboard/), hp.click('button[type=submit]')]);
    ok('登入後到儀表板', hp.url().includes('/dashboard'));
    const room = 'e2e-' + locale.toLowerCase() + '-' + Date.now().toString(36);
    await hp.fill('input[name=room]', room);
    if (await hp.$('#txChk') && await hp.isChecked('#txChk')) await hp.uncheck('#txChk');   // 逐字稿預設勾選（v1.16.3）；這裡不測逐字稿
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
    if (!NO_I18N) ok('html lang 正確', html === ({ 'en-US': 'en', 'ja-JP': 'ja' }[locale] || 'zh-Hant-TW'), html);
    ok('主持人頁無 CSP / SRI 錯誤', hv.length === 0, hv.slice(0, 2).join(' | '));
    ok('來賓頁無 CSP / SRI 錯誤', gv.length === 0, gv.slice(0, 2).join(' | '));
    if (locale === LOCALES[0]) {
      // v1.16.0：Jitsi 會議畫面正常回應時，20 秒後不可跳出「無法連線到會議」
      await hp.waitForTimeout(22000);
      ok('T59 Jitsi 正常：20 秒後沒有跳出連線失敗遮罩', await hp.$eval('#svcOverlay', e => e.hidden));
      // 擋掉 Jitsi 會議畫面（模擬 Jitsi 故障、但伺服器端檢查還沒發現）→ 20 秒後顯示友善遮罩
      const bad = await host.newPage();
      await bad.route(/^https:\/\/8x8\.vc\/(?!.*external_api\.js).*/, r => r.abort());
      await bad.goto(BASE + '/meeting');
      await bad.waitForSelector('#svcOverlay:not([hidden])', { timeout: 30000 }).catch(() => {});
      ok('T59 會議畫面沒有回應 → 顯示「無法連線到會議」與重試按鈕', await bad.isVisible('#svcOverlay') && await bad.isVisible('#svcOverlayRetry'));
      await bad.close();
    }
    // 刪除會議室（自訂確認框）→ 登出（POST 按鈕）
    await hp.goto(BASE + '/dashboard');
    const delForm = hp.locator(`form[action="/room-delete"]:has(input[value="${room}"])`);
    if (await delForm.count()) {
      await delForm.locator('button').click();
      await hp.waitForSelector('#confirmModal.open', { timeout: 5000 });
      await Promise.all([hp.waitForURL(/\/dashboard/), hp.click('#confirmOk')]);
      ok('UI 刪除會議室', !(await hp.content()).includes(`value="${room}"`));
    } else ok('UI 刪除會議室', false, '找不到刪除按鈕');
    // 系統設定頁目錄：每張可見卡片都有對應目錄項目；點選只顯示該卡片（#hash）、重新整理保持、「全部」還原
    await hp.goto(BASE + '/settings');
    const tocInfo = await hp.evaluate(() => {
      const cards = [...document.querySelectorAll('.settings-card')].filter(c => c.style.display !== 'none').map(c => c.id.replace(/^card-/, ''));
      const items = [...document.querySelectorAll('.settings-toc-item')].filter(a => a.offsetParent !== null).map(a => a.getAttribute('data-toc'));
      return { cards, items };
    });
    ok('設定目錄：可見卡片與目錄項目一致', tocInfo.cards.length >= 10 && JSON.stringify(tocInfo.items) === JSON.stringify(['all', ...tocInfo.cards]), JSON.stringify(tocInfo));
    const visibleCards = () => hp.evaluate(() => [...document.querySelectorAll('.settings-card')].filter(c => c.offsetParent !== null).map(c => c.id.replace(/^card-/, '')));
    await hp.click('.settings-toc-item[data-toc="sso"]');
    { const v = await visibleCards(); ok('設定目錄：點選後只顯示該卡片並更新網址', JSON.stringify(v) === '["sso"]' && hp.url().endsWith('#sso'), hp.url() + ' ' + JSON.stringify(v)); }
    await hp.goto(BASE + '/dashboard'); await hp.goto(BASE + '/settings#sso'); await hp.waitForTimeout(500);
    ok('設定目錄：直接開 /settings#sso 只顯示該卡片', JSON.stringify(await visibleCards()) === '["sso"]');
    ok('設定目錄：以 #id 開啟時目錄仍在畫面內', await hp.evaluate(() => document.querySelector('.settings-toc').getBoundingClientRect().top >= 0));
    await hp.evaluate(() => { location.hash = '#smtp'; }); await hp.waitForTimeout(300);
    ok('設定目錄：只改網址 # 也會切換卡片', JSON.stringify(await visibleCards()) === '["smtp"]');
    await hp.click('.settings-toc-item[data-toc="all"]');
    ok('設定目錄：「全部」還原所有卡片', (await visibleCards()).length === tocInfo.cards.length);
    await hp.goto(BASE + '/recordings');
    { const hrefs = await hp.$$eval('main a', as => as.filter(a => !a.closest('.nav-row')).map(a => a.getAttribute('href')).filter(h => h && h.startsWith('/settings')));
      ok('錄影頁的「系統設定 → 錄製設定」連結指向 #recording', hrefs.length > 0 && hrefs.every(h => h === '/settings#recording'), JSON.stringify(hrefs)); }
    ok('設定頁無 CSP 錯誤', hv.length === 0, hv.slice(0, 2).join(' | '));
    await Promise.all([hp.waitForURL(/jt-login/), hp.click('form[action="/logout"] button')]);
    ok('登出按鈕（POST）', hp.url().includes('/jt-login'));
    await host.close(); await guest.close();
  }
  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
