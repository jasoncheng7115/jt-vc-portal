<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/layout.php';

$me = Auth::requireAdmin();
$ip = Auth::clientIp();

$labels = Audit::labels();
$action = $_GET['action'] ?? '';
$q      = trim($_GET['q'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$per    = (int)($_GET['per'] ?? 50);
$page   = (int)($_GET['page'] ?? 1);

$res = Audit::search(['action' => $action, 'q' => $q, 'from' => $from, 'to' => $to], $page, $per);
$rows = $res['rows'];

// 保留篩選條件的 query string（換頁用）
$qsBase = http_build_query(array_filter([
  'action' => $action, 'q' => $q, 'from' => $from, 'to' => $to, 'per' => $per,
], fn($v) => $v !== '' && $v !== null));

function audit_badge(string $result): string {
  if ($result === 'fail') return '<span class="badge badge-warning">失敗</span>';
  if ($result === 'warn') return '<span class="badge badge-muted">警示</span>';
  return '<span class="badge badge-success">成功</span>';
}

render_head('稽核記錄');
render_topbar($me, $ip);
?>
<main class="container">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>

  <div class="nav-row">
    <a class="btn btn-ghost btn-sm" href="/dashboard"><?= icon('arrow-right', 14) ?>回儀表板</a>
  </div>
  <div class="card">
    <h1>稽核記錄</h1>
    <p class="subtitle">系統行為記錄（登入登出、建立 / 進入會議室、寄送邀請、帳號與設定變更等），同時即時外拋 SIEM（若已啟用）。</p>

    <form method="GET" action="/audit-log" class="inline-form" style="margin-bottom:16px;">
      <div class="field"><label>行為類型</label>
        <select name="action">
          <option value="">全部</option>
          <?php foreach ($labels as $k => $v): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $action===$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>開始日期</label>
        <input type="text" id="from" name="from" value="<?= htmlspecialchars($from) ?>" placeholder="YYYY-MM-DD" autocomplete="off" style="min-width:140px;"></div>
      <div class="field"><label>結束日期</label>
        <input type="text" id="to" name="to" value="<?= htmlspecialchars($to) ?>" placeholder="YYYY-MM-DD" autocomplete="off" style="min-width:140px;"></div>
      <div class="field"><label>關鍵字（帳號 / 詳情 / IP）</label>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="搜尋"></div>
      <div class="field"><label>每頁</label>
        <select name="per">
          <?php foreach ([25,50,100,200] as $n): ?>
            <option value="<?= $n ?>" <?= $per===$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-secondary btn-sm"><?= icon('arrow-right',14) ?>篩選</button>
      <a class="btn btn-ghost btn-sm" href="/audit-log"><?= icon('refresh',14) ?>清除</a>
    </form>

    <?php if (empty($rows)): ?>
      <div class="empty">沒有符合的記錄。</div>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>時間</th><th>行為</th><th>帳號</th><th>顯示名稱</th><th>詳情</th><th>結果</th><th>來源 IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $act = $r['action'] ?? '';
          $actLabel = $labels[$act] ?? $act;
        ?>
          <tr>
            <td class="mono" style="white-space:nowrap;"><?= htmlspecialchars(date('Y-m-d H:i:s', $r['ts'] ?? strtotime($r['time'] ?? 'now'))) ?></td>
            <td><?= htmlspecialchars($actLabel) ?></td>
            <td class="mono"><?= htmlspecialchars($r['actor'] ?? '') ?><?= ($r['role'] ?? '')==='guest' ? ' <span class="badge badge-muted">來賓</span>' : '' ?></td>
            <td><?= htmlspecialchars($r['actor_name'] ?? '') ?></td>
            <td style="max-width:320px;"><?= htmlspecialchars($r['detail'] ?? '') ?></td>
            <td><?= audit_badge($r['result'] ?? 'ok') ?></td>
            <td class="mono"><?= htmlspecialchars($r['ip'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pager">
        <span class="muted">共 <?= (int)$res['total'] ?> 筆 · 第 <?= (int)$res['page'] ?> / <?= (int)$res['pages'] ?> 頁</span>
        <span class="pager-btns">
          <?php if ($res['page'] > 1): ?>
            <a class="btn btn-secondary btn-sm" href="?<?= $qsBase ?>&page=1">« 第一頁</a>
            <a class="btn btn-secondary btn-sm" href="?<?= $qsBase ?>&page=<?= $res['page']-1 ?>">‹ 上一頁</a>
          <?php endif; ?>
          <?php if ($res['page'] < $res['pages']): ?>
            <a class="btn btn-secondary btn-sm" href="?<?= $qsBase ?>&page=<?= $res['page']+1 ?>">下一頁 ›</a>
            <a class="btn btn-secondary btn-sm" href="?<?= $qsBase ?>&page=<?= $res['pages'] ?>">最末頁 »</a>
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
</main>
<script>
  if (window.flatpickr) {
    flatpickr('#from', { dateFormat: 'Y-m-d', allowInput: true });
    flatpickr('#to',   { dateFormat: 'Y-m-d', allowInput: true });
  }
</script>
<?php render_foot(); ?>
