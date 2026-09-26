<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/jaas.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/ical.php';
require_once __DIR__ . '/lib/invites.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireLogin();

// 一律 POST + CSRF（A01）：建立表單與儀表板的「進入 / 立即主持」按鈕都是 POST 表單。
// GET 不做任何狀態變更，直接回儀表板（避免跨站連結讓已登入主持人建房或被標記進場）。
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /dashboard');
  exit;
}
Auth::csrfCheck();

$raw_room = $_POST['room'] ?? '';
$entering = !empty($_POST['enter']);   // 從清單「進入」既有會議室
$room = Rooms::sanitize($raw_room);

// 保留輸入，validation 失敗時帶回
$form_input = [
  'room'      => (string)($_POST['room']      ?? ''),
  'starts_at' => (string)($_POST['starts_at'] ?? ''),
  'ends_at'   => (string)($_POST['ends_at']   ?? ''),
  'attendees' => (string)($_POST['attendees'] ?? ''),
  'lobby'     => !empty($_POST['lobby']) ? '1' : '',
];
$fail = function (string $msg) use ($form_input) {
  $_SESSION['room_error'] = $msg;
  $_SESSION['form_values'] = $form_input;
  header('Location: /dashboard');
  exit;
};

if ($room === '') {
  $fail(t('請輸入有效的會議室名稱（僅限英文、數字、- 與 _；中文等非 ASCII 字元不支援）。'));
}

$mode = $_POST['mode'] ?? 'host';
if (!in_array($mode, ['host', 'create'], true)) $mode = 'host';

$has_schedule = !$entering && (isset($_POST['starts_at']) || isset($_POST['ends_at']));
$starts_at = Rooms::parseDateTimeLocal($_POST['starts_at'] ?? null);
$ends_at   = Rooms::parseDateTimeLocal($_POST['ends_at']   ?? null);
if ($starts_at !== null && $ends_at !== null && $ends_at <= $starts_at) {
  $fail(t('結束時間必須晚於開始時間。'));
}

// 解析與會者 email（換行 / 逗號 / 空白分隔）
$attendees = [];
$bad_emails = [];
if (!empty($_POST['attendees'])) {
  foreach (preg_split('/[\s,;]+/', $_POST['attendees']) as $e) {
    $e = trim($e);
    if ($e === '') continue;
    if (Mailer::isValidEmail($e)) $attendees[] = $e;
    else $bad_emails[] = $e;
  }
  $attendees = array_values(array_unique($attendees));
}
if (!empty($bad_emails)) {
  $fail(t('以下 email 格式不正確：{emails}', ['emails' => htmlspecialchars(implode(', ', array_slice($bad_emails, 0, 5)))]));
}

$existing = Rooms::get($room);

// 建立表單輸入「已存在」的房名 → 一律擋下，要求改名；
// 從近期清單「進入」（enter=1）則必須是既有會議室。
if (!$entering && $existing) {
  $fail(t('此會議室名稱已存在，請改用其他名稱（可按「亂數」產生）。'));
}
if ($entering && !$existing) {
  $fail(t('找不到此會議室，可能已過期被清除。'));
}
if ($entering) $mode = 'host';

// 進入他人擁有的會議室 → 擋
if ($existing && !empty($existing['owner'])
    && $existing['owner'] !== $me['id'] && ($me['role'] ?? '') !== 'admin') {
  $fail(t('此會議室名稱已被其他主持人使用，請換一個名稱。'));
}

$opts = [
  'host_joined'     => ($mode === 'host'),
  'update_schedule' => $has_schedule,
  'starts_at'       => $starts_at,
  'ends_at'         => $ends_at,
  'owner'           => $me['id'],
  'owner_name'      => $me['username'] ?? $me['email'],
  'attendees'       => $attendees,
];
// 只有「建立表單」才更新 lobby / 與會者；從清單「進入」須保留原值，否則會把先前的設定清掉。
if (!$entering) {
  $opts['lobby'] = !empty($_POST['lobby']);
} else {
  unset($opts['attendees']);
}
Rooms::upsert($room, $opts);

// 寄送邀請信（有填 email 且 SMTP 已啟用時）
$mail_result = null;
if (!empty($attendees)) {
  $mail_result = Invites::send($room, Rooms::get($room) ?? ['created_at' => time(), 'starts_at' => $starts_at, 'ends_at' => $ends_at], $attendees, $me);
  if (strpos((string)$mail_result, 'sent_') === 0) {
    Audit::log('invite_sent', t('會議室「{room}」寄給 {n} 位：{emails}', ['room' => $room, 'n' => count($attendees), 'emails' => implode(', ', $attendees)]));
  }
}

if ($mode === 'create') {
  Audit::log('room_create', t('會議室「{room}」', ['room' => $room]) . ($starts_at ? t('（已設排程）') : '') . (!empty($_POST['lobby']) ? t('（大廳模式）') : ''));
  $q = '/dashboard?created=' . rawurlencode($room);
  if ($mail_result !== null) $q .= '&mail=' . rawurlencode($mail_result);
  header('Location: ' . $q);
  exit;
}

// mode = host：依模式簽發主持人 JWT 並進入會議
$jwt = Jaas::makeJwt($room, [
  'name'      => ($me['display_name'] ?? '') ?: ($me['username'] ?: HOST_DISPLAY_NAME),
  'email'     => $me['email'],
  'id'        => $me['email'],
  'moderator' => true,
], ['recording' => true] + Jaas::FEATURES_OFF, Jaas::HOST_JWT_TTL);
$_SESSION['jwt'] = $jwt;
$_SESSION['room'] = $room;
Audit::log('room_enter', t('會議室「{room}」', ['room' => $room]));
header('Location: /meeting');
exit;
