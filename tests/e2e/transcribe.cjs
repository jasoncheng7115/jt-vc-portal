// 逐字稿與摘要的瀏覽器測試（T23–T30），由 tests/run-transcribe.sh 在資料都準備好之後呼叫。
// 用法：node transcribe.cjs <portal-url> <admin-password> <host-password>
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const [,, BASE, APW, HPW] = process.argv;
let pass = 0, fail = 0;
const ok = (n, c, i = '') => { c ? (pass++, console.log('  ok   ' + n)) : (fail++, console.log('  FAIL ' + n + (i ? ' — ' + String(i).slice(0, 180) : ''))); };

async function login(b, user, pw) {
  const ctx = await b.newContext({ locale: 'zh-TW', extraHTTPHeaders: { 'Accept-Language': 'zh-TW' } });
  const p = await ctx.newPage(); p._csp = [];
  p.on('console', m => { if (/Content Security Policy|Refused to/i.test(m.text())) p._csp.push(m.text()); });
  await p.goto(BASE + '/jt-login');
  await p.fill('#email', user); await p.fill('#password', pw);
  await Promise.all([p.waitForURL(/\/dashboard/), p.click('form[action="/verify"] button[type=submit]')]);
  return p;
}
const status = async (p, path) => (await p.request.get(BASE + path, { maxRedirects: 0 })).status();

