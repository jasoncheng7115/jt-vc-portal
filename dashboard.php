<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/settings.php';

$me = Auth::requireLogin();
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
unset($_SESSION['room_error']);
$form_values = $_SESSION['form_values'] ?? [];
unset($_SESSION['form_values']);
$form_room      = (string)($form_values['room']      ?? ($_GET['room'] ?? ''));
$form_starts_at = (string)($form_values['starts_at'] ?? '');
$form_ends_at   = (string)($form_values['ends_at']   ?? '');
$form_attendees = (string)($form_values['attendees'] ?? '');
$form_lobby     = !empty($form_values['lobby']);
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
  if ($diff < 60) return $diff . ' 秒前';
  if ($diff < 3600) return floor($diff / 60) . ' 分鐘前';
  if ($diff < 86400) return floor($diff / 3600) . ' 小時前';
  return floor($diff / 86400) . ' 天前';
}
function fmt_range(?int $s, ?int $e): string {
  if ($s === null) return '';
  $sameday = $e !== null && date('Y-m-d', $s) === date('Y-m-d', $e);
  $left  = date('m/d H:i', $s);
  $right = $e === null ? '不限' : ($sameday ? date('H:i', $e) : date('m/d H:i', $e));
  return $left . ' ～ ' . $right;
}
function fmt_dur_s(int $s): string {
  if ($s < 60) return $s . ' 秒';
  $m = intdiv($s, 60);
  if ($m < 60) return $m . ' 分';
  $h = intdiv($m, 60); $mm = $m % 60;
  return $h . ' 時' . ($mm ? ' ' . $mm . ' 分' : '');
}
function mail_flash_text(string $m): string {
  if ($m === 'smtp_off') return '（SMTP 未啟用，邀請信未寄出，僅建立連結）';
  if (preg_match('/sent_(\d+)_fail_(\d+)/', $m, $x)) {
    return "（邀請信：成功 {$x[1]} 封" . ($x[2] > 0 ? "、失敗 {$x[2]} 封" : '') . '）';
  }
  return '';
}

