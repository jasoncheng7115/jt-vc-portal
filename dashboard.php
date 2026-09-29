<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/transcripts.php';

$me = Auth::requireLogin();

/** 「進入 / 立即主持」按鈕：以 POST + CSRF 送出（A01：避免跨站 GET 觸發主持人進場）。 */
function enter_room_form(string $room, string $labelHtml, string $cls): string {
  return '<form method="POST" action="/start" style="display:inline;">' . Auth::csrfField()
       . '<input type="hidden" name="room" value="' . htmlspecialchars($room) . '">'
       . '<input type="hidden" name="mode" value="host"><input type="hidden" name="enter" value="1">'
       . '<button type="submit" class="' . htmlspecialchars($cls) . '">' . $labelHtml . '</button></form>';
}
$ip = Auth::clientIp();
$is_admin = ($me['role'] ?? '') === 'admin';
$jaas_mode = Settings::getJaas()['mode'];   // jaas | selfhosted（自建不顯示 8x8 用量）

$all_rooms = Rooms::pruneAndGet();
// 角色過濾：admin 看全部、host 只看自己建的
$rooms = $is_admin ? $all_rooms : array_filter($all_rooms, fn($r) => ($r['owner'] ?? null) === $me['id']);
uasort($rooms, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

// 各會議室的進入 / 時長彙整（自最早建立時間起讀取已結束 session）
$meet_agg = [];
if (!empty($rooms)) {
  $oldest = min(array_map(fn($r) => (int)($r['created_at'] ?? time()), $rooms));
  foreach (Rooms::meetingSessions($oldest - 3600, time()) as $sess) {
    $rn = (string)($sess['room'] ?? '');
    if ($rn === '') continue;
    if (!isset($meet_agg[$rn])) $meet_agg[$rn] = ['dur' => 0, 'first' => null, 'count' => 0];
    $meet_agg[$rn]['dur']   += (int)($sess['dur'] ?? 0);
    $meet_agg[$rn]['count'] += 1;
    $st = (int)($sess['start'] ?? 0);
    if ($meet_agg[$rn]['first'] === null || $st < $meet_agg[$rn]['first']) $meet_agg[$rn]['first'] = $st;
  }
}

$error = $_SESSION['room_error'] ?? '';
$room_msg = $_SESSION['room_msg'] ?? '';
unset($_SESSION['room_error'], $_SESSION['room_msg']);
$form_values = $_SESSION['form_values'] ?? [];
unset($_SESSION['form_values']);
$form_room      = (string)($form_values['room']      ?? ($_GET['room'] ?? ''));
$form_starts_at = (string)($form_values['starts_at'] ?? '');
$form_ends_at   = (string)($form_values['ends_at']   ?? '');
$form_attendees = (string)($form_values['attendees'] ?? '');
$form_lobby     = !empty($form_values) ? !empty($form_values['lobby']) : true;  // 新表單預設啟用大廳模式
$tx_can         = Settings::transcribeReady() && Transcripts::canUse($me);
$form_tx        = !empty($form_values) ? !empty($form_values['transcribe']) : (Transcripts::perm($me) === 'auto');  // 預設跟帳號權限
$schedule_open  = ($form_starts_at !== '' || $form_ends_at !== '' || $form_attendees !== '');

$created = $_GET['created'] ?? null;
$created_data = null;
if ($created) {
  $created = Rooms::sanitize($created);
  $created_data = $all_rooms[$created] ?? null;
}
$mail_flash = $_GET['mail'] ?? '';

function fmt_when(int $ts): string {
  $diff = time() - $ts;
  if ($diff < 60) return t('{n} 秒前', ['n' => $diff]);
  if ($diff < 3600) return t('{n} 分鐘前', ['n' => floor($diff / 60)]);
  if ($diff < 86400) return t('{n} 小時前', ['n' => floor($diff / 3600)]);
  return t('{n} 天前', ['n' => floor($diff / 86400)]);
}
function fmt_range(?int $s, ?int $e): string {
  if ($s === null) return '';
  $sameday = $e !== null && date('Y-m-d', $s) === date('Y-m-d', $e);
  $left  = date('m/d H:i', $s);
  $right = $e === null ? t('不限') : ($sameday ? date('H:i', $e) : date('m/d H:i', $e));
  return t('{start} ～ {end}', ['start' => $left, 'end' => $right]);
}
function fmt_dur_s(int $s): string {
  if ($s < 60) return t('{n} 秒', ['n' => $s]);
  $m = intdiv($s, 60);
  if ($m < 60) return t('{n} 分', ['n' => $m]);
  $h = intdiv($m, 60); $mm = $m % 60;
  return $mm ? t('{h} 時 {m} 分', ['h' => $h, 'm' => $mm]) : t('{h} 時', ['h' => $h]);
}
function mail_flash_text(string $m): string {
  if ($m === 'smtp_off') return t('（SMTP 未啟用，邀請信未寄出，僅建立連結）');
  if (preg_match('/sent_(\d+)_fail_(\d+)/', $m, $x)) {
    return $x[2] > 0
      ? t('（邀請信：成功 {ok} 封、失敗 {fail} 封）', ['ok' => $x[1], 'fail' => $x[2]])
      : t('（邀請信：成功 {ok} 封）', ['ok' => $x[1]]);
  }
  return '';
}

render_head(t('儀表板'));
render_topbar($me, $ip);
?>
<main class="container">
  <script <?= nonce_attr() ?> src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">
  <script <?= nonce_attr() ?> src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
  <script <?= nonce_attr() ?> src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/zh-tw.js" integrity="sha384-mjJeOdHLBw1XvGBUqn+UxU0xEtQSbR1nG/0o9getG2BM2o6lfJvCwTjPwyvzKrOY" crossorigin="anonymous"></script>

  <?php if ($is_admin || Settings::hasJibri()): ?><?= admin_nav('dashboard') ?><?php endif; ?>
  <?php if ($is_admin): /* 管理員：進來就檢查 Jitsi Meet 與 Jibri 是否健康（v1.16.0） */ ?>
  <div class="sys-health" id="sysHealth" aria-live="polite">
    <span class="sh-title"><?= icon('info', 14) ?><?= th('系統狀態') ?></span>
    <span class="sh-item" data-k="jitsi"><span class="sh-dot"></span><b>Jitsi Meet</b><span class="sh-msg"><?= th('檢查中…') ?></span></span>
    <?php if (Settings::hasJibri()): ?><span class="sh-item" data-k="jibri"><span class="sh-dot"></span><b><?= th('Jibri 錄影') ?></b><span class="sh-msg"><?= th('檢查中…') ?></span></span><?php endif; ?>
    <button type="button" class="btn btn-ghost btn-sm sh-refresh" id="shRefresh" title="<?= th('重新檢查') ?>"><?= icon('refresh', 14) ?></button>
  </div>
  <?php endif; ?>
  <?php if ($room_msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($room_msg) ?></span></div><?php endif; ?>

  <?php if ($created_data):
    $invite_url = SITE_URL . '/room/' . rawurlencode($created);
  ?>
  <div class="created-panel">
    <div class="panel-qr"><div id="createdQr"></div></div>
    <div class="panel-body">
      <div class="panel-title"><?= icon('check') ?> <?= th('會議室連結已建立') ?> <?= htmlspecialchars(mail_flash_text($mail_flash)) ?></div>
      <div class="muted" style="font-size:13px;margin-bottom:6px;">
        <?= th('會議室') ?> <strong style="font-family:var(--mono);color:var(--text);"><?= htmlspecialchars($created) ?></strong>
        <?php if ($created_data['starts_at']): ?> · <?= th('開放時段 {range}', ['range' => fmt_range($created_data['starts_at'], $created_data['ends_at'])]) ?><?php endif; ?>
        <?php if (!empty($created_data['lobby'])): ?> · <span style="color:var(--text);"><?= icon('lock', 12) ?><?= th('大廳模式') ?></span><?php endif; ?>
      </div>
      <div class="panel-link"><?= htmlspecialchars($invite_url) ?></div>
      <div class="panel-actions">
        <button type="button" class="btn btn-primary btn-sm" data-copy="<?= htmlspecialchars($invite_url) ?>"><?= icon('copy', 14) ?><?= th('複製邀請連結') ?></button>
        <?= enter_room_form($created, icon('play', 14) . th('立即主持'), 'btn btn-secondary btn-sm') ?>
      </div>
    </div>
    <div></div>
  </div>
  <script <?= nonce_attr() ?>>
    window.addEventListener('DOMContentLoaded', () => {
      if (window.QRCode) new QRCode(document.getElementById('createdQr'), {
        text: <?= json_encode($invite_url) ?>, width: 96, height: 96,
        colorDark: '#18181b', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M
      });
    });
  </script>
  <?php endif; ?>

  <div class="card">
    <h1><?= icon('video', 18) ?><?= th('建立 / 進入會議室') ?></h1>
    <p class="subtitle"><?= th('輸入會議室名稱即可開始，或從下方近期清單快速進入。可選擇先建立連結但暫不進入會議。') ?></p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" action="/start">
      <?= Auth::csrfField() ?>
      <div class="field">
        <label for="room"><?= th('會議室名稱') ?></label>
        <div class="link-row">
          <input type="text" id="room" name="room" required autofocus
                 placeholder="<?= th('例如：weekly-sync') ?>"
                 pattern="[A-Za-z0-9_\-]+" title="<?= th('僅限英文、數字、- 與 _') ?>"
                 value="<?= htmlspecialchars($form_room) ?>">
          <button type="button" class="btn btn-secondary" id="randomRoom" title="<?= th('亂數產生會議室名稱') ?>"><?= icon('refresh',14) ?><?= th('亂數') ?></button>
        </div>
        <div class="help"><?= t('僅限英文、數字、{dash}、{us}；空格自動轉 {dash}，中文等其他字元會自動移除（Jitsi 會議室不支援非 ASCII 名稱）。', ['dash' => '<span class="kbd">-</span>', 'us' => '<span class="kbd">_</span>']) ?></div>
        <div class="help"><?= icon('info', 12) ?> <?= th('會議室名稱就是邀請連結的一部分：容易猜到的名稱（例如 weekly）可能被他人猜中，建議用「亂數」產生，或勾選大廳模式由主持人逐一允許。') ?></div>
      </div>

      <div class="schedule-block">
      <label class="schedule-head" for="scheduleChk">
        <input type="checkbox" id="scheduleChk"<?= $schedule_open ? ' checked' : '' ?>>
        <span class="lobby-text">
          <span class="lobby-title"><?= icon('calendar', 14) ?><?= th('限定開放時段 / 寄送邀請') ?></span>
          <span class="help" style="margin:0;"><?= th('未勾選 = 來賓需等主持人開啟；勾選後可設定開放時段並寄送邀請信。') ?></span>
        </span>
      </label>
      <div id="scheduleBody" class="schedule-body"<?= $schedule_open ? '' : ' hidden' ?>>
        <div class="field-row">
          <div class="field" style="margin-bottom:0;">
            <label for="starts_at"><?= th('開始時間') ?></label>
            <input type="text" id="starts_at" name="starts_at" autocomplete="off" placeholder="<?= th('點選選擇日期時間') ?>" value="<?= htmlspecialchars($form_starts_at) ?>">
          </div>
          <div class="field" style="margin-bottom:0;">
            <label for="ends_at"><?= th('結束時間') ?></label>
            <input type="text" id="ends_at" name="ends_at" autocomplete="off" placeholder="<?= th('點選選擇日期時間') ?>" value="<?= htmlspecialchars($form_ends_at) ?>">
          </div>
        </div>
        <div class="field" style="margin:14px 0 0;">
          <label for="attendees"><?= th('與會者 Email（選填，可多筆，換行或逗號分隔）') ?></label>
          <textarea id="attendees" name="attendees" placeholder="alice@example.com, bob@example.com"><?= htmlspecialchars($form_attendees) ?></textarea>
          <div class="help"><?= th('填寫後系統會寄出含行事曆（.ics）的邀請信，對方可一鍵加入行事曆（需先於系統設定啟用 SMTP）。') ?></div>
        </div>
      </div>
      </div>

      <label class="lobby-toggle">
        <input type="checkbox" name="lobby" value="1"<?= $form_lobby ? ' checked' : '' ?>>
        <span class="lobby-text">
          <span class="lobby-title"><?= icon('lock', 14) ?><?= th('啟用大廳模式') ?></span>
          <span class="help" style="margin:0;"><?= th('主持人進入後自動開啟；之後每位來賓需經主持人允許才能進入會議室。') ?></span>
        </span>
      </label>

      <?php if ($tx_can): ?>
      <input type="hidden" name="transcribe_field" value="1">
      <div class="tx-box">
        <label class="lobby-toggle">
          <input type="checkbox" name="transcribe" id="txChk" value="1"<?= $form_tx ? ' checked' : '' ?>>
          <span class="lobby-text">
            <span class="lobby-title"><?= icon('file-text', 14) ?><?= th('錄影完成後產生逐字稿與摘要') ?></span>
            <span class="help" style="margin:0;"><?= th('這場會議有錄影時，錄影完成後自動交給語音服務產生逐字稿（含發言者）與會議摘要；之後可在「錄影記錄」查看。') ?></span>
          </span>
        </label>
        <div class="tx-lang-field" id="txLangBox"<?= $form_tx ? '' : ' hidden' ?>>
          <label for="txLang"><?= th('會議主要語言') ?> <span class="req">*</span></label>
          <select id="txLang" name="tx_lang"<?= $form_tx ? ' required' : '' ?>>
            <option value=""><?= th('請選擇') ?></option>
            <?php foreach (Transcripts::meetingLanguages() as $lv => $ll): ?>
              <option value="<?= $lv ?>"<?= ($form_values['tx_lang'] ?? '') === $lv ? ' selected' : '' ?>><?= htmlspecialchars($ll) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help"><?= th('語音服務依這裡選的語言挑選辨識模型；夾雜多種語言時，選講最多的那一種。會議中有台語（閩南語）就選台語：專用模型也聽得懂夾雜的華語，但逐字稿不會標出發言者，英文大多會被翻成中文；只偶爾一兩句台語的會議選中文為主即可。') ?></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="btn-group">
        <button type="submit" name="mode" value="host" class="btn btn-primary"><?= icon('play') ?><?= th('開始主持會議') ?></button>
        <button type="submit" name="mode" value="create" class="btn btn-secondary"><?= icon('link') ?><?= th('建立會議室連結') ?></button>
      </div>
    </form>
  </div>

  <div class="card">
    <h1><?= icon('clock', 18) ?><?= th('近期會議室') ?> <span class="muted" style="text-transform:none;letter-spacing:0;font-weight:400;font-size:13px;">· <?= th('近期 / 即將開始') ?><?= $is_admin ? th('（全部主持人）') : '' ?></span></h1>
    <?php if (empty($rooms)): ?>
      <div class="empty"><?= th('尚無近期會議室。建立後將會出現在這裡。') ?></div>
    <?php else: ?>
      <ul class="room-list">
        <?php foreach ($rooms as $name => $r):
          $invite = SITE_URL . '/room/' . rawurlencode($name);
          $now = time();
          $hj = Rooms::isHostPresent($r, $now);
          $host_was = !empty($r['host_joined']) && !$hj;
          $s = $r['starts_at']; $e = $r['ends_at'];
          if ($hj) $badge = '<span class="badge badge-success">'.icon('check',11).th('主持人在線上').'</span>';
          elseif ($host_was) $badge = '<span class="badge badge-muted">'.th('主持人離線').'</span>';
          elseif ($s !== null && $now < $s) $badge = '<span class="badge badge-accent">'.icon('clock',11).th('預約中').'</span>';
          elseif ($s !== null && $e !== null && $now > $e) $badge = '<span class="badge badge-muted">'.th('已結束').'</span>';
          elseif ($s !== null) $badge = '<span class="badge badge-warning">'.th('開放中').'</span>';
          else $badge = '<span class="badge">'.th('待主持人').'</span>';
        ?>
          <li>
            <div class="room-main">
              <span class="room-name"><?= htmlspecialchars($name) ?></span>
              <div class="room-meta">
                <?= $badge ?>
                <?php if (!empty($r['lobby'])): ?><span class="badge badge-accent"><?= icon('lock',11) ?><?= th('大廳模式') ?></span><?php endif; ?>
                <span><?= th('建立於 {when}', ['when' => fmt_when($r['created_at'])]) ?></span>
                <?php if ($is_admin && !empty($r['owner_name'])): ?><span><?= icon('user',11) ?> <?= htmlspecialchars($r['owner_name']) ?></span><?php endif; ?>
                <?php
                  $agg = $meet_agg[$name] ?? null;
                  $ongoing = $hj && !empty($r['host_joined_at']);
                  $first_enter = $agg['first'] ?? ($ongoing ? (int)$r['host_joined_at'] : null);
                  $open_secs = (int)($agg['dur'] ?? 0) + ($ongoing ? max(0, $now - (int)$r['host_joined_at']) : 0);
                ?>
                <?php if ($first_enter !== null): ?>
                  <span><?= icon('play',11) ?> <?= th('進入 {time}', ['time' => date('m/d H:i', $first_enter)]) ?></span>
                  <span><?= icon('clock',11) ?> <?= th('開了 {dur}', ['dur' => fmt_dur_s($open_secs)]) ?><?= $ongoing ? th('（進行中）') : '' ?></span>
                <?php else: ?>
                  <span class="muted"><?= icon('info',11) ?> <?= th('尚未進入') ?></span>
                <?php endif; ?>
                <?php if ($s !== null): ?><span><?= icon('calendar', 11) ?> <?= htmlspecialchars(fmt_range($s, $e)) ?></span><?php endif; ?>
                <?php if (!empty($r['attendees'])): ?><span><?= icon('user',11) ?> <?= th('{n} 位受邀', ['n' => count($r['attendees'])]) ?></span><?php endif; ?>
              </div>
            </div>
            <div class="room-actions">
              <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= htmlspecialchars($invite) ?>" title="<?= th('複製邀請連結') ?>"><?= icon('copy', 14) ?><?= th('複製') ?></button>
              <button type="button" class="btn btn-secondary btn-sm" data-qr="<?= htmlspecialchars($invite) ?>" data-room="<?= htmlspecialchars($name) ?>" title="<?= th('顯示 QR Code') ?>"><?= icon('qr', 14) ?>QR</button>
              <?= enter_room_form($name, icon('arrow-right', 14) . th('進入'), 'btn btn-secondary btn-sm') ?>
              <?php if ($is_admin || ($r['owner'] ?? '') === $me['id']): ?>
              <form method="POST" action="/room-delete" style="display:inline;" data-confirm="<?= !empty($r['attendees']) ? th('確定刪除會議室「{room}」？受邀者會收到取消通知。', ['room' => $name]) : th('確定刪除會議室「{room}」？', ['room' => $name]) ?>">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="room" value="<?= htmlspecialchars($name) ?>">
                <button type="submit" class="btn btn-ghost btn-sm" title="<?= th('刪除會議室') ?>"><?= icon('trash', 14) ?><?= th('刪除') ?></button>
              </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</main>

<!-- QR Code modal -->
<div class="modal-backdrop" id="qrModal">
  <div class="modal">
    <h2><?= th('會議室 QR Code') ?></h2>
    <p class="modal-sub"><?= th('會議室：') ?><strong id="qrRoom"></strong></p>
    <div class="qr" id="qrBox"></div>
    <div class="link-row">
      <input type="text" id="qrLink" readonly>
      <button type="button" class="btn btn-primary" id="qrCopyLink"><?= icon('copy',14) ?><?= th('複製連結') ?></button>
    </div>
    <div class="modal-footer">
      <form method="POST" action="/start" style="display:inline;" id="qrEnterForm">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="room" id="qrEnterRoom" value="">
        <input type="hidden" name="mode" value="host">
        <input type="hidden" name="enter" value="1">
        <button type="submit" class="btn btn-primary"><?= icon('arrow-right',14) ?><?= th('進入會議室') ?></button>
      </form>
      <button type="button" class="btn btn-secondary" id="qrDownload"><?= icon('download') ?><?= th('下載圖片') ?></button>
      <button type="button" class="btn btn-secondary" id="qrClose"><?= icon('x',14) ?><?= th('關閉') ?></button>
    </div>
  </div>
</div>

<div id="flash" class="copy-flash"><?= icon('check', 14) ?><?= th('已複製') ?></div>
<script <?= nonce_attr() ?>>
function dashFlash(msg){
  const f = document.getElementById('flash');
  if (msg) f.lastChild.textContent = msg;
  f.classList.add('show');
  clearTimeout(window.__ft);
  window.__ft = setTimeout(() => f.classList.remove('show'), 1400);
}
async function dashCopy(text){
  try { await navigator.clipboard.writeText(text); }
  catch (_) {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); ta.remove();
  }
}

// copy buttons (room list / created panel)
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  await dashCopy(btn.getAttribute('data-copy'));
  dashFlash(<?= json_encode(t('已複製邀請連結')) ?>);
});

