<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/jaas.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/ical.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireLogin();

// 表單(POST)需 CSRF；儀表板的 GET 連結（進入/立即主持）為同站點選，不帶 token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  Auth::csrfCheck();
}

$raw_room = $_POST['room'] ?? $_GET['room'] ?? '';
$room = Rooms::sanitize($raw_room);

// 保留輸入，validation 失敗時帶回
$form_input = [
  'room'      => (string)($_POST['room']      ?? $_GET['room']      ?? ''),
  'starts_at' => (string)($_POST['starts_at'] ?? $_GET['starts_at'] ?? ''),
  'ends_at'   => (string)($_POST['ends_at']   ?? $_GET['ends_at']   ?? ''),
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
  $fail('請輸入有效的會議室名稱（中英文、數字、底線、連字號）。');
}

$mode = $_POST['mode'] ?? $_GET['mode'] ?? 'host';
if (!in_array($mode, ['host', 'create'], true)) $mode = 'host';

$has_schedule = isset($_POST['starts_at']) || isset($_POST['ends_at']);
$starts_at = Rooms::parseDateTimeLocal($_POST['starts_at'] ?? null);
$ends_at   = Rooms::parseDateTimeLocal($_POST['ends_at']   ?? null);
if ($starts_at !== null && $ends_at !== null && $ends_at <= $starts_at) {
  $fail('結束時間必須晚於開始時間。');
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
  $fail('以下 email 格式不正確：' . htmlspecialchars(implode(', ', array_slice($bad_emails, 0, 5))));
}

$existing = Rooms::get($room);

// 透過建立表單(POST)輸入「已存在」的房名 → 一律擋下，要求改名
// （從近期清單點「進入」是 GET 連結，不受此限，仍可進入既有會議室）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $existing) {
  $fail('此會議室名稱已存在，請改用其他名稱（可按「亂數」產生）。');
}

// 進入他人擁有的會議室 → 擋
if ($existing && !empty($existing['owner'])
    && $existing['owner'] !== $me['id'] && ($me['role'] ?? '') !== 'admin') {
  $fail('此會議室名稱已被其他主持人使用，請換一個名稱。');
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
// 只有「建立 / 設定表單」(POST，含大廳勾選欄位) 才更新 lobby；
// 從清單點「進入」(GET) 不帶此欄位，須保留原值，否則會把先前勾的大廳清掉。
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $opts['lobby'] = !empty($_POST['lobby']);
}
Rooms::upsert($room, $opts);

// 寄送邀請信（有填 email 且 SMTP 已啟用時）
$mail_result = null;
if (!empty($attendees)) {
  $mail_result = send_invites($room, $attendees, $starts_at, $ends_at, $me);
  if (strpos((string)$mail_result, 'sent_') === 0) {
    Audit::log('invite_sent', "會議室「{$room}」寄給 " . count($attendees) . " 位：" . implode(', ', $attendees));
  }
}

if ($mode === 'create') {
  Audit::log('room_create', "會議室「{$room}」" . ($starts_at ? '（已設排程）' : '') . (!empty($_POST['lobby']) ? '（大廳模式）' : ''));
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
], ['recording' => true, 'livestreaming' => false, 'transcription' => false, 'outbound-call' => false]);
$_SESSION['jwt'] = $jwt;
$_SESSION['room'] = $room;
Audit::log('room_enter', "會議室「{$room}」");
header('Location: /meeting');
exit;

/** 寄出 .ics 邀請，回傳簡短結果字串給儀表板顯示。 */
function send_invites(string $room, array $attendees, ?int $starts_at, ?int $ends_at, array $me): string {
  $cfg = Mailer::config();
  if (empty($cfg['enabled'])) return 'smtp_off';

  $invite_url = SITE_URL . '/room/' . rawurlencode($room);
  $start = $starts_at ?? time();
  $end   = $ends_at ?? ($start + 3600);
  $time_str = $starts_at
    ? ('會議時間：' . date('Y-m-d H:i', $start) . ' ～ ' . date('H:i', $end))
    : '';
  $site_name = Settings::getSite()['brand_name'];
  $host_name = ($me['display_name'] ?? '') ?: ($me['username'] ?? '');
  $vars = [
    'room'       => $room,
    'invite_url' => $invite_url,
    'time'       => $time_str,
    'site_name'  => $site_name,
    'host'       => $host_name,
  ];
  $subject = Mailer::renderTemplate($cfg['subject_tpl'], $vars);
  $sent = 0; $failed = 0;

  foreach ($attendees as $to) {
    $uid = 'jt-' . substr(md5($room . $to), 0, 16) . '@jt-vc-portal';
    $ics = ICal::buildRequest([
      'uid'            => $uid,
      'summary'        => $subject,
      'description'    => "請於會議時間點此連結加入：\n" . $invite_url,
      'location'       => $invite_url,
      'organizerEmail' => $cfg['from_email'],
      'organizerName'  => $cfg['from_name'],
      'attendees'      => [$to],
      'start'          => $start,
      'end'            => $end,
    ]);
    $body = Mailer::renderTemplate($cfg['body_tpl'], $vars);
    [$ok] = Mailer::sendInvite([
      'to' => $to,
      'subject' => $subject,
      'bodyText' => $body,
      'icsContent' => $ics,
      'icsFilename' => 'invite.ics',
    ], $cfg);
    $ok ? $sent++ : $failed++;
  }
  return "sent_{$sent}_fail_{$failed}";
}
