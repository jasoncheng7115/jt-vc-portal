<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
$ip = Auth::clientIp();

// 8x8 用量 / MAU 為 JaaS 模式專屬；其餘會議統計兩種模式皆適用
$is_jaas = Settings::getJaas()['mode'] === 'jaas';

// 依保留天數清理過舊的會議記錄
Rooms::pruneMeetings(Settings::getMeetingRetentionDays());

function fmt_dur(int $s): string {
  if ($s < 60) return t('{n} 秒', ['n' => $s]);
  $m = intdiv($s, 60);
  if ($m < 60) return t('{n} 分', ['n' => $m]);
  $h = intdiv($m, 60); $mm = $m % 60;
  return $mm ? t('{h} 時 {m} 分', ['h' => $h, 'm' => $mm]) : t('{h} 時', ['h' => $h]);
}

// === 本期 MAU ===
$period        = Usage::currentPeriod();
$periodStartTs = strtotime($period);
$periodEndTs   = strtotime('+1 month', $periodStartTs);
$usage_count   = Usage::currentMonthCount();
$plan_limit    = Settings::getPlanLimit();
$usage_pct     = $plan_limit > 0 ? min(100, (int)round($usage_count / $plan_limit * 100)) : 0;
$usage_lvl     = $usage_pct >= 95 ? 'crit' : ($usage_pct >= 80 ? 'warn' : 'ok');

// === 歷史 MAU 趨勢（最近 12 期）===
$all = Usage::load();
ksort($all);
$histLabels = []; $histData = [];
foreach (array_slice(array_keys($all), -12) as $k) {
  $histLabels[] = date('Y/m/d', strtotime($k));
  $histData[]   = Usage::periodCount($k);
}

// === 行為統計（本期）+ 近 30 天每日 ===
$entries = Audit::query(20000);
$cnt = ['room_create' => 0, 'room_enter' => 0, 'guest_join' => 0, 'invite_sent' => 0];
$dayKeys = [];
for ($i = 29; $i >= 0; $i--) $dayKeys[] = date('Y-m-d', strtotime("-$i day"));
$daily = [];
foreach ($dayKeys as $d) $daily[$d] = ['room_create' => 0, 'room_enter' => 0, 'guest_join' => 0];
$cutoff = strtotime($dayKeys[0] . ' 00:00:00');
foreach ($entries as $e) {
  $ts = (int)($e['ts'] ?? 0);
  $a  = $e['action'] ?? '';
  if ($ts >= $periodStartTs && $ts < $periodEndTs && isset($cnt[$a])) $cnt[$a]++;
  if ($ts >= $cutoff) {
    $d = date('Y-m-d', $ts);
    if (isset($daily[$d]) && isset($daily[$d][$a])) $daily[$d][$a]++;
  }
}
$dailyLabels = array_map(fn($d) => date('n/j', strtotime($d)), $dayKeys);
$dailyCreate = array_map(fn($d) => $daily[$d]['room_create'], $dayKeys);
$dailyEnter  = array_map(fn($d) => $daily[$d]['room_enter'],  $dayKeys);
$dailyGuest  = array_map(fn($d) => $daily[$d]['guest_join'],  $dayKeys);

// === 目前活躍會議室 ===
$rooms_now    = Rooms::pruneAndGet();
$active_rooms = count($rooms_now);

// === 本期會議時長 session ===
$sessions = Rooms::meetingSessions($periodStartTs, min(time(), $periodEndTs));
usort($sessions, fn($a, $b) => ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0)));
$meet_count = count($sessions);
$meet_total = array_sum(array_map(fn($s) => (int)($s['dur'] ?? 0), $sessions));
$meet_avg   = $meet_count > 0 ? (int)round($meet_total / $meet_count) : 0;
$span       = max(1, $periodEndTs - $periodStartTs);

// 主持人排行（本期，依總時長排序）
$host_rank = [];
foreach ($sessions as $s) {
  $h = trim((string)($s['owner_name'] ?? '')) ?: t('（未具名）');
  if (!isset($host_rank[$h])) $host_rank[$h] = ['count' => 0, 'dur' => 0];
  $host_rank[$h]['count']++;
  $host_rank[$h]['dur'] += (int)($s['dur'] ?? 0);
}
uasort($host_rank, fn($a, $b) => $b['dur'] <=> $a['dur']);

