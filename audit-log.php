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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>

  <?= admin_nav('audit') ?>
  <div class="card">
    <h1><?= icon('clock', 18) ?>稽核記錄</h1>
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
        <input type="text" id="from" name="from" value="<?= htmlspecialchars($from) ?>" placeholder="YYYY-MM-DD" autocomplete="off" style="width:120px;"></div>
      <div class="field"><label>結束日期</label>
        <input type="text" id="to" name="to" value="<?= htmlspecialchars($to) ?>" placeholder="YYYY-MM-DD" autocomplete="off" style="width:120px;"></div>
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
      <a class="btn btn-secondary btn-sm" href="/audit-export<?= $qsBase ? '?'.htmlspecialchars($qsBase) : '' ?>"><?= icon('download',14) ?>匯出 CSV</a>
    </form>

    <?php if (empty($rows)): ?>
      <div class="empty">沒有符合的記錄。</div>
    <?php else: ?>
      <p class="muted" style="font-size:12px;margin:0 0 8px;">點擊任一列可展開明細（含完整 User-Agent）。</p>
      <table class="table audit-table">
        <thead><tr><th class="caret-col no-sort"></th><th>時間</th><th>行為</th><th>帳號</th><th>詳情</th><th>結果</th><th>來源 IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $act = $r['action'] ?? '';
          $actLabel = $labels[$act] ?? $act;
          $ua = (string)($r['user_agent'] ?? '');
          $roleLabel = ['admin'=>'管理者','host'=>'主持人','guest'=>'來賓'][$r['role'] ?? ''] ?? ($r['role'] ?? '');
          $fullTime = date('Y-m-d H:i:s', $r['ts'] ?? strtotime($r['time'] ?? 'now'));
        ?>
          <tr class="row-main" title="點擊展開明細">
            <td class="caret-col"><svg class="caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></td>
            <td class="mono" style="white-space:nowrap;"><?= htmlspecialchars($fullTime) ?></td>
            <td><?= htmlspecialchars($actLabel) ?></td>
            <td class="mono"><?= htmlspecialchars($r['actor'] ?? '') ?><?= ($r['role'] ?? '')==='guest' ? ' <span class="badge badge-muted">來賓</span>' : '' ?></td>
            <td style="max-width:320px;"><?= htmlspecialchars($r['detail'] ?? '') ?></td>
            <td><?= audit_badge($r['result'] ?? 'ok') ?></td>
            <td class="mono"><?= htmlspecialchars($r['ip'] ?? '') ?></td>
          </tr>
          <tr class="row-detail" hidden>
            <td colspan="7">
              <dl class="audit-detail">
                <dt>時間</dt><dd class="mono"><?= htmlspecialchars($fullTime) ?></dd>
                <dt>行為</dt><dd><?= htmlspecialchars($actLabel) ?>（<?= htmlspecialchars($act) ?>）</dd>
                <dt>帳號 / 角色</dt><dd><?= htmlspecialchars($r['actor'] ?? '') ?><?= $roleLabel !== '' ? '　·　' . htmlspecialchars($roleLabel) : '' ?></dd>
                <dt>顯示名稱</dt><dd><?= htmlspecialchars($r['actor_name'] ?? '') ?: '—' ?></dd>
                <dt>來源 IP</dt><dd class="mono"><?= htmlspecialchars($r['ip'] ?? '') ?: '—' ?></dd>
                <dt>完整詳情</dt><dd><?= htmlspecialchars($r['detail'] ?? '') ?: '—' ?></dd>
                <dt>User-Agent</dt><dd class="mono ua"><?= htmlspecialchars($ua) ?: '—' ?></dd>
              </dl>
            </td>
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
<style>
  .audit-table .caret-col{width:28px;text-align:center;padding-left:4px;padding-right:4px;}
  .audit-table .row-main{cursor:pointer;}
  .audit-table .row-main .caret{transition:transform .15s;opacity:.6;vertical-align:middle;}
  .audit-table .row-main.open .caret{transform:rotate(90deg);opacity:1;}
  .audit-table .row-main.open > td{background:var(--bg-muted,rgba(127,127,127,.08));}
  .audit-table .row-detail > td{background:var(--bg-muted,rgba(127,127,127,.06));padding:14px 18px;}
  .audit-detail{display:grid;grid-template-columns:max-content 1fr;gap:6px 18px;margin:0;font-size:13px;}
  .audit-detail dt{color:var(--muted,#6b7280);font-weight:600;white-space:nowrap;}
  .audit-detail dd{margin:0;word-break:break-all;}
  .audit-detail dd.ua{line-height:1.5;}
</style>
<script>
  if (window.flatpickr) {
    flatpickr('#from', { dateFormat: 'Y-m-d', allowInput: true });
    flatpickr('#to',   { dateFormat: 'Y-m-d', allowInput: true });
  }
  document.querySelectorAll('.audit-table .row-main').forEach(function (tr) {
    tr.addEventListener('click', function () {
      var det = tr.nextElementSibling;
      if (!det || !det.classList.contains('row-detail')) return;
      det.hidden = !det.hidden;
      tr.classList.toggle('open', !det.hidden);
    });
  });
</script>
<?php render_foot(); ?>
