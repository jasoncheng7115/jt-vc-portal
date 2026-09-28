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
$lobby_on = !empty(Rooms::get($room)['lobby'] ?? false);   // 大廳模式：主持人進場後自動開啟
$mui = Settings::resolveMeetingUi();               // 會議室自訂（logo / 進入預設 / 工具列）
$invite_url = SITE_URL . '/room/' . rawurlencode($room);
$theme = Settings::getTheme();
$body_class = 'in-meeting theme-' . $theme . (Settings::isDark($theme) ? ' is-dark' : '');
send_meeting_csp();   // 會議頁 CSP（iframe 只允許 Jitsi 網域）
?>
<!DOCTYPE html>
<html lang="<?= I18n::htmlLang() ?>" class="in-meeting">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= th('{room} · 主持會議', ['room' => $room]) ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
  <script <?= nonce_attr() ?> src="<?= htmlspecialchars(Jaas::scriptUrl()) ?>" integrity="<?= htmlspecialchars(Jaas::scriptSri()) ?>"></script>
  <script <?= nonce_attr() ?> src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
</head>
<body class="<?= htmlspecialchars($body_class) ?>">
  <div class="meeting-shell">
    <div id="jaas-container"></div>
  </div>

  <button class="share-fab" id="shareBtn" title="<?= th('分享邀請連結') ?>" aria-label="<?= th('分享邀請連結') ?>">
    <?= icon('share', 22) ?>
  </button>

  <div class="modal-backdrop" id="shareModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="shareTitle">
      <h2 id="shareTitle"><?= th('邀請來賓加入') ?></h2>
      <p class="modal-sub"><?= th('會議室：') ?><strong><?= htmlspecialchars($room) ?></strong></p>
      <div class="qr" id="qrcode"></div>
      <div class="link-row">
        <input type="text" id="inviteLink" readonly value="<?= htmlspecialchars($invite_url) ?>">
        <button type="button" class="btn btn-primary" id="copyLink"><?= icon('copy', 14) ?><?= th('複製') ?></button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="closeShare"><?= icon('x', 14) ?><?= th('關閉') ?></button>
      </div>
    </div>
  </div>

  <div id="flash" class="copy-flash"><?= icon('check', 14) ?><?= th('已複製邀請連結') ?></div>
  <div id="recToast" class="copy-flash"></div>

<script <?= nonce_attr() ?>>
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
<?php if (Settings::getJaas()['mode'] === 'selfhosted'): ?>      hiddenDomain: 'hidden.meet.jitsi',   // hide the Jibri recorder (its login domain) from the participant list
<?php endif; ?>
      videoQuality: {
        codecPreferenceOrder: ['VP9', 'H264', 'VP8', 'AV1'],
        mobileCodecPreferenceOrder: ['VP9', 'H264', 'VP8', 'AV1'],
        enableAdaptiveMode: <?= $mui['bw_save_off'] ? 'false' : 'true' ?>   // false when the room option "disable video bandwidth saving" is checked
      },