// QR Code modal
(function(){
  const modal = document.getElementById('qrModal');
  const box = document.getElementById('qrBox');
  const linkInput = document.getElementById('qrLink');
  let qr = null;
  function open(url, room){
    linkInput.value = url;
    document.getElementById('qrRoom').textContent = room;
    document.getElementById('qrEnterRoom').value = room;
    box.innerHTML = '';
    qr = new QRCode(box, { text: url, width: 220, height: 220, colorDark:'#18181b', colorLight:'#ffffff', correctLevel: QRCode.CorrectLevel.M });
    modal.classList.add('open');
  }
  function close(){ modal.classList.remove('open'); }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-qr]');
    if (!b) return;
    open(b.getAttribute('data-qr'), b.getAttribute('data-room') || '');
  });
  document.getElementById('qrClose').onclick = close;
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.getElementById('qrCopyLink').onclick = async () => { await dashCopy(linkInput.value); dashFlash(<?= json_encode(t('已複製連結')) ?>); };
  document.getElementById('qrDownload').onclick = () => {
    const img = box.querySelector('img') || box.querySelector('canvas');
    if (!img) return;
    const src = img.tagName === 'CANVAS' ? img.toDataURL('image/png') : img.src;
    const a = document.createElement('a');
    a.href = src; a.download = (document.getElementById('qrRoom').textContent || 'room') + '-qr.png';
    document.body.appendChild(a); a.click(); a.remove();
  };
})();