render_head('儀表板');
render_topbar($me, $ip);
?>
<main class="container">
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/zh-tw.js" integrity="sha384-mjJeOdHLBw1XvGBUqn+UxU0xEtQSbR1nG/0o9getG2BM2o6lfJvCwTjPwyvzKrOY" crossorigin="anonymous"></script>

  <?php if ($is_admin): ?><?= admin_nav('dashboard') ?><?php endif; ?>

  <?php if ($created_data):
    $invite_url = SITE_URL . '/room/' . rawurlencode($created);
  ?>
  <div class="created-panel">
    <div class="panel-qr"><div id="createdQr"></div></div>
    <div class="panel-body">
      <div class="panel-title"><?= icon('check') ?> 會議室連結已建立 <?= htmlspecialchars(mail_flash_text($mail_flash)) ?></div>
      <div class="muted" style="font-size:13px;margin-bottom:6px;">
        會議室 <strong style="font-family:var(--mono);color:var(--text);"><?= htmlspecialchars($created) ?></strong>
        <?php if ($created_data['starts_at']): ?> · 開放時段 <?= htmlspecialchars(fmt_range($created_data['starts_at'], $created_data['ends_at'])) ?><?php endif; ?>
        <?php if (!empty($created_data['lobby'])): ?> · <span style="color:var(--text);"><?= icon('lock', 12) ?>大廳模式</span><?php endif; ?>
      </div>
      <div class="panel-link"><?= htmlspecialchars($invite_url) ?></div>
      <div class="panel-actions">
        <button type="button" class="btn btn-primary btn-sm" data-copy="<?= htmlspecialchars($invite_url) ?>"><?= icon('copy', 14) ?>複製邀請連結</button>
        <a class="btn btn-secondary btn-sm" href="/start?room=<?= rawurlencode($created) ?>&mode=host"><?= icon('play', 14) ?>立即主持</a>
      </div>
    </div>
    <div></div>
  </div>
  <script>
    window.addEventListener('DOMContentLoaded', () => {
      if (window.QRCode) new QRCode(document.getElementById('createdQr'), {
        text: <?= json_encode($invite_url) ?>, width: 96, height: 96,
        colorDark: '#18181b', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M
      });
    });
  </script>
  <?php endif; ?>

  <div class="card">
    <h1><?= icon('video', 18) ?>建立 / 進入會議室</h1>
    <p class="subtitle">輸入會議室名稱即可開始，或從下方近期清單快速進入。可選擇先建立連結但暫不進入會議。</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" action="/start">
      <?= Auth::csrfField() ?>
      <div class="field">
        <label for="room">會議室名稱</label>
        <div class="link-row">
          <input type="text" id="room" name="room" required autofocus
                 placeholder="例如：weekly-sync"
                 pattern="[\p{L}\p{N}_\-]+" title="可使用中英文、數字、底線、連字號"
                 value="<?= htmlspecialchars($form_room) ?>">
          <button type="button" class="btn btn-secondary" id="randomRoom" title="亂數產生會議室名稱"><?= icon('refresh',14) ?>亂數</button>
        </div>
        <div class="help">命名建議使用英文或數字，以利分享。空格會自動轉成 <span class="kbd">-</span></div>
      </div>

      <div class="schedule-block">
      <label class="schedule-head" for="scheduleChk">
        <input type="checkbox" id="scheduleChk"<?= $schedule_open ? ' checked' : '' ?>>
        <span class="lobby-text">
          <span class="lobby-title"><?= icon('calendar', 14) ?>限定開放時段 / 寄送邀請</span>
          <span class="help" style="margin:0;">未勾選 = 來賓需等主持人開啟；勾選後可設定開放時段並寄送邀請信。</span>
        </span>
      </label>
      <div id="scheduleBody" class="schedule-body"<?= $schedule_open ? '' : ' hidden' ?>>
        <div class="field-row">
          <div class="field" style="margin-bottom:0;">
            <label for="starts_at">開始時間</label>
            <input type="text" id="starts_at" name="starts_at" autocomplete="off" placeholder="點選選擇日期時間" value="<?= htmlspecialchars($form_starts_at) ?>">
          </div>
          <div class="field" style="margin-bottom:0;">
            <label for="ends_at">結束時間</label>
            <input type="text" id="ends_at" name="ends_at" autocomplete="off" placeholder="點選選擇日期時間" value="<?= htmlspecialchars($form_ends_at) ?>">
          </div>
        </div>
        <div class="field" style="margin:14px 0 0;">
          <label for="attendees">與會者 Email（選填，可多筆，換行或逗號分隔）</label>
          <textarea id="attendees" name="attendees" placeholder="alice@example.com, bob@example.com"><?= htmlspecialchars($form_attendees) ?></textarea>
          <div class="help">填寫後系統會寄出含行事曆（.ics）的邀請信，對方可一鍵加入行事曆（需先於系統設定啟用 SMTP）。</div>
        </div>
      </div>
      </div>

      <label class="lobby-toggle">
        <input type="checkbox" name="lobby" value="1"<?= $form_lobby ? ' checked' : '' ?>>
        <span class="lobby-text">
          <span class="lobby-title"><?= icon('lock', 14) ?>啟用大廳模式</span>
          <span class="help" style="margin:0;">主持人進入後自動開啟；之後每位來賓需經主持人允許才能進入會議室。</span>
        </span>
      </label>

      <div class="btn-group">
        <button type="submit" name="mode" value="host" class="btn btn-primary"><?= icon('play') ?>開始主持會議</button>
        <button type="submit" name="mode" value="create" class="btn btn-secondary"><?= icon('link') ?>建立會議室連結</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h1><?= icon('clock', 18) ?>近期會議室 <span class="muted" style="text-transform:none;letter-spacing:0;font-weight:400;font-size:13px;">· 近期 / 即將開始<?= $is_admin ? '（全部主持人）' : '' ?></span></h1>
    <?php if (empty($rooms)): ?>
      <div class="empty">尚無近期會議室。建立後將會出現在這裡。</div>
    <?php else: ?>
      <ul class="room-list">
        <?php foreach ($rooms as $name => $r):
          $invite = SITE_URL . '/room/' . rawurlencode($name);
          $now = time();
          $hj = Rooms::isHostPresent($r, $now);
          $host_was = !empty($r['host_joined']) && !$hj;
          $s = $r['starts_at']; $e = $r['ends_at'];
          if ($hj) $badge = '<span class="badge badge-success">'.icon('check',11).'主持人在線上</span>';
          elseif ($host_was) $badge = '<span class="badge badge-muted">主持人離線</span>';
          elseif ($s !== null && $now < $s) $badge = '<span class="badge badge-accent">'.icon('clock',11).'預約中</span>';
          elseif ($s !== null && $e !== null && $now > $e) $badge = '<span class="badge badge-muted">已結束</span>';
          elseif ($s !== null) $badge = '<span class="badge badge-warning">開放中</span>';
          else $badge = '<span class="badge">待主持人</span>';
        ?>
          <li>
            <div class="room-main">
              <span class="room-name"><?= htmlspecialchars($name) ?></span>
              <div class="room-meta">
                <?= $badge ?>
                <?php if (!empty($r['lobby'])): ?><span class="badge badge-accent"><?= icon('lock',11) ?>大廳模式</span><?php endif; ?>
                <span>建立於 <?= fmt_when($r['created_at']) ?></span>
                <?php if ($is_admin && !empty($r['owner_name'])): ?><span><?= icon('user',11) ?> <?= htmlspecialchars($r['owner_name']) ?></span><?php endif; ?>
                <?php
                  $agg = $meet_agg[$name] ?? null;
                  $ongoing = $hj && !empty($r['host_joined_at']);
                  $first_enter = $agg['first'] ?? ($ongoing ? (int)$r['host_joined_at'] : null);
                  $open_secs = (int)($agg['dur'] ?? 0) + ($ongoing ? max(0, $now - (int)$r['host_joined_at']) : 0);
                ?>
                <?php if ($first_enter !== null): ?>
                  <span><?= icon('play',11) ?> 進入 <?= date('m/d H:i', $first_enter) ?></span>
                  <span><?= icon('clock',11) ?> 開了 <?= htmlspecialchars(fmt_dur_s($open_secs)) ?><?= $ongoing ? '（進行中）' : '' ?></span>
                <?php else: ?>
                  <span class="muted"><?= icon('info',11) ?> 尚未進入</span>
                <?php endif; ?>
                <?php if ($s !== null): ?><span><?= icon('calendar', 11) ?> <?= htmlspecialchars(fmt_range($s, $e)) ?></span><?php endif; ?>
                <?php if (!empty($r['attendees'])): ?><span><?= icon('user',11) ?> <?= count($r['attendees']) ?> 位受邀</span><?php endif; ?>
              </div>
            </div>
            <div class="room-actions">
              <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= htmlspecialchars($invite) ?>" title="複製邀請連結"><?= icon('copy', 14) ?>複製</button>
              <button type="button" class="btn btn-secondary btn-sm" data-qr="<?= htmlspecialchars($invite) ?>" data-room="<?= htmlspecialchars($name) ?>" title="顯示 QR Code"><?= icon('qr', 14) ?>QR</button>
              <a class="btn btn-secondary btn-sm" href="/start?room=<?= rawurlencode($name) ?>&mode=host"><?= icon('arrow-right', 14) ?>進入</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</main>

