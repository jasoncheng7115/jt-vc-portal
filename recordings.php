<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/transcripts.php';

$me = Auth::requireLogin();
$ip = Auth::clientIp();
$is_admin = ($me['role'] ?? '') === 'admin';
$tx_on = Settings::transcribeReady() && Transcripts::canUse($me);
$tx_index = $tx_on ? Transcripts::all() : [];

/** 逐字稿欄：狀態 + 可做的操作（權限在 transcript-action 再檢查一次）。 */
function tx_cell(array $r, ?array $e, array $me): string {
  $rid = (string)$r['id'];
  $can = Transcripts::canRequest($r, $me);
  $form = function (string $action, string $label, string $icon, string $cls = 'btn btn-secondary btn-sm', string $confirm = '', string $tip = '') use ($rid) {
    return '<form method="POST" action="/transcript-action" style="display:inline;"' . ($confirm !== '' ? ' data-confirm="' . htmlspecialchars($confirm) . '"' : '') . '>'
      . Auth::csrfField() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . htmlspecialchars($rid) . '">'
      . '<input type="hidden" name="back" value="/recordings"><button class="' . $cls . '" title="' . htmlspecialchars($tip !== '' ? $tip : $label) . '">' . icon($icon, 14) . htmlspecialchars($label) . '</button></form>';
  };
  $st = (string)($e['status'] ?? '');
  if ($st === '') {
    if (($r['status'] ?? '') !== 'ok') return '<span class="muted">—</span>';
    return $can ? $form('request', t('產生逐字稿'), 'sparkles', 'btn btn-sm tx-gen', '', t('把這筆錄影交給語音服務產生逐字稿與會議摘要')) : '<span class="muted">—</span>';
  }
  // 已完成（綠底、打勾）與尚未產生（白底虛線、星形）外觀刻意區分，一眼分得出來
  $view = '<a class="btn btn-sm tx-view" title="' . th('查看逐字稿與會議摘要') . '" href="/transcript?id=' . rawurlencode($rid) . '">' . icon('check', 14) . th('查看逐字稿與摘要') . '</a>';
  // 狀態標籤：與按鈕同高、圖示＋文字；失敗原因以小字顯示在下方（過長截斷，滑過看完整）
  $chip = fn(string $cls, string $ic, string $label) => '<span class="tx-chip ' . $cls . '">' . $ic . '<span>' . htmlspecialchars($label) . '</span></span>';
  // 單行呈現（與同列其他欄垂直對齊）：原因放在標籤的滑過提示；點該列展開時也會完整顯示（tx_reason）
  $row = fn(string $inner, string $why = '') => '<div class="tx-cell-row"' . ($why !== '' ? ' title="' . htmlspecialchars($why) . '"' : '') . '>' . $inner . '</div>';
  switch ($st) {
    case 'done': return $view;
    case 'partial':
      $why = !empty($e['summary_retry_at']) ? t('摘要將自動重試（第 {n} 次）', ['n' => (int)($e['summary_retries'] ?? 0) + 1]) : Jtlw::describe((string)($e['summary_error'] ?? ''));
      return $row($view . $chip('tx-chip-warn', icon('warning', 13), t('摘要失敗')), $why);
    case 'failed':
      return $row($chip('tx-chip-fail', icon('warning', 13), t('產生失敗')) . ($can ? $form('regenerate', t('重新產生'), 'refresh', 'btn btn-secondary btn-sm', '', t('重新上傳錄影並產生逐字稿與摘要')) : ''),
                  Jtlw::describe((string)($e['error_code'] ?? ''), (string)($e['error_reason'] ?? '')));
    case 'cancelled':
      return $row($chip('tx-chip-muted', icon('x', 13), t('已取消')) . ($can ? $form('regenerate', t('重新產生'), 'refresh', 'btn btn-secondary btn-sm', '', t('重新上傳錄影並產生逐字稿與摘要')) : ''));
    default:
      $txt = tx_progress_text($e);
      // 處理中：取消鈕做成標籤右端的小 ✕（可移除標籤的樣式），不另外放一顆按鈕
      $x = ($can && in_array($st, ['pending', 'queued', 'running'], true))
        ? '<form method="POST" action="/transcript-action" class="tx-chip-x-form" data-confirm="' . th('確定取消產生這筆錄影的逐字稿？') . '">' . Auth::csrfField()
          . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="' . htmlspecialchars($rid) . '"><input type="hidden" name="back" value="/recordings">'
          . '<button class="tx-chip-x" title="' . th('取消產生這筆錄影的逐字稿') . '" aria-label="' . th('取消產生這筆錄影的逐字稿') . '">' . icon('x', 12) . '</button></form>'
        : '';
      return $row('<span class="tx-chip tx-chip-busy' . ($x !== '' ? ' has-x' : '') . '"><span class="spinner-dot"></span><span>' . htmlspecialchars($txt) . '</span>' . $x . '</span>',
        ($st === 'pending' && (int)($e['attempts'] ?? 0) > 0) ? Jtlw::describe((string)($e['error_code'] ?? '')) : '');
  }
}

