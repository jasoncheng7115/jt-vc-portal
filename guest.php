<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/jaas.php';
require_once __DIR__ . '/lib/audit.php';

Auth::start();
$room = $_SESSION['room'] ?? null;

if (empty($_SESSION['invited']) || !$room) {
  render_head('需要邀請連結');
  render_topbar(false);
  ?>
  <main class="container narrow">
    <div class="card" style="text-align:center;">
      <h1>需要邀請連結</h1>
      <p class="subtitle">請使用主持人提供的邀請連結進入會議室。</p>
    </div>
  </main>
  <?php
  render_foot();
  exit;
}

/** 清洗來賓自填名字：去控制字元、壓空白、限長。 */
function guest_clean_name(string $s): string {
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
  $s = preg_replace('/\s+/u', ' ', trim($s));
  return mb_substr($s, 0, 40);
}
/** 用來賓自填名字簽發 JWT（依模式 JaaS/自建）。 */
function build_guest_jwt(string $room, string $name, string $gid): string {
  return Jaas::makeJwt($room, [
    'name'      => $name,
    'id'        => $gid . '@guest',
    'moderator' => false,
  ]);
}

$data = Rooms::get($room);
if ($data === null) {
  // 房間沒被建立過 → 視為 wait_host
  $eval = ['allow' => false, 'status' => 'wait_host', 'starts_at' => null, 'ends_at' => null, 'host_joined' => false];
} else {
  $eval = Rooms::evaluate($data);
}

// 允許 → 先確認來賓已輸入名字，否則顯示輸入表單
if ($eval['allow']) {
  $name_err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_name'])) {
    $n = guest_clean_name((string)$_POST['guest_name']);
    if ($n === '') {
      $name_err = '請輸入您的名稱。';
    } else {
      $_SESSION['guest_name'] = $n;
      $_SESSION['guest_jwt'] = build_guest_jwt($room, $n, $_SESSION['guest_id'] ?? 'guest'); // 自建無 JWT 模式可能為 ''
      $_SESSION['guest_ready'] = true;
      Audit::log('guest_join', "會議室「{$room}」", ['actor' => $n, 'actor_name' => $n, 'role' => 'guest']);
    }
  }

  // 尚未輸入名字 → 顯示輸入頁（用 guest_ready 判斷，jwt 在自建模式可能合法為空）
  if (empty($_SESSION['guest_ready'])) {
    render_head('輸入名稱');
    ?>
    <div class="page">
      <header class="topbar">
        <a class="brand" href="#">
          <img class="brand-logo" src="<?= htmlspecialchars(site_brand()['logo_src']) ?>" alt="<?= htmlspecialchars(site_brand()['brand_name']) ?>" width="32" height="32">
          <span class="brand-text"><?= htmlspecialchars(site_brand()['brand_name']) ?></span>
        </a>
      </header>
      <main class="center-stage">
        <div class="stage-card" style="max-width:420px;">
          <div class="room-tag"><?= icon('door-out', 12) ?><?= htmlspecialchars($room) ?></div>
          <h1>請輸入您的名稱</h1>
          <p class="muted" style="margin:6px 0 18px;">這個名稱會顯示給會議室裡的其他人。</p>
          <?php if ($name_err): ?>
            <div class="alert alert-error" style="text-align:left;"><?= icon('warning') ?><span><?= htmlspecialchars($name_err) ?></span></div>
          <?php endif; ?>
          <form method="POST" action="/guest">
            <div class="field" style="text-align:left;">
              <label for="guest_name">您的名稱</label>
              <input type="text" id="guest_name" name="guest_name" required autofocus
                     maxlength="40" placeholder="請輸入您的名稱"
                     value="">
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?= icon('arrow-right') ?>加入會議</button>
          </form>
        </div>
      </main>
    </div>
    <?php
    render_foot();
    exit;
  }

  $jwt = $_SESSION['guest_jwt'];
  $theme = Settings::getTheme();
  $body_class = 'in-meeting theme-' . $theme . (Settings::isDark($theme) ? ' is-dark' : '');
  ?>
<!DOCTYPE html>
<html lang="zh-TW" class="in-meeting">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($room) ?> · 來賓加入</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/style.css">
  <script src="<?= htmlspecialchars(Jaas::scriptUrl()) ?>" async></script>
</head>
<body class="<?= htmlspecialchars($body_class) ?>">
  <div class="meeting-shell">
    <div id="jaas-container"></div>
  </div>
