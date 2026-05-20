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

// 用量為 JaaS 模式專屬；自建模式導回儀表板
if (Settings::getJaas()['mode'] !== 'jaas') {
  header('Location: /dashboard');
  exit;
}

function fmt_dur(int $s): string {
  if ($s < 60) return $s . ' 秒';
  $m = intdiv($s, 60);
  if ($m < 60) return $m . ' 分';
  $h = intdiv($m, 60); $mm = $m % 60;
  return $h . ' 時' . ($mm ? ' ' . $mm . ' 分' : '');
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

render_head('用量統計');
render_topbar($me, $ip);
?>
<main class="container">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <?= admin_nav('usage') ?>

  <!-- 本期 MAU -->
  <div class="card card-usage">
    <div class="usage-head">
      <div>
        <h1 style="margin:0;">本期 8x8 用量</h1>
        <p class="subtitle" style="margin:6px 0 0;">JaaS Monthly Active Users（本期 <?= htmlspecialchars(Usage::currentPeriodLabel()) ?>）</p>
      </div>
      <div class="usage-num"><?= (int)$usage_count ?> <span class="lim">/ <?= (int)$plan_limit ?> MAU</span></div>
    </div>
    <div class="usage-bar"><div class="usage-fill lvl-<?= $usage_lvl ?>" style="width: <?= (int)$usage_pct ?>%"></div></div>
    <p class="muted" style="font-size:12px;margin:0;">
      <?= $usage_pct ?>% 已使用<?php if ($usage_count === 0): ?> · 需在 8x8 Console 設定 USAGE webhook 後才會開始計量<?php endif; ?>
    </p>
  </div>

  <!-- 本期統計數字 -->
  <div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['room_create'] ?></div><div class="stat-label"><?= icon('plus', 14) ?>建立會議室</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['room_enter'] ?></div><div class="stat-label"><?= icon('play', 14) ?>主持進入</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['guest_join'] ?></div><div class="stat-label"><?= icon('user', 14) ?>來賓進入</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$cnt['invite_sent'] ?></div><div class="stat-label"><?= icon('calendar', 14) ?>寄送邀請</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$meet_count ?></div><div class="stat-label"><?= icon('video', 14) ?>會議場次</div></div>
    <div class="stat-card"><div class="stat-num" style="font-size:22px;"><?= htmlspecialchars($meet_count ? fmt_dur($meet_avg) : '—') ?></div><div class="stat-label"><?= icon('clock', 14) ?>平均時長</div></div>
    <div class="stat-card"><div class="stat-num" style="font-size:22px;"><?= htmlspecialchars($meet_count ? fmt_dur($meet_total) : '—') ?></div><div class="stat-label"><?= icon('clock', 14) ?>會議總時長</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int)$active_rooms ?></div><div class="stat-label"><?= icon('home', 14) ?>活躍會議室</div></div>
  </div>

  <!-- 歷史 MAU 趨勢 -->
  <div class="card">
    <div class="card-title"><?= icon('chart', 16) ?>歷史 MAU 趨勢（依計費週期）</div>
    <?php if (count($histData) > 0): ?>
      <div class="chart-wrap"><canvas id="histChart"></canvas></div>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin:4px 0 0;">尚無歷史資料，開始計量後此處會顯示各週期的 MAU 趨勢。</p>
    <?php endif; ?>
  </div>

  <!-- 近 30 天活動 -->
  <div class="card">
    <div class="card-title"><?= icon('chart', 16) ?>近 30 天活動</div>
    <div class="chart-wrap"><canvas id="dailyChart"></canvas></div>
  </div>

  <!-- 本期會議時長時間軸 -->
  <div class="card">
    <div class="card-title"><?= icon('clock', 16) ?>本期會議時長時間軸
      <span class="muted" style="font-weight:400;font-size:12px;margin-left:8px;">共 <?= (int)$meet_count ?> 場 · 總時長 <?= htmlspecialchars(fmt_dur($meet_total)) ?></span>
    </div>
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
          $tip = $room . '　' . date('m/d H:i', $st) . ' ~ ' . date('H:i', (int)($s['end'] ?? $st)) . '　(' . fmt_dur($dur) . ')';
        ?>
          <div class="gantt-row">
            <div class="gantt-label" title="<?= htmlspecialchars($room) ?>"><?= htmlspecialchars($room) ?></div>
            <div class="gantt-track">
              <div class="gantt-bar" style="left:<?= $left ?>%;width:<?= $w ?>%;" title="<?= htmlspecialchars($tip) ?>"></div>
            </div>
            <div class="gantt-dur"><?= htmlspecialchars(fmt_dur($dur)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin:4px 0 0;">本期尚無會議時長記錄。會議結束（主持人離開）後即會在此累積各場次的長度。</p>
    <?php endif; ?>
  </div>

<script>
(function () {
  if (!window.Chart) return;
  const grid = getComputedStyle(document.body).getPropertyValue('color');
  const histData = <?= json_encode($histData) ?>;
  const histLabels = <?= json_encode($histLabels) ?>;
  if (document.getElementById('histChart') && histData.length) {
    new Chart(document.getElementById('histChart'), {
      type: 'bar',
      data: { labels: histLabels, datasets: [{ label: 'MAU', data: histData, backgroundColor: '#7c3aed', borderRadius: 6, maxBarThickness: 48 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                 scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
  }
  new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: { labels: <?= json_encode($dailyLabels) ?>, datasets: [
      { label: '建立會議室', data: <?= json_encode($dailyCreate) ?>, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,.12)', tension: .3, fill: true },
      { label: '主持進入',  data: <?= json_encode($dailyEnter) ?>,  borderColor: '#06b6d4', backgroundColor: 'rgba(6,182,212,.12)', tension: .3, fill: true },
      { label: '來賓進入',  data: <?= json_encode($dailyGuest) ?>,  borderColor: '#ec4899', backgroundColor: 'rgba(236,72,153,.12)', tension: .3, fill: true }
    ] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
               plugins: { legend: { position: 'bottom' } },
               scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });
})();
</script>
</main>
<?php render_foot(); ?>
