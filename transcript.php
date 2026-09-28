<?php
/**
 * 錄影逐字稿與會議摘要檢視頁（GET /transcript?id=<錄影 id>）。畫面與互動照 jtdt 的 meeting_transcribe / meeting_summary：
 *   摘要（重點、決議與待辦、事件與影響、風險、未決問題；每一條附引用時間，點了跳播並標亮逐字稿）、議題時間軸、誰講了多少、
 *   播放器（圓形播放鍵＋波形＋游標提示當下發言者；大檔不畫波形）、逐字稿（發言者配色、點名字改名：整個代號或只改這段）。
 * 權限：Transcripts::canView（管理員或有逐字稿權限的主持人，且可存取該錄影）。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/transcripts.php';
require_once __DIR__ . '/lib/layout.php';

$me = Auth::requireLogin();
$ip = Auth::clientIp();
$id = (string)($_GET['id'] ?? '');
if (!Transcripts::validId($id) || !Settings::transcribeReady()) Auth::notFound();
$rec = null;
foreach (Recordings::listRecordings() as $r) { if ((string)($r['id'] ?? '') === $id) { $rec = $r; break; } }
if (!$rec || !Transcripts::canView($rec, $me)) Auth::notFound();
$e = Transcripts::get($id);
$tr = Transcripts::result($id);
if (!$e || !$tr) Auth::notFound();
$sum = Transcripts::summary($id);
$is_admin = ($me['role'] ?? '') === 'admin';
$can_req = Transcripts::canRequest($rec, $me);
$sess = Transcripts::sessionOf($rec);
Audit::log('transcript_view', t('檢視逐字稿與摘要：會議室「{room}」錄影 {id}', ['room' => $rec['room'] ?? '', 'id' => $id]));

$msg = $_SESSION['rec_msg'] ?? ''; $err = $_SESSION['rec_err'] ?? '';
unset($_SESSION['rec_msg'], $_SESSION['rec_err']);

$recStartMs = ((int)($rec['mtime'] ?? 0) - (int)($rec['duration'] ?? 0)) * 1000;
$sug = Transcripts::suggestSpeakers($tr['segments'], (array)($sess['talk'] ?? []), $recStartMs);
$pnames = Transcripts::participantNames($sess);
$data = [
  'id' => $id,
  'segments' => $tr['segments'],
  'speaker_names' => (object)$tr['speaker_names'],
  'speaker_overrides' => (object)$tr['speaker_overrides'],
  'size' => (int)($rec['size'] ?? 0),
  'suggest' => $sug['speakers'],
  'participants' => $pnames,
  'has_talk' => !empty($sess['talk']),
  'summary' => $sum ? [
    'text' => (string)($sum['summary']['text'] ?? ''),
    'grounded' => (bool)($sum['summary']['grounded'] ?? true),
    'unsupported' => array_values((array)($sum['summary']['unsupported'] ?? [])),
    'items' => (array)($sum['items'] ?? []),
    'chapters' => array_values((array)($sum['chapters'] ?? [])),
    'speakers' => array_values((array)($sum['speakers'] ?? [])),
    'model' => (string)($sum['model'] ?? ''),
  ] : null,
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$action_form = function (string $action, string $label, string $icon, string $cls = 'btn btn-secondary btn-sm', string $confirm = '') use ($id) {
  return '<form method="POST" action="/transcript-action" style="display:inline;"' . ($confirm !== '' ? ' data-confirm="' . htmlspecialchars($confirm) . '"' : '') . '>'
    . Auth::csrfField() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . htmlspecialchars($id) . '">'
    . '<input type="hidden" name="back" value="' . htmlspecialchars('/transcript?id=' . rawurlencode($id)) . '"><button class="' . $cls . '">' . icon($icon, 14) . htmlspecialchars($label) . '</button></form>';
};

render_head(t('逐字稿與摘要') . ' · ' . ($rec['room'] ?? ''));
render_topbar($me, $ip);
?>
<main class="container tx-page">
  <?= admin_nav('recordings') ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('file-text', 18) ?><?= th('逐字稿與摘要') ?></h1>
    <div class="tx-meta">
      <span><?= icon('video', 14) ?><strong><?= htmlspecialchars((string)($rec['room'] ?? '')) ?></strong></span>
      <span class="mono"><?= icon('clock', 14) ?><?= htmlspecialchars(date('Y-m-d H:i', (int)($rec['mtime'] ?? 0) - (int)($rec['duration'] ?? 0))) ?></span>
      <?php if ($sess && ($sess['owner_name'] ?? '') !== ''): ?><span><?= icon('user', 14) ?><?= htmlspecialchars((string)$sess['owner_name']) ?></span><?php endif; ?>
      <span class="muted" id="txCount"></span>
    </div>
    <div class="tx-actions">
      <a class="btn btn-secondary btn-sm" href="/transcript-download?id=<?= rawurlencode($id) ?>&amp;f=txt"><?= icon('download', 14) ?><?= th('逐字稿（純文字）') ?></a>
      <a class="btn btn-secondary btn-sm" href="/transcript-download?id=<?= rawurlencode($id) ?>&amp;f=srt"><?= icon('download', 14) ?><?= th('字幕（SRT）') ?></a>
      <a class="btn btn-secondary btn-sm" href="/transcript-download?id=<?= rawurlencode($id) ?>&amp;f=json"><?= icon('download', 14) ?>JSON</a>
      <?php if ($sum): ?><a class="btn btn-secondary btn-sm" href="/transcript-download?id=<?= rawurlencode($id) ?>&amp;f=md"><?= icon('download', 14) ?><?= th('摘要（Markdown）') ?></a><?php endif; ?>
      <button type="button" class="btn btn-secondary btn-sm" id="txCopy"><?= icon('copy', 14) ?><?= th('複製純文字') ?></button>
      <span class="tx-actions-sep"></span>
      <?php if ($can_req && ($e['status'] ?? '') === 'partial' && empty($e['acked'])): ?><?= $action_form('retry_summary', t('重做摘要'), 'refresh') ?><?php endif; ?>
      <?php if ($can_req): ?><?= $action_form('regenerate', t('重新產生'), 'refresh', 'btn btn-ghost btn-sm', t('重新產生會刪除目前的逐字稿、摘要與發言者改名，確定？')) ?><?php endif; ?>
      <?php if ($is_admin): ?><?= $action_form('delete', t('刪除逐字稿'), 'trash', 'btn btn-ghost btn-sm', t('確定刪除這筆錄影的逐字稿與摘要？錄影本身不受影響。')) ?><?php endif; ?>
    </div>
    <?php if (!empty($tr['uncorrected'])): ?>
      <p class="info-box tx-hint"><?= th('這份逐字稿沒有經過校正 —— 你看到的是原始辨識結果，標點與錯字都還沒修。通常是校正那一步失敗了；重新產生一次多半就會有。') ?></p>
    <?php endif; ?>
    <?php if (!empty($tr['tail_hint'])): $tg = (int)round(($tr['tail_gap_ms'] ?? 0) / 1000); ?>
      <p class="info-box tx-hint"><?= th('錄影最後 {m} 分 {s} 秒沒有任何文字。如果到最後都有人在講話，這份逐字稿可能不完整，請重新產生；如果是錄影忘了停、或結尾沒有人說話，就不用理會。', ['m' => intdiv($tg, 60), 's' => $tg % 60]) ?></p>
    <?php endif; ?>
    <?php if (!empty($tr['diarize_skipped'])): ?>
      <p class="info-box tx-hint"><?= th('這次的辨識沒有做發言者分離，所以逐字稿沒有標出是誰說的。') ?></p>
    <?php endif; ?>
    <?php if (($e['summary_status'] ?? '') === 'failed'): ?>
      <p class="info-box tx-hint"><?= htmlspecialchars(Jtlw::describe((string)($e['summary_error'] ?? 'llm_failed'))) ?></p>
    <?php elseif (($e['summary_status'] ?? '') === 'unsupported'): ?>
      <p class="info-box tx-hint"><?= th('會議摘要只支援中文與英文會議；這場只有逐字稿。') ?></p>
    <?php endif; ?>
  </div>

  <?php if ($sum): ?>
  <div class="card tx-summary">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('sparkles', 18) ?><?= th('會議摘要') ?></h1>
    <p class="muted tx-sum-note"><?= th('由語音服務（JTLW）以語言模型從逐字稿整理；每一條都附逐字稿的時間點與發言者，點時間可以跳到錄影該處。發言者代號（S1、S2…）不是人名，可以在下方逐字稿點名字改名。') ?></p>
    <section class="tx-sec"><h3><?= icon('sparkles', 16) ?><?= th('重點摘要') ?></h3><div class="tx-sum-text" id="txSumText"></div><div class="tx-warn" id="txSumWarn" hidden></div></section>
    <section class="tx-sec"><h3><?= icon('check', 16) ?><?= th('決議與待辦') ?></h3><div class="tx-cards" id="txDecide"></div></section>
    <section class="tx-sec"><h3><?= icon('info', 16) ?><?= th('事件與影響') ?></h3><div class="tx-cards" id="txImpacts"></div></section>
    <section class="tx-sec"><h3><?= icon('warning', 16) ?><?= th('風險') ?></h3><div class="tx-cards" id="txRisks"></div></section>
    <section class="tx-sec"><h3><?= icon('info', 16) ?><?= th('未決問題') ?></h3><div class="tx-cards" id="txQuestions"></div></section>
    <section class="tx-sec" id="txChapWrap" hidden><h3><?= icon('list', 16) ?><?= th('議題時間軸') ?></h3><div class="tx-chap" id="txChap"></div></section>
    <section class="tx-sec" id="txSpkWrap" hidden><h3><?= icon('user', 16) ?><?= th('誰講了多少') ?></h3>
      <div class="tx-tablewrap"><table class="tx-spk" id="txSpk"></table></div></section>
    <?php if ($sum['model'] ?? ''): ?><p class="muted tx-model"><?= th('摘要模型：{m}', ['m' => (string)$sum['model']]) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('file-text', 18) ?><?= th('逐字稿') ?></h1>
    <div class="mt-player" id="txPlayer">
      <button class="mt-play" id="txPlay" type="button" aria-label="<?= th('播放 / 暫停') ?>">
        <span id="txIcPlay"><?= icon('play', 20) ?></span><span id="txIcPause" hidden><?= icon('pause', 20) ?></span>
      </button>
      <div class="mt-wave-box">
        <canvas id="txWave" class="mt-wave" height="72"></canvas>
        <div class="mt-wave-tip" id="txWaveTip" hidden><b id="txTipTime"></b><span class="mt-tip-dot" id="txTipDot" hidden></span><span id="txTipWho"></span></div>
      </div>
      <span class="mt-clock"><b id="txNow">00:00</b><i>/</i><span id="txTot">00:00</span></span>
      <button class="btn btn-ghost btn-sm" id="txShowVideo" type="button"><?= icon('video', 14) ?><span><?= th('顯示畫面') ?></span></button>
    </div>
    <video id="txMedia" class="tx-video" preload="metadata" playsinline hidden src="/recordings-file?id=<?= rawurlencode($id) ?>"></video>
    <p class="muted tx-err" id="txMediaErr" hidden><?= th('錄影檔已經不在了，或是這個格式瀏覽器放不出來。') ?></p>
    <div class="tx-suggest" id="txSuggest" hidden>
      <div class="tx-suggest-head"><strong><?= icon('user', 14) ?><?= th('發言者對應建議') ?></strong>
        <button type="button" class="btn btn-secondary btn-sm" id="txApplyAll"><?= icon('check', 14) ?><?= th('全部套用最可能的人') ?></button></div>
      <p class="muted tx-suggest-note"><?= th('依會議中 Jitsi 偵測的「目前發言者」時間軸，與逐字稿的發言者代號比對重疊時間。只是建議：人多、同時說話或聲音相近時可能不準，請確認後再套用。') ?></p>
      <div id="txSuggestRows"></div>
    </div>
    <p class="muted tx-suggest-note" id="txNoTalk" hidden><?= th('這場會議沒有記錄到發言時間軸（v1.13.0 之前的會議，或主持人的會議頁沒有全程開著），無法自動建議；改名時可以從參與者名單挑選。') ?></p>
    <datalist id="txPeople"><?php foreach ($pnames as $pn): ?><option value="<?= htmlspecialchars($pn) ?>"></option><?php endforeach; ?></datalist>
    <p class="muted mt-tip"><?= th('點發言者的名字可以改名 —— 預設同一位全部一起改；點時間可以跳到那裡播放。') ?></p>
    <div id="txSegs" class="mt-segs"></div>
  </div>
</main>
<script type="application/json" id="txData"><?= json_encode($data, $jsonFlags) ?></script>
<script <?= nonce_attr() ?>>
(function () {
  var el = function (id) { return document.getElementById(id); };
  var data = JSON.parse(el('txData').textContent);
  var CSRF = <?= json_encode(Auth::csrfToken()) ?>;
  var T = <?= json_encode([
    'segs' => t('共 {0} 段，{1} 位發言者'), 'none' => t('沒有找到'), 'owner' => t('負責：{0}'), 'due' => t('期限：{0}'),
    'jump' => t('跳到這裡播放'), 'rename' => t('點一下改名字'), 'all' => t('全部 {0}'), 'saveFail' => t('名字存不起來'),
    'copied' => t('已複製'), 'copyFail' => t('複製失敗，請手動選取'), 'check' => t('摘要裡有些數字或詞在逐字稿裡找不到，請核對：{0}'),
    'apply' => t('套用'), 'applied' => t('已套用'), 'cover' => t('逐字稿中有 {0}% 的發言時間對得到'), 'spk' => t('發言者'), 'turns' => t('發言次數'), 'chars' => t('字數'), 'time' => t('發言時間'), 'decision' => t('決議'), 'action' => t('待辦'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  function fmt(s) { var a = arguments; return s.replace(/\{(\d)\}/g, function (_, i) { return a[+i + 1]; }); }
  function mmss(ms) { if (ms == null) return ''; var t = Math.round(ms / 1000); return String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0'); }

  // ---- speakers (as jtdt: ids come from JTLW, names are ours; rename all or just this segment) ----
  var order = [];
  function sIdx(id) { if (!id) return -1; var k = order.indexOf(id); if (k < 0) { order.push(id); k = order.length - 1; } return k % 8; }
  function nameOf(id) { return (data.speaker_names || {})[id] || id || ''; }
  function segName(s) { var ov = (data.speaker_overrides || {})[String(s.seq)]; return ov || nameOf(s.speaker); }
  async function saveSpeakers() {
    var r = await fetch('/transcript-action', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ action: 'speakers', id: data.id, map: data.speaker_names || {}, overrides: data.speaker_overrides || {} }) });
    if (!r.ok) alert(T.saveFail);
  }
  function editSpeaker(cell, s) {
    if (cell.querySelector('input')) return;
    var before = cell.textContent; cell.textContent = '';
    var inp = document.createElement('input'); inp.className = 'mt-name-edit'; inp.value = before; inp.maxLength = 40; inp.setAttribute('list', 'txPeople');
    var scope = document.createElement('label'); scope.className = 'mt-scope';
    var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = true;
    scope.appendChild(cb); scope.appendChild(document.createTextNode(fmt(T.all, s.speaker || '')));
    cell.appendChild(inp); if (s.speaker) cell.appendChild(scope);
    inp.focus(); inp.select();
    var committed = false;
    async function commit() {
      if (committed) return; committed = true;
      var name = inp.value.trim();
      data.speaker_names = data.speaker_names || {}; data.speaker_overrides = data.speaker_overrides || {};
      if (!name) { if (cb.checked && s.speaker) delete data.speaker_names[s.speaker]; delete data.speaker_overrides[String(s.seq)]; }
      else if (cb.checked && s.speaker) { data.speaker_names[s.speaker] = name; delete data.speaker_overrides[String(s.seq)]; }
      else { data.speaker_overrides[String(s.seq)] = name; }
      render(); renderSummary();
      await saveSpeakers();
    }
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); commit(); }
      if (e.key === 'Escape') { e.preventDefault(); committed = true; render(); }
    });
    inp.addEventListener('blur', function () { setTimeout(function () { if (!cell.contains(document.activeElement)) commit(); }, 150); });
  }

  // ---- transcript ----
  var segs = data.segments || [];
  var bySeq = {}; segs.forEach(function (s) { bySeq[s.seq] = s; });
  function render() {
    var names = {}; segs.forEach(function (s) { if (s.speaker) names[segName(s)] = 1; });
    el('txCount').textContent = fmt(T.segs, String(segs.length), String(Object.keys(names).length));
    var box = el('txSegs'); box.textContent = '';
    segs.forEach(function (s) {
      var ci = sIdx(s.speaker);
      var row = document.createElement('div');
      row.className = 'mt-row' + (ci >= 0 ? ' mt-b' + ci : '');
      row.dataset.seq = String(s.seq);
      if (s.start_ms != null) row.dataset.start = String(s.start_ms);
      if (s.end_ms != null) row.dataset.end = String(s.end_ms);
      var tc = document.createElement('span'); tc.className = 'mt-t'; tc.textContent = mmss(s.start_ms);
      if (s.start_ms != null) { tc.title = T.jump; tc.addEventListener('click', function () { seekTo(s.start_ms / 1000); }); }
      var sc = document.createElement('span'); sc.className = 'mt-s' + (ci >= 0 ? ' mt-c' + ci : ''); sc.textContent = segName(s);
      sc.title = T.rename; sc.addEventListener('click', function () { editSpeaker(sc, s); });
      var body = document.createElement('span'); body.textContent = s.text;
      row.appendChild(tc); row.appendChild(sc); row.appendChild(body);
      box.appendChild(row);
    });
    nowRow = null;
  }
  function asPlainText() {
    return segs.map(function (s) { var n = segName(s); return (s.start_ms == null ? '' : '[' + mmss(s.start_ms) + '] ') + (n ? n + '\uFF1A' : '') + s.text; }).join('\n');
  }
  el('txCopy').addEventListener('click', async function () {
    try { await navigator.clipboard.writeText(asPlainText()); this.classList.add('ok'); setTimeout(function () { el('txCopy').classList.remove('ok'); }, 1200); }
    catch (e) { alert(T.copyFail); }
  });

  // ---- summary ----
  function citeChips(item) {
    var wrap = document.createElement('span'); wrap.className = 'tx-cites';
    (item.citations || []).forEach(function (c) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'tx-cite';
      var ci = sIdx(c.speaker_id);
      b.textContent = mmss(c.start_ms) + (c.speaker_id ? ' · ' + nameOf(c.speaker_id) : '');
      if (ci >= 0) b.classList.add('mt-c' + ci);
      b.title = T.jump;
      b.addEventListener('click', function () { markSeqs(c.source_seqs || []); seekTo((c.start_ms || 0) / 1000); });
      wrap.appendChild(b);
    });
    return wrap;
  }
  function itemList(box, items, labelFn) {
    box.textContent = '';
    if (!items || !items.length) { var p = document.createElement('p'); p.className = 'muted tx-none'; p.textContent = T.none; box.appendChild(p); return; }
    items.forEach(function (it) {
      var c = document.createElement('div'); c.className = 'tx-item';
      var head = document.createElement('div'); head.className = 'tx-item-text';
      if (labelFn) { var tag = document.createElement('span'); tag.className = 'tx-tag ' + labelFn(it)[0]; tag.textContent = labelFn(it)[1]; head.appendChild(tag); }
      head.appendChild(document.createTextNode(it.text || ''));
      c.appendChild(head);
      var meta = [];
      if (it.owner) meta.push(fmt(T.owner, nameOf(it.owner)));
      if (it.due_text) meta.push(fmt(T.due, it.due_text));
      if (meta.length) { var m = document.createElement('div'); m.className = 'tx-item-meta'; m.textContent = meta.join('   '); c.appendChild(m); }
      c.appendChild(citeChips(it));
      box.appendChild(c);
    });
  }
  function renderSummary() {
    var S = data.summary; if (!S) return;
    el('txSumText').textContent = S.text || T.none;
    if (!S.grounded && (S.unsupported || []).length) { el('txSumWarn').textContent = fmt(T.check, S.unsupported.join(', ')); el('txSumWarn').hidden = false; }
    var it = S.items || {};
    // decisions and actions shown together (JTLW: the model may file the same thing under either)
    var dec = (it.decisions || []).map(function (x) { x._k = 'd'; return x; }).concat((it.actions || []).map(function (x) { x._k = 'a'; return x; }));
    itemList(el('txDecide'), dec, function (x) { return x._k === 'd' ? ['tx-tag-d', T.decision] : ['tx-tag-a', T.action]; });
    itemList(el('txImpacts'), it.impacts); itemList(el('txRisks'), it.risks); itemList(el('txQuestions'), it.questions);
    var ch = S.chapters || [];
    if (ch.length) {
      el('txChapWrap').hidden = false; var box = el('txChap'); box.textContent = '';
      ch.forEach(function (c) {
        var row = document.createElement('button'); row.type = 'button'; row.className = 'tx-chap-row'; row.title = T.jump;
        var t = document.createElement('span'); t.className = 'mt-t'; t.textContent = mmss(c.start_ms) + '–' + mmss(c.end_ms);
        var name = document.createElement('span'); name.className = 'tx-chap-title'; name.textContent = c.title || '';
        var bar = document.createElement('span'); bar.className = 'tx-bar'; var fill = document.createElement('i'); fill.style.width = Math.max(2, Math.min(100, c.percentage || 0)) + '%'; bar.appendChild(fill);
        var pct = document.createElement('span'); pct.className = 'tx-pct'; pct.textContent = (c.percentage || 0).toFixed(1) + '%';
        row.appendChild(t); row.appendChild(name); row.appendChild(bar); row.appendChild(pct);
        row.addEventListener('click', function () { seekTo((c.start_ms || 0) / 1000); });
        box.appendChild(row);
      });
    }
    var sp = S.speakers || [];
    if (sp.length) {
      el('txSpkWrap').hidden = false; var tb = el('txSpk'); tb.textContent = '';
      var hr = document.createElement('tr');
      [T.spk, T.time, T.turns, T.chars].forEach(function (h) { var th = document.createElement('th'); th.textContent = h; hr.appendChild(th); });
      tb.appendChild(hr);
      sp.forEach(function (s) {
        var tr = document.createElement('tr'); var ci = sIdx(s.speaker_id);
        var a = document.createElement('td'); a.className = 'mt-s' + (ci >= 0 ? ' mt-c' + ci : ''); a.textContent = nameOf(s.speaker_id);
        var b = document.createElement('td'); var bar = document.createElement('span'); bar.className = 'tx-bar'; var fill = document.createElement('i');
        fill.style.width = Math.max(2, Math.min(100, s.percentage || 0)) + '%'; if (ci >= 0) fill.className = 'mt-d' + ci; bar.appendChild(fill);
        b.appendChild(bar); b.appendChild(document.createTextNode(' ' + mmss(s.speaking_ms) + ' (' + (s.percentage || 0).toFixed(1) + '%)'));
        var c = document.createElement('td'); c.className = 'mono'; c.textContent = String(s.turn_count || 0);
        var d = document.createElement('td'); d.className = 'mono'; d.textContent = String(s.chars || 0) + ' (' + (s.char_pct || 0).toFixed(1) + '%)';
        tr.appendChild(a); tr.appendChild(b); tr.appendChild(c); tr.appendChild(d); tb.appendChild(tr);
      });
    }
  }
  var marked = [];
  function markSeqs(seqs) {
    marked.forEach(function (r) { r.classList.remove('tx-mark'); }); marked = [];
    seqs.forEach(function (q) { var r = el('txSegs').querySelector('.mt-row[data-seq="' + Number(q) + '"]'); if (r) { r.classList.add('tx-mark'); marked.push(r); } });
    if (marked[0]) { var box = el('txSegs'); box.scrollTop = Math.max(0, marked[0].getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop - box.clientHeight / 3); }
  }

  // ---- player (as jtdt: no waveform decode for big files; falls back to a clickable timeline) ----
  var media = el('txMedia'), wave = el('txWave'), peaks = null, nowRow = null, hoverX = null;
  function seekTo(sec) { media.currentTime = Math.max(0, sec); if (media.paused) media.play().catch(function () {}); }
  function drawWave() {
    var w = wave.clientWidth || 600, h = 72, dpr = window.devicePixelRatio || 1;
    wave.width = Math.round(w * dpr); wave.height = Math.round(h * dpr);
    var g = wave.getContext('2d'); g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, w, h);
    var dur = media.duration || 0, played = dur ? media.currentTime / dur : 0;
    if (peaks) {
      for (var x = 0; x < w; x++) { var v = peaks[Math.floor(x / w * peaks.length)] || 0, bar = Math.max(2, v * (h - 4)); g.fillStyle = (x / w <= played) ? '#2563eb' : '#cbd5e1'; g.fillRect(x, (h - bar) / 2, 1, bar); }
    } else { g.fillStyle = '#e2e8f0'; g.fillRect(0, h / 2 - 2, w, 4); g.fillStyle = '#2563eb'; g.fillRect(0, h / 2 - 2, w * played, 4); }
    if (dur) { g.fillStyle = '#1e40af'; g.fillRect(Math.min(w - 1, played * w), 0, 1, h); }
    if (hoverX != null && dur) { g.fillStyle = 'rgba(15,23,42,.45)'; g.fillRect(Math.max(0, Math.min(w - 1, hoverX)), 0, 1, h); }
  }
  var timed = segs.filter(function (s) { return s.start_ms != null; });
  function segmentAt(ms) {   // gaps between segments: do not guess a speaker (jtdt meeting_wave_tip)
    for (var i = 0; i < timed.length; i++) { var s = timed[i], end = s.end_ms != null ? s.end_ms : s.start_ms; if (ms >= s.start_ms && ms <= end) return s; if (s.start_ms > ms) break; }
    return null;
  }
  function showTip() {
    var tip = el('txWaveTip'), dur = media.duration || 0, w = wave.clientWidth || 0;
    if (hoverX == null || !dur || !w) { tip.hidden = true; return; }
    var hx = Math.max(0, Math.min(w - 1, hoverX)), ms = hx / w * dur * 1000;
    el('txTipTime').textContent = mmss(ms);
    var s = segmentAt(ms), dot = el('txTipDot');
    if (s && s.speaker) { var ci = sIdx(s.speaker); dot.className = 'mt-tip-dot' + (ci >= 0 ? ' mt-d' + ci : ''); dot.hidden = false; el('txTipWho').textContent = segName(s); }
    else { dot.hidden = true; el('txTipWho').textContent = ''; }
    tip.hidden = false; var tw = tip.offsetWidth; tip.style.left = ((hx + tw + 4 > w) ? Math.max(0, hx - tw - 4) : hx + 4) + 'px';
  }
  async function buildPeaks() {
    if (!data.size || data.size > 60 * 1024 * 1024) return null;   // big files: decoding the whole PCM would crash the tab
    try {
      var buf = await (await fetch(media.getAttribute('src'))).arrayBuffer();
      var Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) return null;
      var pcm = await new Ctx().decodeAudioData(buf), ch = pcm.getChannelData(0), N = 1200, out = new Array(N), step = ch.length / N, top = 0;
      for (var i = 0; i < N; i++) { var a = Math.floor(i * step), b = Math.min(ch.length, Math.floor((i + 1) * step)), m = 0; for (var k = a; k < b; k++) { var v = Math.abs(ch[k]); if (v > m) m = v; } out[i] = m; if (m > top) top = m; }
      if (top > 0) for (var j = 0; j < N; j++) out[j] = Math.sqrt(out[j] / top);   // normalise + sqrt (meeting recordings peak low)
      return out;
    } catch (e) { return null; }
  }
  function highlight() {
    var ms = (media.currentTime || 0) * 1000, rows = el('txSegs').children, hit = null;
    for (var i = 0; i < rows.length; i++) {
      var a = parseInt(rows[i].dataset.start || '-1', 10), b = parseInt(rows[i].dataset.end || '-1', 10);
      if (a >= 0 && ms >= a && (b < 0 || ms < b)) { hit = rows[i]; break; }
      if (a >= 0 && a > ms) break;
      if (a >= 0) hit = rows[i];
    }
    if (hit === nowRow) return;
    if (nowRow) nowRow.classList.remove('mt-now');
    nowRow = hit; if (!hit || media.paused) { if (hit) hit.classList.add('mt-now'); return; }
    hit.classList.add('mt-now');
    var box = el('txSegs'), top = hit.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop, bh = box.clientHeight;
    if (top < box.scrollTop || top + hit.offsetHeight > box.scrollTop + bh) box.scrollTop = Math.max(0, top - bh / 3);
  }
  function clock() { el('txNow').textContent = mmss((media.currentTime || 0) * 1000); el('txTot').textContent = mmss((media.duration || 0) * 1000); }
  function syncIcon() { var p = !media.paused && !media.ended; el('txIcPlay').hidden = p; el('txIcPause').hidden = !p; }
  media.addEventListener('error', function () { el('txPlayer').hidden = true; el('txMediaErr').hidden = false; });
  media.addEventListener('play', syncIcon); media.addEventListener('pause', syncIcon); media.addEventListener('ended', syncIcon);
  media.addEventListener('timeupdate', function () { clock(); drawWave(); highlight(); });
  media.addEventListener('loadedmetadata', function () { clock(); drawWave(); });
  el('txPlay').addEventListener('click', function () { if (media.paused) media.play().catch(function () {}); else media.pause(); });
  el('txShowVideo').addEventListener('click', function () { media.hidden = !media.hidden; this.classList.toggle('active', !media.hidden); });
  wave.addEventListener('mousemove', function (e) { hoverX = e.clientX - wave.getBoundingClientRect().left; drawWave(); showTip(); });
  wave.addEventListener('mouseleave', function () { hoverX = null; drawWave(); showTip(); });
  wave.addEventListener('click', function (e) { var dur = media.duration || 0; if (!dur) return; var r = wave.getBoundingClientRect(); seekTo((e.clientX - r.left) / r.width * dur); });
  window.addEventListener('resize', drawWave);

  // ---- speaker suggestions (v1.13.0): Jitsi dominant-speaker timeline vs transcript speaker ids ----
  function applyName(sp, name) {
    data.speaker_names = data.speaker_names || {};
    data.speaker_names[sp] = name;
    render(); renderSummary(); renderSuggest(); saveSpeakers();
  }
  function renderSuggest() {
    var sg = data.suggest || {}, keys = Object.keys(sg), box = el('txSuggestRows');
    el('txSuggest').hidden = !keys.length;
    el('txNoTalk').hidden = keys.length > 0 || data.has_talk || !segs.some(function (s) { return s.speaker; });
    box.textContent = '';
    keys.forEach(function (sp) {
      var row = document.createElement('div'); row.className = 'tx-suggest-row'; row.dataset.sp = sp;
      var ci = sIdx(sp);
      var code = document.createElement('span'); code.className = 'mt-s' + (ci >= 0 ? ' mt-c' + ci : ''); code.textContent = sp;
      var cur = document.createElement('span'); cur.className = 'tx-suggest-cur'; cur.textContent = '→ ' + nameOf(sp);
      var chips = document.createElement('span'); chips.className = 'tx-suggest-chips';
      sg[sp].names.forEach(function (c) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'tx-cite tx-suggest-chip';
        var on = (data.speaker_names || {})[sp] === c.name;
        if (on) b.classList.add('on');
        b.textContent = c.name + ' ' + c.pct + '%' + (on ? ' ✓' : '');
        b.title = on ? T.applied : T.apply;
        b.addEventListener('click', function () { applyName(sp, c.name); });
        chips.appendChild(b);
      });
      var cov = document.createElement('span'); cov.className = 'muted tx-suggest-cov'; cov.textContent = fmt(T.cover, String(sg[sp].coverage));
      row.appendChild(code); row.appendChild(cur); row.appendChild(chips); row.appendChild(cov);
      box.appendChild(row);
    });
  }
  el('txApplyAll').addEventListener('click', function () {
    var sg = data.suggest || {};
    data.speaker_names = data.speaker_names || {};
    Object.keys(sg).forEach(function (sp) { if (sg[sp].names[0]) data.speaker_names[sp] = sg[sp].names[0].name; });
    render(); renderSummary(); renderSuggest(); saveSpeakers();
  });

  render(); renderSummary(); renderSuggest(); drawWave();
  buildPeaks().then(function (p) { peaks = p; drawWave(); });
})();
</script>
<?php render_foot(); ?>