// === 會議時長排行（所有保留記錄，前 25 長）===
$topMeetings = Rooms::topMeetingsByDuration(25);
$topLabels = [];
$topMins   = [];
$topTips   = [];
foreach ($topMeetings as $tm) {
  $room = (string)($tm['room'] ?? '');
  $when = date('Y-m-d H:i', (int)($tm['start'] ?? $tm['ts'] ?? 0));
  $dur  = (int)($tm['dur'] ?? 0);
  $topLabels[] = t('{room}（{date}）', ['room' => $room, 'date' => date('m/d', (int)($tm['start'] ?? $tm['ts'] ?? 0))]);
  $topMins[]   = round($dur / 60, 1);
  $topTips[]   = t('{room}　{when}　{dur}', ['room' => $room, 'when' => $when, 'dur' => fmt_dur($dur)]);
}

render_head($is_jaas ? t('用量統計') : t('會議統計'));
render_topbar($me, $ip);
?>
<main class="container">
  <script <?= nonce_attr() ?> src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>

  <?= admin_nav('usage') ?>

  <?php if ($is_jaas): ?>
  <?php /* 本期 MAU（JaaS 專屬） */ ?>
  <div class="card card-usage">
    <div class="usage-head">
      <div>
        <h1 style="margin:0;"><?= th('本期 8x8 用量') ?></h1>
        <p class="subtitle" style="margin:6px 0 0;"><?= th('JaaS Monthly Active Users（本期 {period}）', ['period' => Usage::currentPeriodLabel()]) ?></p>
      </div>
      <div class="usage-num"><?= (int)$usage_count ?> <span class="lim">/ <?= (int)$plan_limit ?> MAU</span></div>
    </div>
    <div class="usage-bar"><div class="usage-fill lvl-<?= $usage_lvl ?>" style="width: <?= (int)$usage_pct ?>%"></div></div>
    <p class="muted" style="font-size:12px;margin:0;">
      <?= th('{pct}% 已使用', ['pct' => $usage_pct]) ?><?php if ($usage_count === 0): ?> · <?= th('需在 8x8 Console 設定 USAGE webhook 後才會開始計量') ?><?php endif; ?>
    </p>
  </div>
  <?php endif; ?>

  <?php /* 本期統計數字 */ ?>
  <div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['room_create'] ?></div><div class="stat-label"><?= icon('plus', 14) ?><?= th('建立會議室') ?></div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['room_enter'] ?></div><div class="stat-label"><?= icon('play', 14) ?><?= th('主持進入') ?></div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['guest_join'] ?></div><div class="stat-label"><?= icon('user', 14) ?><?= th('來賓進入') ?></div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['invite_sent'] ?></div><div class="stat-label"><?= icon('calendar', 14) ?><?= th('寄送邀請') ?></div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$meet_count ?></div><div class="stat-label"><?= icon('video', 14) ?><?= th('會議場次') ?></div></div>
    <div class="stat-card"><div class="stat-num" style="font-size:22px;"><?= htmlspecialchars($meet_count ? fmt_dur($meet_avg) : '—') ?></div><div class="stat-label"><?= icon('clock', 14) ?><?= th('平均時長') ?></div></div>
    <div class="stat-card"><div class="stat-num" style="font-size:22px;"><?= htmlspecialchars($meet_count ? fmt_dur($meet_total) : '—') ?></div><div class="stat-label"><?= icon('clock', 14) ?><?= th('會議總時長') ?></div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$active_rooms ?></div><div class="stat-label"><?= icon('home', 14) ?><?= th('活躍會議室') ?></div></div>
  </div>

  <?php if ($is_jaas): ?>
  <?php /* 歷史 MAU 趨勢（JaaS 專屬） */ ?>
  <div class="card">
    <div class="card-title"><?= icon('chart', 16) ?><?= th('歷史 MAU 趨勢（依計費週期）') ?></div>
    <?php if (count($histData) > 0): ?>
      <div class="chart-wrap"><canvas id="histChart"></canvas></div>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin:4px 0 0;"><?= th('尚無歷史資料，開始計量後此處會顯示各週期的 MAU 趨勢。') ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* 近 30 天活動 */ ?>
  <div class="card">
    <div class="card-title"><?= icon('chart', 16) ?><?= th('近 30 天活動') ?></div>
    <div class="chart-wrap"><canvas id="dailyChart"></canvas></div>
  </div>

  <?php /* 會議時長排行 Top 25 */ ?>
  <div class="card">
    <div class="card-title"><?= icon('clock', 16) ?><?= th('會議時長排行') ?>
      <span class="muted" style="font-weight:400;font-size:12px;margin-left:8px;"><?= th('所有記錄 · 最長前 25 場') ?></span>
    </div>
    <?php if (!empty($topMeetings)): ?>
      <div class="chart-wrap" style="height:<?= max(220, count($topMeetings) * 24 + 40) ?>px;"><canvas id="topDurChart"></canvas></div>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin:4px 0 0;"><?= th('尚無會議記錄。會議結束（主持人離開）後即會在此累積。') ?></p>
    <?php endif; ?>
  </div>

  <?php /* 主持人排行榜 */ ?>
  <div class="card">
    <div class="card-title"><?= icon('user', 16) ?><?= th('主持人排行榜') ?>
      <span class="muted" style="font-weight:400;font-size:12px;margin-left:8px;"><?= th('本期 · 依會議總時長') ?></span>
    </div>
    <?php if (!empty($host_rank)): ?>
      <table class="table">
        <thead><tr><th><?= th('排名') ?></th><th><?= th('主持人') ?></th><th><?= th('會議場次') ?></th><th><?= th('總時長') ?></th></tr></thead>
        <tbody>
        <?php $rk = 0; foreach ($host_rank as $hname => $hd): $rk++; ?>
          <tr>
            <td class="mono"><?= $rk ?></td>
            <td><?= icon('user', 12) ?> <?= htmlspecialchars($hname) ?></td>
            <td><?= (int)$hd['count'] ?></td>
            <td><?= htmlspecialchars(fmt_dur($hd['dur'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin:4px 0 0;"><?= th('本期尚無會議記錄。') ?></p>
    <?php endif; ?>
  </div>

  <?php /* 本期會議時長時間軸（場次多時很長、資訊密度低 → 預設收起，點標題展開） */ ?>
  <details class="card card-fold" id="ganttCard">
    <summary class="card-title"><?= icon('clock', 16) ?><?= th('本期會議時長時間軸') ?>
      <span class="muted" style="font-weight:400;font-size:12px;margin-left:8px;"><?= th('共 {n} 場 · 總時長 {dur}', ['n' => (int)$meet_count, 'dur' => fmt_dur($meet_total)]) ?></span>
      <span class="fold-chev"><?= icon('chevron-down', 16) ?></span>
    </summary>
    <?php if ($meet_count > 0): ?>
      <div class="gantt">
        <div class="gantt-axis">
          <span><?= date('m/d', $periodStartTs) ?></span>
          <span><?= date('m/d', $periodStartTs + (int)($span / 2)) ?></span>
          <span><?= date('m/d', $periodEndTs) ?></span>
        </div>
        <?php foreach ($sessions as $s):
          $st = (int)($s['start'] ?? 0); $dur = (int)($s['dur'] ?? 0);
          $left = max(0, min(99, ($st - $periodStartTs) / $span * 100));
          $w = max(1.2, min(100 - $left, $dur / $span * 100));
          $room = (string)($s['room'] ?? '');
          $host = (string)($s['owner_name'] ?? '');
          $tipVars = ['room' => $room, 'host' => $host, 'start' => date('m/d H:i', $st), 'end' => date('H:i', (int)($s['end'] ?? $st)), 'dur' => fmt_dur($dur)];
          $tip = $host ? t('{room}（{host}）　{start} ~ {end}　({dur})', $tipVars) : t('{room}　{start} ~ {end}　({dur})', $tipVars);
        ?>
          <div class="gantt-row">
            <div class="gantt-label" title="<?= htmlspecialchars($room . ($host ? ' · ' . $host : '')) ?>">
              <span class="gantt-room"><?= htmlspecialchars($room) ?></span>
              <?php if ($host !== ''): ?><span class="gantt-host"><?= icon('user', 10) ?><?= htmlspecialchars($host) ?></span><?php endif; ?>
            </div>
            <div class="gantt-track">
              <div class="gantt-bar" style="left:<?= $left ?>%;width:<?= $w ?>%;" title="<?= htmlspecialchars($tip) ?>"></div>
            </div>
            <div class="gantt-dur"><?= htmlspecialchars(fmt_dur($dur)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin:4px 0 0;"><?= th('本期尚無會議時長記錄。會議結束（主持人離開）後即會在此累積各場次的長度。') ?></p>
    <?php endif; ?>
  </details>

  <?php /* 本期會議參與者（尖峰同時人數 + 進出時間軸） */ ?>
  <?php $sessWithP = array_values(array_filter($sessions, fn($s) => !empty($s['participants']))); ?>
  <div class="card">
    <div class="card-title"><?= icon('user', 16) ?><?= th('本期會議參與者') ?>
      <span class="muted" style="font-weight:400;font-size:12px;margin-left:8px;"><?= th('尖峰同時人數與參與者進出時間軸') ?></span>
    </div>
    <?php if (!empty($sessWithP)): ?>
      <p class="muted" style="font-size:12px;margin:0 0 8px;"><?= th('點任一列展開參與者進出明細。') ?></p>
      <table class="table audit-table">
        <thead><tr><th class="caret-col no-sort"></th><th><?= th('會議室') ?></th><th><?= th('主持人') ?></th><th><?= th('時間') ?></th><th><?= th('尖峰同時') ?></th><th><?= th('不重複') ?></th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($sessWithP) as $s):
          $st = (int)($s['start'] ?? 0); $en = (int)($s['end'] ?? $st);
          $ps = is_array($s['participants'] ?? null) ? $s['participants'] : [];
        ?>
          <tr class="row-main" title="<?= th('點擊展開明細') ?>">
            <td class="caret-col"><svg class="caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></td>
            <td><strong><?= htmlspecialchars((string)($s['room'] ?? '')) ?></strong></td>
            <td><?= htmlspecialchars((string)($s['owner_name'] ?? '')) ?: '—' ?></td>
            <td class="mono" style="white-space:nowrap;"><?= date('m/d H:i', $st) ?>–<?= date('H:i', $en) ?></td>
            <td><?= t('{n} 人', ['n' => '<strong>' . (int)($s['peak'] ?? 0) . '</strong>']) ?></td>
            <td><?= th('{n} 人', ['n' => (int)($s['attendees'] ?? count($ps))]) ?></td>
          </tr>
          <tr class="row-detail" hidden>
            <td colspan="6">
              <table class="table" style="margin:0;">
                <thead><tr><th><?= th('參與者') ?></th><th><?= th('進入') ?></th><th><?= th('離開') ?></th><th><?= th('停留') ?></th></tr></thead>
                <tbody>
                <?php foreach ($ps as $p): $pin = (int)($p['in'] ?? 0); $pout = (int)($p['out'] ?? $pin); ?>
                  <tr>
                    <td><?= htmlspecialchars((string)($p['name'] ?? '')) ?: th('（未具名）') ?></td>
                    <td class="mono"><?= date('H:i:s', $pin) ?></td>
                    <td class="mono"><?= date('H:i:s', $pout) ?></td>
                    <td class="mono"><?= htmlspecialchars(fmt_dur(max(0, $pout - $pin))) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin:4px 0 0;"><?= th('本期尚無參與者統計。新會議結束後會在此累積（需主持人在場，由主持人端回報；舊會議無此資料）。') ?></p>
    <?php endif; ?>
  </div>

<script <?= nonce_attr() ?>>
(function () {
  <?php /* 參與者明細：點列展開（與稽核記錄相同樣式） */ ?>
  document.querySelectorAll('#js-attendee-card .row-main, .card .row-main').forEach(function (row) {
    row.addEventListener('click', function () {
      var d = row.nextElementSibling;
      if (d && d.classList.contains('row-detail')) { d.hidden = !d.hidden; row.classList.toggle('open'); }
    });
  });
})();
</script>

<script <?= nonce_attr() ?>>
(function () {
  if (!window.Chart) return;
  // shared look: site font, light/dark theme, faint horizontal grid only
  const dark = document.body.classList.contains('is-dark');
  const css = getComputedStyle(document.body);
  const text = dark ? 'rgba(255,255,255,.72)' : '#475569', faint = dark ? 'rgba(255,255,255,.45)' : '#94a3b8';
  const gridC = dark ? 'rgba(255,255,255,.08)' : 'rgba(15,23,42,.07)';
  Chart.defaults.font.family = css.fontFamily;
  Chart.defaults.font.size = 12;
  Chart.defaults.color = text;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.boxHeight = 8;
  Chart.defaults.plugins.legend.labels.padding = 16;
  Object.assign(Chart.defaults.plugins.tooltip, {
    backgroundColor: dark ? 'rgba(15,23,42,.96)' : 'rgba(15,23,42,.92)', titleColor: '#fff', bodyColor: '#e2e8f0',
    padding: 10, cornerRadius: 10, boxPadding: 5, usePointStyle: true, titleFont: { weight: '600' }
  });
  const yAxis = { beginAtZero: true, border: { display: false }, grid: { color: gridC, drawTicks: false }, ticks: { precision: 0, padding: 8, color: faint } };
  const xAxis = { border: { display: false }, grid: { display: false }, ticks: { color: faint, maxRotation: 0, autoSkip: true, autoSkipPadding: 14 } };
  // gradients for bars
  function vgrad(ctx, area, from, to) { const g = ctx.createLinearGradient(0, area.bottom, 0, area.top); g.addColorStop(0, from); g.addColorStop(1, to); return g; }
  function hgrad(ctx, area, from, to) { const g = ctx.createLinearGradient(area.left, 0, area.right, 0); g.addColorStop(0, from); g.addColorStop(1, to); return g; }

  const histData = <?= json_encode($histData) ?>;
  const histLabels = <?= json_encode($histLabels) ?>;
  if (document.getElementById('histChart') && histData.length) {
    new Chart(document.getElementById('histChart'), {
      type: 'bar',
      data: { labels: histLabels, datasets: [{ label: 'MAU', data: histData, borderRadius: 8, borderSkipped: false, maxBarThickness: 44,
        backgroundColor: (c) => c.chart.chartArea ? vgrad(c.chart.ctx, c.chart.chartArea, '#a78bfa', '#6d28d9') : '#7c3aed' }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: xAxis, y: yAxis } }
    });
  }

  <?php /* 會議時長排行 Top 25（水平長條，最長在上；長條尾端直接標時長） */ ?>
  const topLabels = <?= json_encode($topLabels) ?>;
  const topMins   = <?= json_encode($topMins) ?>;
  const topTips   = <?= json_encode($topTips) ?>;
  // same wording as the summary cards (fmt_dur), e.g. "1 hr 34 min"
  const FMT = <?= json_encode(['hm' => t('{h} 時 {m} 分'), 'h' => t('{h} 時'), 'm' => t('{n} 分')], JSON_UNESCAPED_UNICODE) ?>;
  const fmtMin = (m) => { m = Math.round(m); const h = Math.floor(m / 60), r = m % 60;
    return h ? (r ? FMT.hm.replace('{h}', h).replace('{m}', r) : FMT.h.replace('{h}', h)) : FMT.m.replace('{n}', m); };
  const endLabels = { id: 'endLabels', afterDatasetsDraw(chart) {
    const { ctx } = chart; const meta = chart.getDatasetMeta(0);
    ctx.save(); ctx.font = '600 11px ' + css.fontFamily; ctx.fillStyle = text; ctx.textBaseline = 'middle';
    meta.data.forEach((bar, i) => { ctx.fillText(fmtMin(topMins[i]), bar.x + 8, bar.y); });
    ctx.restore();
  } };
  if (document.getElementById('topDurChart') && topMins.length) {
    new Chart(document.getElementById('topDurChart'), {
      type: 'bar',
      data: { labels: topLabels, datasets: [{ label: <?= json_encode(t('時長（分鐘）')) ?>, data: topMins, borderRadius: 6, borderSkipped: false, maxBarThickness: 16,
        backgroundColor: (c) => c.chart.chartArea ? hgrad(c.chart.ctx, c.chart.chartArea, '#22d3ee', '#6366f1') : '#0ea5e9' }] },
      options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, layout: { padding: { right: 64 } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => topTips[c.dataIndex] } } },
        scales: { x: Object.assign({}, yAxis, { ticks: { color: faint, padding: 6, callback: (v) => FMT.m.replace('{n}', v) } }),
                  y: { border: { display: false }, grid: { display: false }, ticks: { color: text, padding: 6 } } } },
      plugins: [endLabels]
    });
  }

  <?php /* 近 30 天活動：每天的次數用堆疊長條（原本三條曲線在 0 附近疊在一起、曲線還會衝過實際值） */ ?>
  const days = <?= json_encode($dailyLabels) ?>;
  new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: { labels: days, datasets: [
      { label: <?= json_encode(t('建立會議室')) ?>, data: <?= json_encode($dailyCreate) ?>, backgroundColor: '#8b5cf6' },
      { label: <?= json_encode(t('主持進入')) ?>, data: <?= json_encode($dailyEnter) ?>,  backgroundColor: '#22d3ee' },
      { label: <?= json_encode(t('來賓進入')) ?>, data: <?= json_encode($dailyGuest) ?>,  backgroundColor: '#f472b6' }
    ].map((d) => Object.assign(d, { stack: 'a', borderRadius: 4, borderSkipped: false, borderWidth: 0, maxBarThickness: 22, categoryPercentage: .72, barPercentage: .9 })) },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top', align: 'end' },
        tooltip: { callbacks: { footer: (items) => <?= json_encode(t('合計')) ?> + ' ' + items.reduce((a, i) => a + i.parsed.y, 0) } } },
      scales: { x: Object.assign({ stacked: true }, xAxis), y: Object.assign({ stacked: true }, yAxis) } }
  });
})();
</script>
</main>
<?php render_foot(); ?>