<script>
window.addEventListener('load', () => {
  const options = {
    roomName: <?= json_encode(Jaas::roomName($room)) ?>,
    parentNode: document.querySelector('#jaas-container'),
    lang: <?= json_encode(Settings::getMeetingLang()) ?>,
    userInfo: { displayName: <?= json_encode($_SESSION['guest_name']) ?> },
<?php if ($jwt !== ''): ?>    jwt: <?= json_encode($jwt) ?>,
<?php endif; ?>
    configOverwrite: {
      defaultLanguage: <?= json_encode(Settings::getMeetingLang()) ?>,
      defaultLogoUrl: <?= json_encode(SITE_URL . '/logo') ?>,
      enableLobby: true,
      prejoinPageEnabled: false,
      prejoinConfig: { enabled: false },
      startWithAudioMuted: true,
      startWithVideoMuted: true,
      resolution: 1080,
      fileRecordingsEnabled: true,
      fileRecordingsServiceEnabled: true,
      transcribingEnabled: false,
      transcription: { enabled: false, autoCaptionOnRecord: false },
      liveStreamingEnabled: false,
      liveStreaming: { enabled: false },
      desktopSharingFrameRate: { min: 15, max: 30 },
      constraints: { video: { height: { ideal: 1080, max: 1080, min: 720 } } },
      toolbarButtons: [
        'camera','chat','desktop','download','embedmeeting','etherpad',
        'feedback','filmstrip','fullscreen','hangup','help','highlight','linktosalesforce',
        'microphone','mute-everyone','mute-video-everyone',
        'participants-pane','profile','raisehand','recording','security','select-background',
        'settings','shareaudio','sharedvideo','shortcuts','stats','tileview','toggle-camera','videoquality'
      ]
    },
    interfaceConfigOverwrite: {
      LANG_DETECTION: false,
      INVITE_URL: <?= json_encode(SITE_URL . '/room/' . rawurlencode($room)) ?>,
      DEFAULT_LOGO_URL: <?= json_encode(SITE_URL . '/logo') ?>,
      DEFAULT_WELCOME_PAGE_LOGO_URL: <?= json_encode(SITE_URL . '/logo') ?>
    }
  };
  const api = new JitsiMeetExternalAPI(<?= json_encode(Jaas::apiDomain()) ?>, options);
  api.addEventListener('readyToClose', () => { window.location.href = '/leave'; });
});
</script>
</body>
</html>
  <?php
  exit;
}

/* ===== 未開放：依狀態渲染對應頁面，並用 JS 輪詢 ===== */
$status = $eval['status'];
$starts_at = $eval['starts_at'];
$ends_at   = $eval['ends_at'];

