<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/jaas.php';
require_once __DIR__ . '/lib/rooms.php';

Auth::requireLogin();
if (empty($_SESSION['jwt']) || empty($_SESSION['room'])) {
  header('Location: /dashboard');
  exit;
}

$jwt = $_SESSION['jwt'];
$room = $_SESSION['room'];
$lobby_on = !empty(Rooms::get($room)['lobby']);   // 大廳模式：主持人進場後自動開啟
$mui = Settings::resolveMeetingUi();               // 會議室自訂（logo / 進入預設 / 工具列）
$invite_url = SITE_URL . '/room/' . rawurlencode($room);
$theme = Settings::getTheme();
$body_class = 'in-meeting theme-' . $theme . (Settings::isDark($theme) ? ' is-dark' : '');
?>
<!DOCTYPE html>
<html lang="zh-TW" class="in-meeting">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($room) ?> · 主持會議</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
  <script src="<?= htmlspecialchars(Jaas::scriptUrl()) ?>" async></script>
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body class="<?= htmlspecialchars($body_class) ?>">
  <div class="meeting-shell">
    <div id="jaas-container"></div>
  </div>

  <button class="share-fab" id="shareBtn" title="分享邀請連結" aria-label="分享邀請連結">
    <?= icon('share', 22) ?>
  </button>

  <div class="modal-backdrop" id="shareModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="shareTitle">
      <h2 id="shareTitle">邀請來賓加入</h2>
      <p class="modal-sub">會議室：<strong><?= htmlspecialchars($room) ?></strong></p>
      <div class="qr" id="qrcode"></div>
      <div class="link-row">
        <input type="text" id="inviteLink" readonly value="<?= htmlspecialchars($invite_url) ?>">
        <button type="button" class="btn btn-primary" id="copyLink"><?= icon('copy', 14) ?>複製</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="closeShare"><?= icon('x', 14) ?>關閉</button>
      </div>
    </div>
  </div>

  <div id="flash" class="copy-flash"><?= icon('check', 14) ?>已複製邀請連結</div>