// live room-name conversion: whitespace -> '-', strip non-ASCII (Jitsi rejects non-ASCII room names)
(function(){
  const input = document.getElementById('room');
  if (!input) return;
  input.addEventListener('input', () => {
    const v = input.value.replace(/\s+/g, '-').replace(/[^A-Za-z0-9_-]/g, '');
    if (v !== input.value) {
      const pos = input.selectionStart;
      input.value = v;
      try { input.setSelectionRange(pos - 1, pos - 1); } catch (e) {}
    }
  });
})();

// random room name (adjective-noun-number, easy to read and share)
(function(){
  const btn = document.getElementById('randomRoom');
  if (!btn) return;
  const adj = ['swift','bright','calm','bold','keen','warm','cool','neat','fair','lucid','brave','quiet','sunny','rapid','prime'];
  const noun = ['otter','falcon','maple','river','comet','willow','harbor','meadow','cobalt','ember','pixel','cedar','lotus','quartz','zephyr'];
  const pick = a => a[Math.floor(Math.random()*a.length)];
  btn.addEventListener('click', () => {
    const name = pick(adj) + '-' + pick(noun) + '-' + Math.floor(1000 + Math.random()*9000);
    const input = document.getElementById('room');
    input.value = name;
    input.focus();
  });
})();

