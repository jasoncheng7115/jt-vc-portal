// 逐字稿與摘要的瀏覽器測試（T23–T30、T36–T37、T40–T45、T48–T50），由 tests/run-transcribe.sh 在資料都準備好之後呼叫。
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
/** 從 ZIP（本系統產生的 docx / odt）取出一個檔案的內容。 */
function unzipEntry(buf, name) {
  const zlib = require('zlib'); let p = 0;
  while (buf.readUInt32LE(p) === 0x04034b50) {
    const method = buf.readUInt16LE(p + 8), csize = buf.readUInt32LE(p + 18), nlen = buf.readUInt16LE(p + 26), xlen = buf.readUInt16LE(p + 28);
    const n = buf.slice(p + 30, p + 30 + nlen).toString(), raw = buf.slice(p + 30 + nlen + xlen, p + 30 + nlen + xlen + csize);
    if (n === name) return (method === 8 ? zlib.inflateRawSync(raw) : raw).toString('utf8');
    p += 30 + nlen + xlen + csize;
  }
  return '';
}

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
  ok('T24 manual：建立表單有逐字稿開關、預設勾選（T71）', !!(await pm.$('input[name=transcribe]')) && await pm.isChecked('input[name=transcribe]'));
  await pm.goto(BASE + '/recordings');
  const html = await pm.content();
  ok('T24 manual：錄影記錄有「逐字稿」欄', html.includes('tx-cell'));
  ok('T24 manual：已完成的場次有「逐字稿與摘要」連結', !!(await pm.$('a[href="/transcript?id=rec-ok01"]')));
  ok('T24 manual：已取消的場次有「重新產生」', !!(await pm.$('form[action="/transcript-action"]:has(input[value="rec-slow01"]) input[name=action][value=regenerate]')));
  ok('T24 manual：看不到別人主持的場次', !html.includes('rec-other01'));
  const qRow = pm.locator('tr:has(input[value="rec-q01"])');
  ok('T43 處理中：取消是標籤右端的 ✕（有提示文字）', (await qRow.locator('.tx-chip-busy .tx-chip-x').count()) === 1 && ((await qRow.locator('.tx-chip-x').getAttribute('title')) || '').length > 0);
  const failRow = pm.locator('tr:has(input[value="rec-fail01"])');
  ok('T40 失敗狀態：與按鈕同高的「產生失敗」標籤，原因在滑過提示', (await failRow.locator('.tx-chip-fail').count()) === 1 && ((await failRow.locator('.tx-cell-row').getAttribute('title')) || '').trim().length > 0);
  const mids = await failRow.evaluate(r => [...r.children].map(td => { const el = td.querySelector('.tx-chip, .btn, .badge, strong, svg') || td; const b = el.getBoundingClientRect(); return b.top + b.height / 2; }));
  ok('T40 同一列各欄垂直置中（偏差 ≤ 2px）', Math.max(...mids) - Math.min(...mids) <= 2, mids.map(Math.round).join(','));
  await failRow.locator('td').nth(1).click();
  ok('T40 展開該列會完整顯示失敗原因', await pm.locator('tr.row-detail:not([hidden]) .tx-detail-why').first().isVisible());
  const chipH = await failRow.locator('.tx-chip-fail').evaluate(e => e.getBoundingClientRect().height);
  const btnH = await failRow.locator('.tx-cell-row .btn').first().evaluate(e => e.getBoundingClientRect().height);
  ok('T40 標籤高度與按鈕一致', Math.abs(chipH - btnH) <= 2, chipH + ' vs ' + btnH);
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
  // T68：hidden 屬性一定要真的隱藏（CSS display 曾蓋掉 hidden，波形左上出現空白提示框）
  const tipVis = () => pm.$eval('#txWaveTip', e => { const r = e.getBoundingClientRect(); return getComputedStyle(e).display !== 'none' && r.width > 0; });
  ok('T68 波形時間提示：還沒移到波形上時不顯示', (await tipVis()) === false);
  await pm.waitForFunction(() => (document.getElementById('txMedia').duration || 0) > 0, null, { timeout: 15000 }).catch(() => {});   // 時間提示要等音檔長度
  await pm.$eval('#txWave', e => e.scrollIntoView({ block: 'center' })); await pm.waitForTimeout(300);
  const wb = await pm.$eval('#txWave', e => { const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
  await pm.mouse.move(wb.x + wb.w * 0.6, wb.y + wb.h / 2);
  await pm.waitForTimeout(300);
  if (process.env.MEDIA_OK === '1') ok('T68 波形時間提示：游標在波形上時顯示時間', (await tipVis()) === true && /\d\d:\d\d/.test(await pm.textContent('#txTipTime')));
  else console.log('  skip T68 游標在波形上顯示時間（沒有 ffmpeg，無法產生可播放的測試音檔）');
  await pm.mouse.move(wb.x + wb.w * 0.6, wb.y - 80);
  await pm.waitForTimeout(300);
  ok('T68 波形時間提示：游標離開後再隱藏', (await tipVis()) === false);
  ok('T68 全站規則：[hidden] 元素一律不顯示', (await pm.$$eval('[hidden]', els => els.filter(e => getComputedStyle(e).display !== 'none').length)) === 0);

  // 改名（全部）→ 重新整理仍在、同一代號的每一段都改
  const firstSpk = await pm.$eval('#txSegs .mt-row .mt-s', e => e.textContent);
  await pm.click('#txSegs .mt-row .mt-s');
  await pm.fill('#txSegs .mt-name-edit', '陳副理');
  await Promise.all([pm.waitForResponse(r => r.url().includes('/transcript-action') && r.request().method() === 'POST'), pm.press('#txSegs .mt-name-edit', 'Enter')]);
  await pm.reload();
  const names = await pm.$$eval('#txSegs .mt-row .mt-s', els => els.map(e => e.textContent));
  ok('T26 改名（全部）存下，重新整理後仍在', names[0] === '陳副理', names[0]);
  ok('T26 同一代號的其他段落也一起改名', !names.includes(firstSpk), firstSpk);
  // T73：送件帶 hints.diarize_engine=auto，取回時記下實際用的方法（mock：auto → nemotron）；退回舊方法時頁面要說明
  const sh2 = require('child_process').execSync;
  const dz = JSON.parse(sh2(`docker exec -u www-data ${process.env.PN} php -r "echo json_encode(json_decode(file_get_contents('/var/jaas-data/transcripts/rec-ok01/transcript.json'), true)['diarization'] ?? null);"`).toString() || 'null');
  ok('T73 送件要求 auto，取回記下實際方法（nemotron）', !!dz && dz.requested === 'auto' && dz.engine === 'nemotron' && dz.fallback === false, JSON.stringify(dz));
  ok('T73 沒有退回舊方法時不顯示提醒', !(await pm.isVisible('#txDiarizeFallback')));
  sh2(`docker exec -u www-data ${process.env.PN} php -r "\\$f='/var/jaas-data/transcripts/rec-ok01/transcript.json'; \\$d=json_decode(file_get_contents(\\$f), true); \\$d['diarization']['fallback']=true; file_put_contents(\\$f, json_encode(\\$d, JSON_UNESCAPED_UNICODE));"`);
  await pm.reload(); await pm.waitForTimeout(800);
  ok('T73 auto 退回舊方法（超過 8 位發言者）→ 頁面提醒發言者分組可能較不準', await pm.isVisible('#txDiarizeFallback'));
  sh2(`docker exec -u www-data ${process.env.PN} php -r "\\$f='/var/jaas-data/transcripts/rec-ok01/transcript.json'; \\$d=json_decode(file_get_contents(\\$f), true); \\$d['diarization']['fallback']=false; file_put_contents(\\$f, json_encode(\\$d, JSON_UNESCAPED_UNICODE));"`);
  const txt = await (await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=txt')).text();
  ok('T27 下載純文字：行首 [mm:ss]、套用改名', /^\uFEFF?\[\d\d:\d\d\] /.test(txt) && txt.includes('陳副理：'), txt.slice(0, 60));
  const srt = await (await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=srt')).text();
  ok('T27 下載 SRT', /\d\d:\d\d:\d\d,\d{3} --> \d\d:\d\d:\d\d,\d{3}/.test(srt));
  const md = await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=md');
  ok('T27 下載摘要 Markdown', md.status() === 200 && (md.headers()['content-type'] || '').includes('markdown'));
  const js = JSON.parse(await (await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=json')).text());
  ok('T27 下載 JSON 帶 speaker_name', js.segments && js.segments[0].speaker_name === '陳副理');
  // ---- 會議記錄匯出 PDF / Word / ODT（T44–T45）----
  for (const [f, type, magic] of [['pdf', 'application/pdf', '%PDF-'], ['docx', 'wordprocessingml.document', 'PK\x03\x04'], ['odt', 'opendocument.text', 'PK\x03\x04'], ['html', 'text/html', '<!DOCTYPE html>']]) {
    const r = await pm.request.get(BASE + '/transcript-download?id=rec-ok01&f=' + f);
    const buf = await r.body();
    ok(`T44 下載會議記錄 ${f.toUpperCase()}：200、類型、檔名、檔頭`, r.status() === 200 && (r.headers()['content-type'] || '').includes(type)
      && (r.headers()['content-disposition'] || '').includes('-meeting.' + f) && buf.slice(0, magic.length).toString('latin1') === magic, r.status() + ' ' + r.headers()['content-type']);
    if (f === 'html') {
      const t = buf.toString('utf8');
      ok('T45 HTML 單一檔案：沒有 script、內容套用改名、含摘要與逐字稿', !/<script/i.test(t) && t.includes('陳副理') && t.includes('重點摘要') && t.includes('發言統計'));
    } else if (f !== 'pdf') {
      const doc = unzipEntry(buf, f === 'docx' ? 'word/document.xml' : 'content.xml');
      ok(`T45 ${f.toUpperCase()} 內容套用改名、含摘要與逐字稿`, doc.includes('陳副理') && doc.includes('重點摘要') && doc.includes('逐字稿'));
    } else {
      ok('T45 PDF 內嵌字型子集、可複製（ToUnicode）', buf.includes('/ToUnicode') && buf.includes('+NotoSansTC-Regular') && buf.length < 1024 * 1024, buf.length);
    }
  }
  ok('T44 沒有逐字稿權限 → 匯出 404', (await status(pn, '/transcript-download?id=rec-ok01&f=pdf')) === 404);
  await pm.goto(BASE + '/transcript?id=rec-ok01');
  ok('T48 檢視頁：PDF / DOCX / ODT / HTML 四個下載按鈕', (await pm.$$eval('a.tx-dl', e => e.map(x => x.textContent.trim()).join(','))) === 'PDF,DOCX,ODT,HTML');
  ok('T48 「其他格式」選單預設收起', !(await pm.isVisible('#txMoreMenu')));
  await pm.click('#txMoreBtn');
  ok('T48 點「其他格式」才展開（純文字 / SRT / JSON / Markdown）', await pm.isVisible('#txMoreMenu') && (await pm.$$eval('#txMoreMenu a', e => e.length)) === 4);
  await pm.click('.tx-meta');
  ok('T48 點外面收起', !(await pm.isVisible('#txMoreMenu')));
  ok('T49 /session-check：登入中 → login=true', (await (await pm.request.get(BASE + '/session-check')).json()).login === true);
  // 登入逾時後按播放：要說「登入已逾時」並給重新登入連結，不能說檔案不在
  const pt = await login(b, 'hman', HPW);
  await pt.goto(BASE + '/transcript?id=rec-ok01');
  await pt.context().clearCookies();
  ok('T49 /session-check：未登入 → login=false', (await (await pt.request.get(BASE + '/session-check')).json()).login === false);
  await pt.evaluate(() => { const m = document.getElementById('txMedia'); m.src = m.src + '&r=1'; m.load(); });
  await pt.waitForFunction(() => !document.getElementById('txMediaErr').hidden, null, { timeout: 10000 }).catch(() => {});
  const why = (await pt.textContent('#txMediaErr')) || '';
  ok('T49 登入逾時時播放：顯示「登入已逾時」與重新登入連結', why.includes('登入已逾時') && (await pt.getAttribute('#txMediaErr a', 'href')) === '/jt-login', why);
  ok('T49 登入逾時時播放器不隱藏（重新登入後可繼續）', await pt.isVisible('#txPlayer'));

  // ---- 會議主要語言（v1.16.0）----
  const { execSync: sh } = require('child_process');
  const phpq = code => sh(`docker exec -u www-data ${process.env.PN} php -r "require '/var/www/html/config.php'; require_once '/var/www/html/lib/transcripts.php'; ${code}"`).toString();
  await pm.goto(BASE + '/dashboard');
  ok('T71 建立表單：逐字稿預設勾選，必填的「會議主要語言」直接顯示', await pm.isChecked('#txChk') && await pm.isVisible('#txLangBox') && await pm.$eval('#txLang', e => e.required));
  await pm.fill('#room', 't71-default'); await pm.click('button[name=mode][value=create]'); await pm.waitForTimeout(500);
  ok('T71 沒選主要語言就送出 → 瀏覽器擋下、沒有建立', pm.url().includes('/dashboard') && !pm.url().includes('created=') && phpq("var_export(Rooms::get('t71-default'));").trim() === 'NULL');
  await pm.uncheck('#txChk');
  ok('T54 建立表單：沒勾逐字稿時不顯示語言選單', !(await pm.isVisible('#txLangBox')) && !(await pm.$eval('#txLang', e => e.required)));
  await pm.check('#txChk');
  ok('T54 勾選逐字稿 → 出現必填的「會議主要語言」', await pm.isVisible('#txLangBox') && await pm.$eval('#txLang', e => e.required));
  const opts = await pm.$$eval('#txLang option', o => o.map(x => x.value));
  ok('T54 選項：中文 / 英文 / 日文 / 韓文 / 台語為主，預設未選', opts.join(',') === ',zh-Hant,en,ja,ko,nan-Hant' && (await pm.inputValue('#txLang')) === '', opts.join(','));
  ok('T54 選項文字不用帶政治意涵的稱呼', (await pm.$$eval('#txLang option', o => o.map(x => x.textContent).join('|'))) === '請選擇|中文為主|英文為主|日文為主|韓文為主|台語（閩南語）為主');
  await pm.uncheck('#txChk');
  ok('T54 取消勾選 → 語言選單收起、不再必填', !(await pm.isVisible('#txLangBox')) && !(await pm.$eval('#txLang', e => e.required)));
  const csrfM = await pm.$eval('input[name=_csrf]', e => e.value);
  await pm.request.post(BASE + '/start', { form: { _csrf: csrfM, room: 'lang-none', mode: 'create', transcribe_field: '1', transcribe: '1' }, maxRedirects: 0 });
  await pm.goto(BASE + '/dashboard');
  ok('T55 勾了逐字稿卻沒選語言 → 伺服器端擋下並提示', (await pm.content()).includes('請選擇會議主要語言') && phpq("var_export(Rooms::get('lang-none'));").trim() === 'NULL');
  await pm.request.post(BASE + '/start', { form: { _csrf: csrfM, room: 'lang-nan', mode: 'create', transcribe_field: '1', transcribe: '1', tx_lang: 'nan-Hant' }, maxRedirects: 0 });
  ok('T55 選了台語 → 會議室記下主要語言', phpq("echo Rooms::get('lang-nan')['tx_lang'] ?? 'none';").trim() === 'nan-Hant');
  await pm.request.post(BASE + '/start', { form: { _csrf: csrfM, room: 'lang-bogus', mode: 'create', transcribe_field: '1', transcribe: '1', tx_lang: 'klingon' }, maxRedirects: 0 });
  ok('T55 不在清單內的語言 → 擋下', phpq("var_export(Rooms::get('lang-bogus'));").trim() === 'NULL');
  await pm.goto(BASE + '/recordings');
  ok('T55 錄影記錄有「主要語言」欄', (await pm.$$eval('main thead th', e => e.map(x => x.textContent.trim()))).some(x => x.startsWith('主要語言')));
  const okRow = pm.locator('tr.row-main').filter({ has: pm.locator('a[href="/transcript?id=rec-ok01"]') });
  ok('T55 已送件的錄影顯示送件時的語言', ((await okRow.locator('.tx-lang-cell').textContent()) || '').trim() === '中文為主');
  const langRow = pm.locator('tr.row-main').filter({ has: pm.locator('input[name=id][value="rec-lang01"]') });
  ok('T56 會議沒選語言的錄影：「產生逐字稿」先問主要語言', (await langRow.locator('.tx-pick-btn').count()) === 1 && ((await langRow.locator('.tx-lang-cell').textContent()) || '').trim() === '—');
  await langRow.locator('.tx-pick-btn').click();
  const menu = langRow.locator('.tx-pick-menu');
  ok('T56 語言選單展開（5 種語言）', await menu.isVisible() && (await menu.locator('button.tx-lang-item').count()) === 5);
  await Promise.all([pm.waitForURL(/\/recordings/), menu.locator('button.tx-lang-item', { hasText: '台語' }).click()]);
  ok('T56 選台語 → 排入並顯示語言', phpq("echo Transcripts::get('rec-lang01')['language'] ?? 'none';").trim() === 'nan-Hant'
    && ((await pm.locator('tr.row-main').filter({ has: pm.locator('input[name=id][value="rec-lang01"]') }).locator('.tx-lang-cell').textContent()) || '').trim() === '台語（閩南語）為主');

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
  await pa.goto(BASE + '/recordings');
  ok('T43 已完成＝綠底「查看逐字稿與摘要」、未產生＝虛線「產生逐字稿」，外觀不同', (await pa.$$eval('a.tx-view', e => e.length)) > 0 && (await pa.$$eval('button.tx-gen', e => e.length)) > 0
    && await pa.$eval('a.tx-view', e => getComputedStyle(e).backgroundColor) !== await pa.$eval('button.tx-gen', e => getComputedStyle(e).backgroundColor)
    && await pa.$eval('button.tx-gen', e => getComputedStyle(e).borderStyle) === 'dashed');
  const live = pa.locator('tr.row-main:has-text("room-man-live"), tr.row-main').filter({ has: pa.locator('.rec-live-actions') });
  ok('T41 錄製中：播放、下載、刪除三個按鈕都反灰', (await live.count()) === 1 && (await live.locator('.rec-live-actions button:disabled').count()) === 3);
  ok('T41 錄製中：串流 / 下載請求被擋（409）', (await status(pa, '/recordings-file?id=rec-live01')) === 409 && (await status(pa, '/recordings-file?id=rec-live01&dl=1')) === 409);
  const csrfA = await pa.evaluate(() => (document.querySelector('input[name=_csrf]') || {}).value);
  await pa.request.post(BASE + '/recordings-action', { form: { _csrf: csrfA, action: 'delete', id: 'rec-live01' }, maxRedirects: 0 });
  await pa.goto(BASE + '/recordings');
  ok('T41 錄製中：刪除請求被擋，錄影仍在並顯示原因', (await pa.locator('.rec-live-actions').count()) === 1 && (await pa.content()).includes('錄製中不可刪除'));
  // ---- 系統狀態（v1.16.0）：Jibri 健康由錄影服務回報每台錄製器 ----
  const hj = await (await pa.request.get(BASE + '/health?force=1')).json();
  ok('T60 /health：Jibri 回報 2 台錄製器、皆健康（1 台錄影中）', hj.jibri && hj.jibri.level === 'ok' && hj.jibri.recorders === 2 && hj.jibri.healthy === 2 && hj.jibri.busy === 1, JSON.stringify(hj.jibri));
  await pa.goto(BASE + '/dashboard'); await pa.waitForFunction(() => document.querySelector('#sysHealth .sh-item[data-k="jibri"]').className.includes('sh-'), null, { timeout: 15000 }).catch(() => {});
  ok('T60 儀表板系統狀態列顯示 Jibri 正常', ((await pa.getAttribute('#sysHealth .sh-item[data-k="jibri"]', 'class')) || '').includes('sh-ok'));
  // ---- 會議統計頁（v1.16.0）：圖表改版、時間軸預設收起 ----
  const uerr = []; pa.on('pageerror', e => uerr.push(e.message));
  await pa.goto(BASE + '/usage'); await pa.waitForTimeout(1200);
  ok('T58 會議統計：時間軸預設收起、點標題可展開', !(await pa.$eval('#ganttCard', e => e.open)) && (await (async () => { await pa.click('#ganttCard > summary'); return pa.$eval('#ganttCard', e => e.open); })()));
  ok('T58 近 30 天活動為堆疊長條圖、有畫出來、頁面無 JS 錯誤', await pa.evaluate(() => { const c = window.Chart && Chart.getChart('dailyChart'); return !!c && c.config.type === 'bar' && c.options.scales.y.stacked === true && c.chartArea.width > 0; }) && uerr.length === 0, uerr.join(' | '));
  await pa.goto(BASE + '/settings#transcribe');
  ok('T29 系統設定有逐字稿卡片（目錄可切換）', await pa.isVisible('#card-transcribe') && !(await pa.isVisible('#card-sso')));
  ok('T29 設定頁不回填 JTLW 金鑰', (await pa.inputValue('#card-transcribe input[name=jtlw_key]')) === '');
  ok('T73 系統設定有「發言者辨識方式」，預設自動', (await pa.inputValue('#card-transcribe select[name=diarize_engine]')) === 'auto');
  // 背景排程（cron）沒在跑要提醒：舊版升級者最常漏掉
  const { execSync } = require('child_process');
  execSync(`docker exec ${process.env.PN} touch -d '-20 min' /var/jaas-data/transcribe-worker.lock`);
  await pa.reload();
  ok('T50 排程超過 10 分鐘沒執行 → 逐字稿卡片顯示警示與排程指令', await pa.isVisible('#txWorkerDown') && (await pa.textContent('#txWorkerDown')).includes('transcribe-worker.php'));
  execSync(`docker exec ${process.env.PN} touch /var/jaas-data/transcribe-worker.lock`);
  await pa.reload();
  ok('T50 排程正常 → 不警示', !(await pa.isVisible('#txWorkerDown')));
  ok('T50 必要元件齊全 → 沒有「缺少元件」警示', !(await pa.$('#reqMissing')));
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