<script>
window.addEventListener('load', () => {
  const inviteUrl = <?= json_encode($invite_url) ?>;
  const room = <?= json_encode($room) ?>;

  const options = {
    roomName: <?= json_encode(Jaas::roomName($room)) ?>,
    parentNode: document.querySelector('#jaas-container'),
    lang: <?= json_encode(Settings::getMeetingLang()) ?>,
<?php if ($jwt !== ''): ?>    jwt: <?= json_encode($jwt) ?>,
<?php endif; ?>
    configOverwrite: {
      defaultLanguage: <?= json_encode(Settings::getMeetingLang()) ?>,
      disableDeepLinking: true,
      videoQuality: {
        codecPreferenceOrder: ['VP9', 'H264', 'VP8', 'AV1'],
        mobileCodecPreferenceOrder: ['VP9', 'H264', 'VP8', 'AV1'],
        enableAdaptiveMode: true
      },
<?php if ($mui['logo_url'] !== ''): ?>      defaultLogoUrl: <?= json_encode($mui['logo_url']) ?>,
<?php endif; ?>      enableLobby: true,
      startWithAudioMuted: <?= $mui['mute_audio'] ? 'true' : 'false' ?>,
      startWithVideoMuted: <?= $mui['mute_video'] ? 'true' : 'false' ?>,
      resolution: <?= (int)$mui['resolution'] ?>,
      fileRecordingsEnabled: true,
      fileRecordingsServiceEnabled: true,
      recordingService: { enabled: true, sharingEnabled: false },
      // 停用即時逐字稿與直播串流（避免額外計費）
      transcribingEnabled: false,
      transcription: { enabled: false, autoCaptionOnRecord: false },
      liveStreamingEnabled: false,
      liveStreaming: { enabled: false },
      desktopSharingFrameRate: { min: 15, max: 30 },
      constraints: { video: { height: { ideal: 1080, max: 1080, min: 720 } } },
      toolbarButtons: <?= json_encode($mui['toolbar']) ?>
    },
    interfaceConfigOverwrite: {
      LANG_DETECTION: false,
      INVITE_URL: inviteUrl,
      DEFAULT_REMOTE_DISPLAY_NAME: <?= json_encode(Settings::getRecorderName()) ?>,
      SHOW_JITSI_WATERMARK: <?= $mui['logo_url'] !== '' ? 'true' : 'false' ?>,
<?php if ($mui['logo_url'] !== ''): ?>      DEFAULT_LOGO_URL: <?= json_encode($mui['logo_url']) ?>,
      DEFAULT_WELCOME_PAGE_LOGO_URL: <?= json_encode($mui['logo_url']) ?>,
      JITSI_WATERMARK_LINK: <?= json_encode($mui['logo_link']) ?>,
<?php endif; ?>
    }
  };
  const api = new JitsiMeetExternalAPI(<?= json_encode(Jaas::apiDomain()) ?>, options);
  api.addEventListener('readyToClose', () => { window.location.href = '/leave'; });
<?php if ($lobby_on || $mui['default_view'] === 'tile'): ?>
  let _localId = null;
<?php if ($lobby_on): ?>
  // 大廳只有 moderator 能開；toggleLobby(true) 是「設為開」(冪等)，重複呼叫安全。
  // moderator 角色在進場後才授予(可能數秒)，故持續重試直到角色確認或逾時。
  const enableLobby = () => { try { api.executeCommand('toggleLobby', true); } catch (e) {} };
  let _lobbyTimer = null;
  const startLobbyAuto = () => {
    if (_lobbyTimer) return;
    let n = 0;
    enableLobby();
    _lobbyTimer = setInterval(() => { enableLobby(); if (++n >= 10) { clearInterval(_lobbyTimer); _lobbyTimer = null; } }, 1500);
  };
  const stopLobbyAuto = () => { if (_lobbyTimer) { clearInterval(_lobbyTimer); _lobbyTimer = null; } };
  api.addEventListener('participantRoleChanged', (e) => {
    if (e && e.id === _localId && e.role === 'moderator') { enableLobby(); stopLobbyAuto(); }
  });
<?php endif; ?>
  api.addEventListener('videoConferenceJoined', (e) => {
    _localId = e && e.id;
<?php if ($mui['default_view'] === 'tile'): ?>
    try { api.executeCommand('setTileView', true); } catch (e) {}   // 預設畫廊檢視
<?php endif; ?>
<?php if ($lobby_on): ?>
    startLobbyAuto();   // 持續重試開大廳，直到取得 moderator 後生效
<?php endif; ?>
  });
<?php endif; ?>

  // === 主持人心跳：每 15 秒回報，遠端據此判斷主持人是否還在 ===
  const heartbeatUrl = '/host-heartbeat?room=' + encodeURIComponent(room);
  const beat = () => { fetch(heartbeatUrl, { method: 'POST', cache: 'no-store', keepalive: true }).catch(() => {}); };
  beat();
  setInterval(beat, 15000);

  // === 關閉分頁 / 切離時用 beacon 通知離開（即使 readyToClose 沒有觸發） ===
  const leaveUrl = '/host-left?room=' + encodeURIComponent(room);
  const sendLeft = () => {
    if (navigator.sendBeacon) navigator.sendBeacon(leaveUrl);
    else fetch(leaveUrl, { method: 'POST', cache: 'no-store', keepalive: true }).catch(() => {});
  };
  window.addEventListener('pagehide', sendLeft);
  window.addEventListener('beforeunload', sendLeft);

  new QRCode(document.getElementById('qrcode'), {
    text: inviteUrl, width: 192, height: 192,
    colorDark: '#18181b', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });

  const modal = document.getElementById('shareModal');
  const open = () => modal.classList.add('open');
  const close = () => modal.classList.remove('open');
  document.getElementById('shareBtn').onclick = open;
  document.getElementById('closeShare').onclick = close;
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });

  const flash = document.getElementById('flash');
  const showFlash = () => {
    flash.classList.add('show');
    clearTimeout(window.__ft);
    window.__ft = setTimeout(() => flash.classList.remove('show'), 1400);
  };
  document.getElementById('copyLink').onclick = async () => {
    const v = document.getElementById('inviteLink').value;
    try { await navigator.clipboard.writeText(v); }
    catch (_) {
      const i = document.getElementById('inviteLink');
      i.select(); document.execCommand('copy');
    }
    showFlash();
  };
});
</script>
</body>
</html>