/** 逐字稿失敗 / 摘要失敗 / 重試中的原因（展開列顯示用）；沒有就回空字串。 */
function tx_reason(?array $e): string {
  $st = (string)($e['status'] ?? '');
  if ($st === 'failed') return Jtlw::describe((string)($e['error_code'] ?? ''), (string)($e['error_reason'] ?? ''));
  if ($st === 'partial') return !empty($e['summary_retry_at']) ? t('摘要將自動重試（第 {n} 次）', ['n' => (int)($e['summary_retries'] ?? 0) + 1]) : Jtlw::describe((string)($e['summary_error'] ?? ''));
  if ($st === 'pending' && (int)($e['attempts'] ?? 0) > 0) return Jtlw::describe((string)($e['error_code'] ?? ''));
  return '';
}

/** 進度文字（照 JTLW 說明：queue_position 0＝下一個就是它；GPU 排隊 waiting；asr 用處理到的時間；summary 顯示 detail）。 */
function tx_progress_text(array $e): string {
  $st = (string)($e['status'] ?? ''); $p = (array)($e['progress'] ?? []);
  if ($st === 'pending') return (int)($e['attempts'] ?? 0) > 0 ? t('等待重試') : t('等待送出');
  if ($st === 'uploading') return t('上傳錄影中');
  if ($st === 'cancelling') return t('取消中');
  if ($st === 'queued') {
    $q = $p['queue_position'] ?? null;
    return $q === null ? t('排隊中') : ((int)$q === 0 ? t('排隊中（下一個就是它）') : t('排隊中（前面還有 {n} 件）', ['n' => (int)$q]));
  }
  if (!empty($p['waiting'])) return t('等候 GPU（前面還有 {n} 件）', ['n' => (int)$p['waiting']]);
  $stage = (string)($p['stage'] ?? '');
  if ($stage === 'asr' && !empty($p['total_ms'])) return t('辨識中 {pct}%', ['pct' => (int)floor(100 * (int)$p['processed_ms'] / max(1, (int)$p['total_ms']))]);
  return match ($stage) {
    'fetch', 'normalize' => t('準備錄音中'),
    'asr' => t('辨識中'),
    'diarization' => t('辨識發言者中'),
    'correction' => t('校正逐字稿中'),
    'finalize' => t('整理結果中'),
    'summary' => ($p['detail'] ?? '') !== '' ? t('產生會議摘要中（{detail}）', ['detail' => $p['detail']]) : t('產生會議摘要中'),
    default => t('處理中'),
  };
}

$msg = $_SESSION['rec_msg'] ?? '';
$err = $_SESSION['rec_err'] ?? '';
unset($_SESSION['rec_msg'], $_SESSION['rec_err']);

$configured = Recordings::configured();
$stats = ($configured && $is_admin) ? Recordings::stats() : null;   // 容量統計僅管理者
$list  = $configured ? Recordings::listRecordings() : [];
if ($configured && !$is_admin) {                                    // 主持人只看自己主持的會議錄影
  $list = array_values(array_filter($list, fn($r) => Recordings::canAccess($r, $me)));
}

