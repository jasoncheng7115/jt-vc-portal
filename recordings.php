<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/layout.php';

$me = Auth::requireAdmin();
$ip = Auth::clientIp();

$msg = $_SESSION['rec_msg'] ?? '';
$err = $_SESSION['rec_err'] ?? '';
unset($_SESSION['rec_msg'], $_SESSION['rec_err']);

$configured = Recordings::configured();
$stats = $configured ? Recordings::stats() : null;
$list  = $configured ? Recordings::listRecordings() : [];

function rec_bytes($n): string {
  $n = (float)$n; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
  while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
  return ($i === 0 ? (int)$n : number_format($n, 1)) . ' ' . $u[$i];
}
function rec_status_badge(string $s): string {
  $map = [
    'ok'         => ['badge-success', '正常'],
    'recording'  => ['badge-accent',  '錄製中'],
    'incomplete' => ['badge-warning', '未完成'],
    'orphan'     => ['badge-warning', '殘留'],
  ];
  [$cls, $txt] = $map[$s] ?? ['badge-muted', $s];
  return '<span class="badge ' . $cls . '">' . htmlspecialchars($txt) . '</span>';
}

render_head('錄影記錄');
render_topbar($me, $ip);
?>
<main class="container">
  <?= admin_nav('recordings') ?>

  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <div class="card">
    <h1><?= icon('video', 18) ?>錄影記錄</h1>
    <p class="subtitle">調閱自建 Jibri 主機上的會議錄影，可線上播放、下載與刪除。</p>

    <?php if (!$configured): ?>
      <div class="alert alert-info" style="align-items:flex-start;">
        <?= icon('warning') ?>
        <span>尚未設定 Jibri 錄影服務。請至 <a href="/settings">系統設定 → 錄製設定</a> 填入服務 URL 與 token。</span>
      </div>
    <?php elseif ($stats === null): ?>
      <div class="alert alert-error" style="align-items:flex-start;">
        <?= icon('warning') ?>
        <span>無法連線到 Jibri 錄影服務，請確認服務狀態、URL 與 token，以及來源 IP 允許清單。</span>
      </div>
    <?php else:
      $disk = $stats['disk']; $rec = $stats['recordings'];
      $usedPct = $disk['total'] > 0 ? round($disk['used'] / $disk['total'] * 100) : 0;
      $lvl = $usedPct >= 90 ? 'lvl-crit' : ($usedPct >= 75 ? 'lvl-warn' : 'lvl-ok');
    ?>
      <div style="margin:6px 0 4px;">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:6px;">
          <span style="display:inline-flex;align-items:center;gap:5px;"><?= icon('chart', 14) ?>錄影主機容量</span>
          <span class="mono"><?= rec_bytes($disk['used']) ?> / <?= rec_bytes($disk['total']) ?>（已用 <?= $usedPct ?>%）</span>
        </div>
        <div class="usage-bar"><div class="usage-fill <?= $lvl ?>" style="width:<?= $usedPct ?>%"></div></div>
        <div class="mono" style="font-size:12px;color:var(--text-muted);margin-top:6px;">
          可用 <?= rec_bytes($disk['free']) ?>　·　錄影 <?= (int)$rec['count'] ?> 筆（<?= rec_bytes($rec['size']) ?>）
        </div>
      </div>

      <form method="POST" action="/recordings-action" style="margin:14px 0 4px;">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="cleanup">
        <button class="btn btn-secondary btn-sm" onclick="return confirm('依目前保留政策立即清理？此動作會刪除符合條件的錄影。');"><?= icon('trash', 14) ?>依保留政策立即清理</button>
        <span class="help" style="margin-left:8px;">保留政策於 <a href="/settings">系統設定 → 錄製設定</a> 調整。</span>
      </form>

      <?php if (empty($list)): ?>
        <div class="empty">目前沒有錄影檔。</div>
      <?php else: ?>
      <table class="table">
        <thead><tr><th>會議室</th><th>時間</th><th>大小</th><th>狀態</th><th style="text-align:right;">操作</th></tr></thead>
        <tbody>
        <?php foreach ($list as $r):
          $rid = (string)$r['id']; $st = (string)($r['status'] ?? 'ok');
          $playable = $st !== 'orphan' && !empty($r['file']);
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['room'] ?: '（未知）') ?></strong></td>
            <td class="mono" style="white-space:nowrap;"><?= htmlspecialchars(date('Y-m-d H:i:s', (int)$r['mtime'])) ?></td>
            <td class="mono"><?= rec_bytes($r['size']) ?></td>
            <td><?= rec_status_badge($st) ?></td>
            <td style="text-align:right;white-space:nowrap;">
              <?php if ($playable): ?>
                <button type="button" class="btn btn-secondary btn-sm js-play" data-id="<?= htmlspecialchars($rid) ?>" data-room="<?= htmlspecialchars($r['room']) ?>"><?= icon('play', 14) ?>播放</button>
                <a class="btn btn-secondary btn-sm" href="/recordings-file?id=<?= rawurlencode($rid) ?>&dl=1"><?= icon('download', 14) ?>下載</a>
              <?php endif; ?>
              <form method="POST" action="/recordings-action" style="display:inline;" onsubmit="return confirm('確定刪除此錄影？此動作無法復原。');">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= htmlspecialchars($rid) ?>">
                <button class="btn btn-ghost btn-sm"><?= icon('trash', 14) ?>刪除</button>
              </form>
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
      <h2 id="playTitle">播放錄影</h2>
      <video id="playVideo" controls preload="metadata" style="width:100%;border-radius:10px;background:#000;max-height:70vh;"></video>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="closePlay"><?= icon('x', 14) ?>關閉</button>
      </div>
    </div>
  </div>
</main>
<script>
(function () {
  var modal = document.getElementById('playModal');
  var video = document.getElementById('playVideo');
  var title = document.getElementById('playTitle');
  function close() { modal.classList.remove('open'); video.pause(); video.removeAttribute('src'); video.load(); }
  document.querySelectorAll('.js-play').forEach(function (b) {
    b.addEventListener('click', function () {
      title.textContent = '播放錄影 · ' + (b.dataset.room || '');
      video.src = '/recordings-file?id=' + encodeURIComponent(b.dataset.id);
      modal.classList.add('open');
    });
  });
  document.getElementById('closePlay').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
})();
</script>
<?php render_foot(); ?>
