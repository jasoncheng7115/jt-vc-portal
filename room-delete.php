<?php
/**
 * 刪除 / 取消會議室（POST + CSRF；擁有者或管理員）。
 * 有受邀者且 SMTP 啟用時寄出 .ics 取消通知（METHOD:CANCEL，SEQUENCE 遞增），行事曆會自動移除該事件。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/invites.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /dashboard'); exit; }
Auth::csrfCheck();

$back = function (string $key, string $msg) {
  $_SESSION[$key] = $msg;
  header('Location: /dashboard');
  exit;
};

$name = Rooms::sanitize((string)($_POST['room'] ?? ''));
$room = $name !== '' ? Rooms::get($name) : null;
if ($room === null) $back('room_error', t('找不到此會議室，可能已過期被清除。'));
$isOwner = !empty($room['owner']) && $room['owner'] === $me['id'];
if (($me['role'] ?? '') !== 'admin' && !$isOwner) $back('room_error', t('只有會議室擁有者或管理員可以刪除。'));

// 先寄取消通知（需要房間資料與 SEQUENCE），再刪除
$mail = '';
if (!empty($room['attendees'])) {
  $mail = Invites::send($name, $room, $room['attendees'], $me, 'CANCEL');
}
Rooms::delete($name);
$detail = t('會議室「{room}」', ['room' => $name]);
if (preg_match('/sent_(\d+)_fail_(\d+)/', $mail, $x)) $detail .= t('（取消通知：成功 {ok} 封、失敗 {fail} 封）', ['ok' => $x[1], 'fail' => $x[2]]);
Audit::log('room_delete', $detail);
$back('room_msg', t('已刪除會議室「{room}」。', ['room' => $name]) . ($mail === 'smtp_off' ? t('（SMTP 未啟用，未寄出取消通知）') : ''));