render_head($status === 'countdown' ? '會議即將開始' : ($status === 'expired' ? '會議已結束' : '等候主持人'));
?>
<div class="page">
  <header class="topbar">
    <a class="brand" href="#">
      <img class="brand-logo" src="<?= htmlspecialchars(site_brand()['logo_src']) ?>" alt="<?= htmlspecialchars(site_brand()['brand_name']) ?>" width="32" height="32">
      <span class="brand-text"><?= htmlspecialchars(site_brand()['brand_name']) ?></span>
    </a>
  </header>
  <main class="center-stage">
    <div class="stage-card">
      <div class="room-tag"><?= icon('door-out', 12) ?><?= htmlspecialchars($room) ?></div>

      <?php if ($status === 'expired'): ?>
        <div class="icon-circle icon-circle-muted" style="margin-bottom:18px;">
          <?= icon('clock', 30) ?>
        </div>
        <h1>會議已結束</h1>
        <p class="muted" style="margin:6px 0 0;">這個會議室的開放時段已過。若需協助請聯絡主持人。</p>
        <?php if ($ends_at): ?>
          <p class="stage-note"><?= icon('calendar',14) ?> 結束於 <?= date('Y-m-d H:i', $ends_at) ?></p>
        <?php endif; ?>
        <div class="stage-actions">
          <button type="button" class="btn btn-secondary" id="retryNow"><?= icon('refresh', 14) ?>再試一次</button>
        </div>

      <?php elseif ($status === 'countdown'): ?>
        <h1>會議即將開始</h1>
        <p class="muted" style="margin:6px 0 0;">會議將於下方時間開放進入。</p>
        <div class="countdown" id="cd">
          <?php foreach (['cd-d'=>'天','cd-h'=>'時','cd-m'=>'分','cd-s'=>'秒'] as $cid=>$lbl): ?>
            <div class="cell">
              <div class="flip" id="<?= $cid ?>" data-val="--">
                <div class="flip-top"><span>--</span></div>
                <div class="flip-bottom"><span>--</span></div>
                <div class="flip-fold"><span>--</span></div>
                <div class="flip-unfold"><span>--</span></div>
              </div>
              <span class="unit"><?= $lbl ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="stage-note"><?= icon('calendar',14) ?> 開放時間 <?= date('Y-m-d H:i', $starts_at) ?><?= $ends_at ? ' ～ ' . date(date('Y-m-d', $starts_at)===date('Y-m-d', $ends_at)?'H:i':'Y-m-d H:i', $ends_at) : '' ?></p>
        <p class="stage-note"><?= icon('info',14) ?> 若主持人提前進入，您將自動加入會議</p>
        <div class="stage-actions">
          <button type="button" class="btn btn-secondary" id="retryNow"><?= icon('refresh', 14) ?>立即重試</button>
        </div>

      <?php else: /* wait_host */ ?>
        <div class="spinner"></div>
        <h1>等候主持人開啟會議室</h1>
        <p class="muted" style="margin:6px 0 0;">系統將每 60 秒自動檢查，主持人進入後您會自動加入。</p>
        <div class="stage-actions">
          <button type="button" class="btn btn-secondary" id="retryNow"><?= icon('refresh', 14) ?>立即重試</button>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
  const ROOM = <?= json_encode($room) ?>;
  const STATUS = <?= json_encode($status) ?>;
  const POLL_MS = 60000;
  let startsAt  = <?= $starts_at !== null ? (int)$starts_at : 'null' ?>;
  let endsAt    = <?= $ends_at   !== null ? (int)$ends_at   : 'null' ?>;
  let serverNow = <?= time() ?>;
  let localBase = Date.now();

  const now = () => Math.floor((serverNow * 1000 + (Date.now() - localBase)) / 1000);

  function setFlip(id, newVal) {
    const flip = document.getElementById(id);
    if (!flip) return;
    const oldVal = flip.dataset.val;
    if (oldVal === newVal) return;
    flip.dataset.val = newVal;
    flip.querySelector('.flip-fold span').textContent   = oldVal;
    flip.querySelector('.flip-unfold span').textContent = newVal;
    flip.classList.remove('flipping');
    void flip.offsetWidth; // force reflow 重置動畫
    flip.classList.add('flipping');
    // 在 fold 動畫剛結束、unfold 即將從 90deg 邊緣旋下的瞬間，
    // 把上下兩個 static 都換成新值。
    // 此時 unfold 是邊緣朝向螢幕（看不見），下方 static 也是新值，
    // 之後 unfold 翻平的整段過程兩者一致，不會看到舊值穿幫。
    setTimeout(() => {
      flip.querySelector('.flip-top span').textContent = newVal;
      flip.querySelector('.flip-bottom span').textContent = newVal;
    }, 400);
    // unfold 動畫完整 0.8s 後解除狀態
    setTimeout(() => { flip.classList.remove('flipping'); }, 850);
  }
  function renderCountdown() {
    if (STATUS !== 'countdown' || startsAt === null) return;
    const diff = Math.max(0, startsAt - now());
    const d = Math.floor(diff / 86400);
    const h = Math.floor((diff % 86400) / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;
    const pad = (n) => String(n).padStart(2, '0');
    setFlip('cd-d', pad(d));
    setFlip('cd-h', pad(h));
    setFlip('cd-m', pad(m));
    setFlip('cd-s', pad(s));
    if (diff === 0) location.reload();
  }
  if (STATUS === 'countdown') { setInterval(renderCountdown, 1000); renderCountdown(); }

  async function poll() {
    try {
      const r = await fetch('/room-status?room=' + encodeURIComponent(ROOM), { cache: 'no-store' });
      const d = await r.json();
      if (!d || !d.ok) return;
      serverNow = d.server_time;
      localBase = Date.now();
      if (d.allow) { location.reload(); return; }
      // 狀態切換（例如從 countdown → expired）→ 重整顯示對應頁
      const expected = <?= json_encode($status) ?>;
      if (d.status !== expected) { location.reload(); return; }
      if (d.starts_at !== startsAt || d.ends_at !== endsAt) {
        startsAt = d.starts_at; endsAt = d.ends_at;
      }
    } catch (e) { /* 安靜失敗，下次再試 */ }
  }
  setInterval(poll, POLL_MS);

  document.getElementById('retryNow')?.addEventListener('click', () => location.reload());
</script>
<?php render_foot(); ?>