// 用 portal 的會議記錄（meetings.jsonl）依「房間 + 時間」對應出主持人與參與者。
// 錄影本身（Jibri）不知道主持人/參與者，這些在 portal 端才有。
$sess_by_room = [];
foreach (Rooms::meetingSessions(0, time() + 86400) as $s) {
  $sess_by_room[(string)($s['room'] ?? '')][] = $s;
}
function rec_match_session(array $r, array $byRoom): ?array {
  $room  = (string)($r['room'] ?? '');
  $end   = (int)($r['mtime'] ?? 0);
  $start = $end - (int)($r['duration'] ?? 0);   // 錄影「開始」時間
  $best = null; $bestDelta = PHP_INT_MAX;
  foreach ($byRoom[$room] ?? [] as $s) {
    $ss = (int)($s['start'] ?? 0); $se = (int)($s['end'] ?? 0);
    // 以「錄影開始時間」落在會議時段內(含小寬限)為準，避免同房間的新錄影誤抓到上一場
    if ($start >= $ss - 120 && $start <= $se + 60) {
      $delta = abs($ss - $start);
      if ($delta < $bestDelta) { $bestDelta = $delta; $best = $s; }
    }
  }
  return $best;
}

function rec_bytes($n): string {
  $n = (float)$n; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
  while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
  return ($i === 0 ? (int)$n : number_format($n, 1)) . ' ' . $u[$i];
}
function rec_status_badge(string $s): string {
  $map = [
    'ok'         => ['badge-success', t('正常')],
    'recording'  => ['badge-accent',  t('錄製中')],
    'incomplete' => ['badge-warning', t('未完成')],
    'orphan'     => ['badge-warning', t('殘留')],
  ];
  [$cls, $txt] = $map[$s] ?? ['badge-muted', $s];
  return '<span class="badge ' . $cls . '">' . htmlspecialchars($txt) . '</span>';
}
function rec_dur($s): string {
  $s = (int)$s;
  if ($s <= 0) return '—';
  $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
  return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
}