<!-- QR Code 彈窗 -->
<div class="modal-backdrop" id="qrModal">
  <div class="modal">
    <h2>會議室 QR Code</h2>
    <p class="modal-sub">會議室：<strong id="qrRoom"></strong></p>
    <div class="qr" id="qrBox"></div>
    <div class="link-row">
      <input type="text" id="qrLink" readonly>
      <button type="button" class="btn btn-primary" id="qrCopyLink"><?= icon('copy',14) ?>複製連結</button>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="qrDownload"><?= icon('download') ?>下載圖片</button>
      <button type="button" class="btn btn-secondary" id="qrClose"><?= icon('x',14) ?>關閉</button>
    </div>
  </div>
</div>

<div id="flash" class="copy-flash"><?= icon('check', 14) ?>已複製</div>
<script>
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

// 複製按鈕（房間列 / 建立面板）
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  await dashCopy(btn.getAttribute('data-copy'));
  dashFlash('已複製邀請連結');
});

// QR Code 彈窗
(function(){
  const modal = document.getElementById('qrModal');
  const box = document.getElementById('qrBox');
  const linkInput = document.getElementById('qrLink');
  let qr = null;
  function open(url, room){
    linkInput.value = url;
    document.getElementById('qrRoom').textContent = room;
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
  document.getElementById('qrCopyLink').onclick = async () => { await dashCopy(linkInput.value); dashFlash('已複製連結'); };
  document.getElementById('qrDownload').onclick = () => {
    const img = box.querySelector('img') || box.querySelector('canvas');
    if (!img) return;
    const src = img.tagName === 'CANVAS' ? img.toDataURL('image/png') : img.src;
    const a = document.createElement('a');
    a.href = src; a.download = (document.getElementById('qrRoom').textContent || 'room') + '-qr.png';
    document.body.appendChild(a); a.click(); a.remove();
  };
})();

// 亂數產生會議室名稱（形容詞-名詞-數字，好讀好分享）
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

// flatpickr 日期時間選擇器（比原生好用）
(function(){
  if (!window.flatpickr) return;
  const common = { enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i', minuteIncrement: 5, allowInput: true,
                   locale: (flatpickr.l10ns && flatpickr.l10ns.zh_tw) ? 'zh_tw' : 'default' };
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

// 限定開放時段：勾選才展開設定；取消勾選則收合並清空（避免殘值送出）
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