<?php if ($mui['bw_save_off']): ?>      channelLastN: -1,   // receive everyone's video, never dropped by lastN (disabled together with bandwidth saving)
<?php endif; ?>      enableLobby: true,
      startWithAudioMuted: <?= $mui['mute_audio'] ? 'true' : 'false' ?>,
      startWithVideoMuted: <?= $mui['mute_video'] ? 'true' : 'false' ?>,
      resolution: <?= (int)$mui['resolution'] ?>,
      fileRecordingsEnabled: true,
      fileRecordingsServiceEnabled: true,
      recordingService: { enabled: true, sharingEnabled: false },
      // disable live transcription and live streaming (avoid extra charges)
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
      DEFAULT_REMOTE_DISPLAY_NAME: <?= json_encode(Settings::getRecorderName()) ?>
    }
  };
  const api = new JitsiMeetExternalAPI(<?= json_encode(Jaas::apiDomain()) ?>, options);
  api.addEventListener('readyToClose', () => { window.location.href = '/leave'; });

  // Recording status toast (works around Jitsi's menu label not refreshing after stop; gives clear feedback)
  let _recOn = null;
  const showRecToast = (msg) => {
    const t = document.getElementById('recToast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(window.__rt);
    window.__rt = setTimeout(() => t.classList.remove('show'), 2600);
  };
  api.addEventListener('recordingStatusChanged', (e) => {
    if (!e) return;
    if (e.mode && e.mode !== 'file') return;   // file recording (Jibri) only; ignore streaming/transcription
    const on = !!e.on;
    if (_recOn === on) return;                  // de-duplicate
    _recOn = on;
    showRecToast(on ? <?= json_encode(t('錄影已開始')) ?> : <?= json_encode(t('錄影已停止')) ?>);
  });
<?php if ($lobby_on || $mui['default_view'] === 'tile'): ?>
  let _localId = null;
<?php if ($lobby_on): ?>
  // Only a moderator can enable the lobby; toggleLobby(true) means "set on" (idempotent), safe to repeat.
  // The moderator role is granted after joining (may take seconds), so keep retrying until confirmed or timed out.
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
    try { api.executeCommand('setTileView', true); } catch (e) {}   // default to tile view
<?php endif; ?>
<?php if ($lobby_on): ?>
    startLobbyAuto();   // keep retrying to enable the lobby until moderator is granted
<?php endif; ?>
  });
<?php endif; ?>

  // === Participant roster (for peak concurrency / participant timeline stats) ===
  // The hidden recorder (hiddenDomain) does not fire participantJoined, so it is not counted.
  const roster = {};            // id -> {name, in, out}
  const nowSec = () => Math.floor(Date.now() / 1000);
  const rosterArr = () => Object.keys(roster).map((k) => roster[k]);
  api.addEventListener('videoConferenceJoined', (e) => { if (e && e.id) roster[e.id] = { name: e.displayName || <?= json_encode(t('我')) ?>, in: nowSec(), out: null }; });
  api.addEventListener('participantJoined', (e) => { if (e && e.id) roster[e.id] = { name: e.displayName || '', in: nowSec(), out: null }; });
  api.addEventListener('participantLeft', (e) => { if (e && e.id && roster[e.id]) roster[e.id].out = nowSec(); });
  api.addEventListener('displayNameChange', (e) => { if (e && e.id && roster[e.id]) roster[e.id].name = e.displayname || e.displayName || roster[e.id].name; });

  // === Dominant-speaker timeline (v1.13.0): who is talking when, from Jitsi's own speaker detection.
  // Used after the meeting to suggest which transcript speaker (S1, S2…) is which participant. Names are
  // resolved when sending, so later display-name changes still apply.
  const talk = [];              // {id, s, e} in ms
  api.addEventListener('dominantSpeakerChanged', (e) => {
    if (!e || !e.id) return;
    const t = Date.now(), last = talk[talk.length - 1];
    if (last && last.id === e.id && last.e === null) return;
    if (last && last.e === null) last.e = t;
    talk.push({ id: e.id, s: t, e: null });
    if (talk.length > 5000) talk.shift();
  });
  const talkArr = () => talk.map((x) => ({ n: (roster[x.id] && roster[x.id].name) || '', s: x.s, e: x.e })).filter((x) => x.n);

  // === Host heartbeat: report every 15 s so the server knows the host is present (with a roster snapshot) ===
  const csrf = <?= json_encode(Auth::csrfToken()) ?>;
  const heartbeatUrl = '/host-heartbeat?room=' + encodeURIComponent(room);
  const beat = () => {
    fetch(heartbeatUrl, {
      method: 'POST', cache: 'no-store', keepalive: true, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ roster: rosterArr(), talk: talkArr() })
    }).catch(() => {});
  };
  beat();
  setInterval(beat, 15000);

  // === Notify leave via beacon on tab close / navigation (even if readyToClose did not fire) ===
  const leaveUrl = '/host-left';
  const sendLeft = () => {
    const fd = new FormData();
    fd.append('room', room);
    fd.append('_csrf', csrf);
    if (navigator.sendBeacon) navigator.sendBeacon(leaveUrl, fd);
    else fetch(leaveUrl, { method: 'POST', body: fd, cache: 'no-store', keepalive: true, credentials: 'same-origin' }).catch(() => {});
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