render_head(t('錄影記錄'));
render_topbar($me, $ip);
?>
<main class="container rec-page">
  <?= admin_nav('recordings') ?>

  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <div class="card">
    <h1><?= icon('video', 18) ?><?= th('錄影記錄') ?></h1>
    <p class="subtitle"><?= $is_admin ? th('調閱自建 Jibri 主機上的會議錄影，可線上播放、下載與刪除。') : th('調閱您主持的會議錄影，可線上播放與下載。') ?></p>

    <?php if (!$configured): ?>
      <?php if ($is_admin): ?>
      <div class="alert alert-info" style="align-items:flex-start;">
        <?= icon('warning') ?>
        <span><?= t('尚未設定 Jibri 錄影服務。請至 {link} 填入服務 URL 與 token。', ['link' => '<a href="/settings#recording">' . th('系統設定 → 錄製設定') . '</a>']) ?></span>
      </div>
      <?php else: ?>
      <div class="alert alert-info"><?= icon('warning') ?><span><?= th('錄影服務尚未啟用。') ?></span></div>
      <?php endif; ?>
    <?php elseif ($is_admin && $stats === null): ?>
      <div class="alert alert-error" style="align-items:flex-start;">
        <?= icon('warning') ?>
        <span><?= th('無法連線到 Jibri 錄影服務，請確認服務狀態、URL 與 token，以及來源 IP 允許清單。') ?></span>
      </div>
    <?php else: ?>
      <?php if ($is_admin && $stats !== null):
        $disk = $stats['disk']; $rec = $stats['recordings'];
        $usedPct = $disk['total'] > 0 ? round($disk['used'] / $disk['total'] * 100) : 0;
        $lvl = $usedPct >= 90 ? 'lvl-crit' : ($usedPct >= 75 ? 'lvl-warn' : 'lvl-ok');
      ?>
      <div style="margin:6px 0 4px;">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:6px;">
          <span style="display:inline-flex;align-items:center;gap:5px;"><?= icon('chart', 14) ?><?= th('錄影主機容量') ?></span>
          <span class="mono"><?= th('{used} / {total}（已用 {pct}%）', ['used' => rec_bytes($disk['used']), 'total' => rec_bytes($disk['total']), 'pct' => $usedPct]) ?></span>
        </div>
        <div class="usage-bar"><div class="usage-fill <?= $lvl ?>" style="width:<?= $usedPct ?>%"></div></div>
        <div class="mono" style="font-size:12px;color:var(--text-muted);margin-top:6px;">
          <?= th('可用 {free}　·　錄影 {count} 筆（{size}）', ['free' => rec_bytes($disk['free']), 'count' => (int)$rec['count'], 'size' => rec_bytes($rec['size'])]) ?>
        </div>
      </div>

      <form method="POST" action="/recordings-action" style="margin:14px 0 4px;" data-confirm="<?= th('依目前保留政策立即清理？此動作會刪除符合條件的錄影。') ?>">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="cleanup">
        <button class="btn btn-secondary btn-sm"><?= icon('trash', 14) ?><?= th('依保留政策立即清理') ?></button>
        <span class="help" style="margin-left:8px;"><?= t('保留政策於 {link} 調整。', ['link' => '<a href="/settings#recording">' . th('系統設定 → 錄製設定') . '</a>']) ?></span>
      </form>
      <?php endif; ?>

      <?php if (empty($list)): ?>
        <div class="empty"><?= th('目前沒有錄影檔。') ?></div>
      <?php else: ?>
      <div class="field" style="max-width:280px;margin:14px 0 8px;">
        <input type="text" id="recSearch" placeholder="<?= th('搜尋會議室 / 主持人 / 參與者…') ?>" autocomplete="off">
      </div>
      <p class="muted" style="font-size:12px;margin:0 0 8px;"><?= th('點任一列（操作鍵除外）可展開該場參與者。') ?></p>
      <table class="table audit-table">
        <thead><tr><th class="caret-col no-sort"></th><th><?= th('會議室') ?></th><th><?= th('主持人') ?></th><th><?= th('時間') ?></th><th><?= th('長度') ?></th><th><?= th('大小') ?></th><th><?= th('狀態') ?></th><?php if ($tx_on): ?><th><?= th('逐字稿') ?></th><?php endif; ?><th style="text-align:right;"><?= th('操作') ?></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r):
          $rid = (string)$r['id']; $st = (string)($r['status'] ?? 'ok');
          $playable = $st !== 'orphan' && !empty($r['file']);
          $match = rec_match_session($r, $sess_by_room);
          $host  = $match ? (string)($match['owner_name'] ?? '') : '';
          $parts = $match && is_array($match['participants'] ?? null) ? $match['participants'] : [];
          // 可搜尋字串：會議室 + 主持人 + 所有參與者名稱（小寫）
          $search_str = (string)($r['room'] ?? '') . ' ' . $host . ' ' . implode(' ', array_map(fn($p) => (string)($p['name'] ?? ''), $parts));
        ?>
          <tr class="rec-row row-main" data-search="<?= htmlspecialchars(mb_strtolower($search_str)) ?>" title="<?= th('點擊展開參與者') ?>">
            <td class="caret-col"><svg class="caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></td>
            <td><strong><?= htmlspecialchars($r['room'] ?: t('（未知）')) ?></strong></td>
            <td><?= $host !== '' ? htmlspecialchars($host) : '<span class="muted">—</span>' ?></td>
            <td class="mono" style="white-space:nowrap;"><?= htmlspecialchars(date('Y-m-d H:i:s', (int)$r['mtime'])) ?></td>
            <td class="mono"><?= htmlspecialchars(rec_dur($r['duration'] ?? 0)) ?></td>
            <td class="mono"><?= rec_bytes($r['size']) ?></td>
            <td><?= rec_status_badge($st) ?></td>
            <?php if ($tx_on): ?><td class="tx-cell" title="" style="white-space:nowrap;"><?= tx_cell($r, $tx_index[$rid] ?? null, $me) ?></td><?php endif; ?>
            <td title="" style="text-align:right;white-space:nowrap;">
              <?php if ($st === 'recording'): /* 錄製中：播放、下載、刪除都不可用（伺服器端也擋） */ $live_tip = t('錄製中，會議結束、錄影完成後才能播放、下載或刪除'); ?>
                <span class="rec-live-actions" title="<?= htmlspecialchars($live_tip) ?>">
                  <button type="button" class="btn btn-secondary btn-sm" disabled><?= icon('play', 14) ?><?= th('播放') ?></button>
                  <button type="button" class="btn btn-secondary btn-sm" disabled><?= icon('download', 14) ?><?= th('下載') ?></button>
                  <?php if ($is_admin): ?><button type="button" class="btn btn-ghost btn-sm" disabled><?= icon('trash', 14) ?><?= th('刪除') ?></button><?php endif; ?>
                </span>
              <?php else: ?>
              <?php if ($playable): ?>
                <button type="button" class="btn btn-secondary btn-sm js-play" title="<?= th('線上播放錄影') ?>" data-id="<?= htmlspecialchars($rid) ?>" data-room="<?= htmlspecialchars($r['room']) ?>"><?= icon('play', 14) ?><?= th('播放') ?></button>
                <a class="btn btn-secondary btn-sm" title="<?= th('下載錄影檔') ?>" href="/recordings-file?id=<?= rawurlencode($rid) ?>&dl=1"><?= icon('download', 14) ?><?= th('下載') ?></a>
              <?php endif; ?>
              <?php if ($is_admin): ?>
              <form method="POST" action="/recordings-action" style="display:inline;" data-confirm="<?= th('確定刪除此錄影？此動作無法復原。') ?>">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= htmlspecialchars($rid) ?>">
                <button class="btn btn-ghost btn-sm" title="<?= th('刪除這筆錄影（逐字稿與摘要一併刪除）') ?>"><?= icon('trash', 14) ?><?= th('刪除') ?></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="row-detail" hidden>
            <td colspan="<?= $tx_on ? 9 : 8 ?>">
              <?php if ($tx_on && ($why = tx_reason($tx_index[$rid] ?? null)) !== ''): ?>
                <div class="tx-detail-why"><?= icon('warning', 14) ?><span><?= th('逐字稿：{why}', ['why' => $why]) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($parts)): ?>
              <table class="table" style="margin:0;">
                <thead><tr><th><?= th('參與者') ?></th><th><?= th('進入') ?></th><th><?= th('離開') ?></th><th><?= th('停留') ?></th></tr></thead>
                <tbody>
                <?php foreach ($parts as $p): $pin = (int)($p['in'] ?? 0); $pout = (int)($p['out'] ?? $pin); ?>
                  <tr>
                    <td><?= htmlspecialchars((string)($p['name'] ?? '')) ?: th('（未具名）') ?></td>
                    <td class="mono"><?= date('H:i:s', $pin) ?></td>
                    <td class="mono"><?= date('H:i:s', $pout) ?></td>
                    <td class="mono"><?= htmlspecialchars(rec_dur(max(0, $pout - $pin))) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php else: ?>
                <span class="muted" style="font-size:13px;"><?= $st === 'recording' ? th('錄製中，會議結束後才會有參與者記錄。') : th('無對應的參與者記錄（此場可能在參與者統計功能上線前錄製，或主持人未在場回報）。') ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="modal-backdrop" id="playModal">
    <div class="modal" role="dialog" aria-modal="true" style="max-width:880px;width:92vw;">
      <h2 id="playTitle"><?= th('播放錄影') ?></h2>
      <video id="playVideo" controls preload="metadata" style="width:100%;border-radius:10px;background:#000;max-height:70vh;"></video>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="closePlay"><?= icon('x', 14) ?><?= th('關閉') ?></button>
      </div>
    </div>
  </div>