// flatpickr date/time picker (nicer than the native one)
(function(){
  if (!window.flatpickr) return;
  const common = { enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i', minuteIncrement: 5, allowInput: true,
                   locale: (<?= json_encode(I18n::lang() === 'zh-TW') ?> && flatpickr.l10ns && flatpickr.l10ns.zh_tw) ? 'zh_tw' : 'default' };
  const fpEnd = flatpickr('#ends_at', common);
  flatpickr('#starts_at', Object.assign({}, common, {
    onChange: function(sel){
      if (!sel[0]) return;
      fpEnd.set('minDate', sel[0]);
      if (!document.getElementById('ends_at').value) {
        fpEnd.setDate(new Date(sel[0].getTime() + 3600*1000), false);
      }
    }
  }));
})();

// system health (admins): fetched after the page loads so a dead service never slows the dashboard
(function(){
  const box = document.getElementById('sysHealth');
  if (!box) return;
  const T = <?= json_encode(['recorders' => t('{n} 台錄製器'), 'busy' => t('{n} 台錄影中'), 'fail' => t('無法取得狀態'), 'checking' => t('檢查中…')], JSON_UNESCAPED_UNICODE) ?>;
  const fmt = (s, n) => s.replace('{n}', n);
  function paint(k, v) {
    const it = box.querySelector('.sh-item[data-k="' + k + '"]'); if (!it || !v) return;
    it.className = 'sh-item sh-' + (v.level || 'error');
    let msg = v.msg || '';
    if (k === 'jibri' && v.level === 'ok') { msg = fmt(T.recorders, v.healthy ?? v.recorders); if (v.busy) msg += ' · ' + fmt(T.busy, v.busy); }
    it.querySelector('.sh-msg').textContent = msg;
  }
  async function load(force) {
    box.querySelectorAll('.sh-item').forEach(it => { it.className = 'sh-item'; it.querySelector('.sh-msg').textContent = T.checking; });
    try {
      const r = await fetch('/health' + (force ? '?force=1' : ''), { cache: 'no-store', credentials: 'same-origin' });
      const d = await r.json();
      paint('jitsi', d.jitsi); paint('jibri', d.jibri);
      box.classList.toggle('sh-bad', [d.jitsi, d.jibri].some(v => v && v.level === 'error'));
    } catch (e) { box.querySelectorAll('.sh-item').forEach(it => { it.className = 'sh-item sh-error'; it.querySelector('.sh-msg').textContent = T.fail; }); }
  }
  document.getElementById('shRefresh').addEventListener('click', () => load(true));
  load(false);
})();

// transcript: the meeting language is required only while the box is ticked
(function(){
  const chk = document.getElementById('txChk'), box = document.getElementById('txLangBox'), sel = document.getElementById('txLang');
  if (!chk || !box || !sel) return;
  chk.addEventListener('change', () => { box.hidden = !chk.checked; sel.required = chk.checked; if (chk.checked) sel.focus(); });
})();

// scheduled window: expand only when checked; unchecking collapses and clears (avoid submitting stale values)
(function(){
  const chk = document.getElementById('scheduleChk');
  const body = document.getElementById('scheduleBody');
  if (!chk || !body) return;
  chk.addEventListener('change', () => {
    body.hidden = !chk.checked;
    if (!chk.checked) {
      body.querySelectorAll('input, textarea').forEach(el => {
        el.value = '';
        if (el._flatpickr) el._flatpickr.clear();
      });
    }
  });
})();
</script>
<?php render_foot(); ?>