(async () => {
  const b = await chromium.launch();

  // ---- 沒有逐字稿權限的主持人 ----
  const pn = await login(b, 'hnone', HPW);
  await pn.goto(BASE + '/dashboard');
  ok('T23 none：建立會議室表單沒有逐字稿開關', !(await pn.$('input[name=transcribe]')));
  await pn.goto(BASE + '/recordings');
  ok('T23 none：錄影記錄沒有「逐字稿」欄', !(await pn.content()).includes('tx-cell'));
  ok('T23 none：直接開檢視頁 → 404', (await status(pn, '/transcript?id=rec-ok01')) === 404);
  ok('T23 none：直接下載 → 404', (await status(pn, '/transcript-download?id=rec-ok01&f=txt')) === 404);

  // ---- manual 主持人 ----
  const pm = await login(b, 'hman', HPW);
  await pm.goto(BASE + '/dashboard');
  ok('T24 manual：建立表單有逐字稿開關、預設不勾（帳號是手動）', !!(await pm.$('input[name=transcribe]')) && !(await pm.isChecked('input[name=transcribe]')));
  await pm.goto(BASE + '/recordings');
  const html = await pm.content();
  ok('T24 manual：錄影記錄有「逐字稿」欄', html.includes('tx-cell'));
  ok('T24 manual：已完成的場次有「逐字稿與摘要」連結', !!(await pm.$('a[href="/transcript?id=rec-ok01"]')));
  ok('T24 manual：已取消的場次有「重新產生」', !!(await pm.$('form[action="/transcript-action"]:has(input[value="rec-slow01"]) input[name=action][value=regenerate]')));
  ok('T24 manual：看不到別人主持的場次', !html.includes('rec-other01'));
  const csrf = await pm.evaluate(() => (document.querySelector('input[name=_csrf]') || {}).value);
  const r1 = await pm.request.post(BASE + '/transcript-action', { form: { _csrf: csrf, action: 'request', id: 'rec-other01' }, maxRedirects: 0 });
  ok('T24 manual：對別人的場次送「產生」→ 404', r1.status() === 404, r1.status());
  const r2 = await pm.request.post(BASE + '/transcript-action', { form: { _csrf: csrf, action: 'delete', id: 'rec-ok01' }, maxRedirects: 0 });
  ok('T24 manual：刪除逐字稿（僅管理員）→ 404', r2.status() === 404, r2.status());
  const r3 = await pm.request.post(BASE + '/transcript-action', { form: { action: 'request', id: 'rec-ok01' }, maxRedirects: 0 });
  ok('T24 沒帶 CSRF → 403', r3.status() === 403, r3.status());

  // ---- 檢視頁（manual 主持人自己的場次）----
  await pm.goto(BASE + '/transcript?id=rec-ok01');
  ok('T25 檢視頁：有摘要區塊與重點摘要文字', (await pm.textContent('#txSumText')).trim().length > 0);
  const nItems = await pm.$$eval('.tx-item', els => els.length);
  ok('T25 檢視頁：列出摘要項目（每條附引用時間）', nItems > 0 && (await pm.$$eval('.tx-cite', els => els.length)) >= nItems, 'items=' + nItems);
  const nRows = await pm.$$eval('#txSegs .mt-row', els => els.length);
  ok('T25 檢視頁：逐字稿段落', nRows > 0);
  await pm.click('.tx-cite');
  ok('T25 點引用 → 標亮對應的逐字稿段落', (await pm.$$eval('#txSegs .mt-row.tx-mark', els => els.length)) > 0);
  ok('T25 檢視頁沒有 CSP 錯誤', pm._csp.length === 0, pm._csp.join(' | '));

  // 改名（全部）→ 重新整理仍在、同一代號的每一段都改
  const firstSpk = await pm.$eval('#txSegs .mt-row .mt-s', e => e.textContent);
  await pm.click('#txSegs .mt-row .mt-s');
  await pm.fill('#txSegs .mt-name-edit', '陳副理');
  await Promise.all([pm.waitForResponse(r => r.url().includes('/transcript-action') && r.request().method() === 'POST'), pm.press('#txSegs .mt-name-edit', 'Enter')]);
  await pm.reload();
  const names = await pm.$$eval('#txSegs .mt-row .mt-s', els => els.map(e => e.textContent));
  ok('T26 改名（全部）存下，重新整理後仍在', names[0] === '陳副理', names[0]);
  ok('T26 同一代號的其他段落也一起改名', !names.includes(firstSpk), firstSpk);
  const txt = await (await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=txt')).text();
  ok('T27 下載純文字：行首 [mm:ss]、套用改名', /^\uFEFF?\[\d\d:\d\d\] /.test(txt) && txt.includes('陳副理：'), txt.slice(0, 60));
  const srt = await (await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=srt')).text();
  ok('T27 下載 SRT', /\d\d:\d\d:\d\d,\d{3} --> \d\d:\d\d:\d\d,\d{3}/.test(srt));
  const md = await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=md');
  ok('T27 下載摘要 Markdown', md.status() === 200 && (md.headers()['content-type'] || '').includes('markdown'));
  const js = JSON.parse(await (await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=json')).text());
  ok('T27 下載 JSON 帶 speaker_name', js.segments && js.segments[0].speaker_name === '陳副理');

  // ---- 發言者對應建議（v1.13.0）----
  await pm.goto(BASE + '/transcript?id=rec-ok01');
  ok('T36 有 Jitsi 時間軸：顯示發言者對應建議（Amy 100%）', await pm.isVisible('#txSuggest') && (await pm.textContent('#txSuggestRows')).includes('Amy 100%'));
  ok('T36 改名輸入框可從參與者名單挑選（datalist）', (await pm.$$eval('#txPeople option', o => o.map(x => x.value))).join(',') === 'Amy,Ben');
  await Promise.all([pm.waitForResponse(r => r.url().includes('/transcript-action') && r.request().method() === 'POST'), pm.click('#txApplyAll')]);
  await pm.reload();
  const after = await pm.$$eval('#txSegs .mt-row .mt-s', els => [...new Set(els.map(e => e.textContent))]);
  ok('T36 「全部套用」後每位發言者都改成建議的人，重新整理仍在', after.length === 1 && after[0] === 'Amy', JSON.stringify(after));
  ok('T36 套用後建議標示為已套用', (await pm.$$eval('.tx-suggest-chip.on', e => e.length)) > 0);

  // ---- 別的主持人（manual）不可看 hman 的場次 ----
  const po = await login(b, 'hother', HPW);
  ok('T28 別的主持人開 hman 的逐字稿 → 404', (await status(po, '/transcript?id=rec-ok01')) === 404);

  // ---- 管理員 ----
  const pa = await login(b, 'jtvc-admin', APW);
  ok('T29 管理員可看任何場次', (await status(pa, '/transcript?id=rec-ok01')) === 200);
  await pa.goto(BASE + '/transcript?id=rec-auto01');
  ok('T37 沒有時間軸的場次：不顯示建議、顯示可手動挑選的提示', !(await pa.isVisible('#txSuggest')) && await pa.isVisible('#txNoTalk'));
  await pa.goto(BASE + '/settings#transcribe');
  ok('T29 系統設定有逐字稿卡片（目錄可切換）', await pa.isVisible('#card-transcribe') && !(await pa.isVisible('#card-sso')));
  ok('T29 設定頁不回填 JTLW 金鑰', (await pa.inputValue('#card-transcribe input[name=jtlw_key]')) === '');
  await pa.goto(BASE + '/accounts');
  ok('T29 帳號管理有逐字稿權限選項', (await pa.$$('select[name=transcribe]')).length > 0);
  ok('T29 帳號清單標示逐字稿權限', (await pa.content()).includes('badge-muted'));
  await pa.goto(BASE + '/audit-log');
  const audit = await pa.content();
  ok('T30 稽核記錄有逐字稿相關事件', audit.includes('逐字稿'));
  ok('T30 稽核記錄不含逐字稿內容', !audit.includes('陳副理：'));

  await b.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.log('  FAIL 例外 — ' + e.message.split('\n')[0]); console.log(`\n${pass} passed, ${fail + 1} failed`); process.exit(1); });