</main>
<script <?= nonce_attr() ?>>
(function () {
  var modal = document.getElementById('playModal');
  var video = document.getElementById('playVideo');
  var title = document.getElementById('playTitle');
  function close() { modal.classList.remove('open'); video.pause(); video.removeAttribute('src'); video.load(); }
  document.querySelectorAll('.js-play').forEach(function (b) {
    b.addEventListener('click', function () {
      title.textContent = <?= json_encode(t('播放錄影 · ')) ?> + (b.dataset.room || '');
      video.src = '/recordings-file?id=' + encodeURIComponent(b.dataset.id);
      modal.classList.add('open');
    });
  });
  document.getElementById('closePlay').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });

  <?php /* 點列展開參與者（避開操作鍵 / 連結 / 表單） */ ?>
  document.querySelectorAll('tr.rec-row.row-main').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('button, a, form, input')) return;
      var d = row.nextElementSibling;
      if (d && d.classList.contains('row-detail')) { d.hidden = !d.hidden; row.classList.toggle('open'); }
    });
  });

  var search = document.getElementById('recSearch');
  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      document.querySelectorAll('tr.rec-row').forEach(function (row) {
        var show = (!q || (row.dataset.search || '').indexOf(q) !== -1);
        row.style.display = show ? '' : 'none';
        var d = row.nextElementSibling;   <?php /* 連帶處理該列的明細 */ ?>
        if (d && d.classList.contains('row-detail')) { if (!show) { d.hidden = true; row.classList.remove('open'); } d.style.display = show ? '' : 'none'; }
      });
    });
  }
})();
</script>
<?php render_foot(); ?>
