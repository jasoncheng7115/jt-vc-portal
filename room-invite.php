<?php
/**
 * 對已建立的會議室寄出邀請（POST + CSRF；擁有者或管理員）。
 * 填入 email → 併入受邀名單 → 寄出 Email + .ics（METHOD:REQUEST，與建立時同一個 UID，SEQUENCE 遞增）。
 * 需要 SMTP；已結束的會議不能再邀請；單次最多 Invites::MAX_PER_SEND 位。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/invites.php';
require_once __DIR__ . '/lib/mailer.php';
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
if (($me['role'] ?? '') !== 'admin' && !$isOwner) $back('room_error', t('只有會議室擁有者或管理員可以寄出邀請。'));
if (empty(Mailer::config()['enabled'])) $back('room_error', t('SMTP 未啟用，無法寄出邀請信。請管理員到系統設定啟用 SMTP。'));
if (!empty($room['ends_at']) && (int)$room['ends_at'] < time()) $back('room_error', t('會議「{room}」已結束，不能再寄出邀請。', ['room' => $name]));

[$emails, $bad] = Invites::parseEmails((string)($_POST['emails'] ?? ''));
if ($bad) $back('room_error', t('以下 email 格式不正確：{emails}', ['emails' => implode(', ', array_slice($bad, 0, 5))]));
if (!$emails) $back('room_error', t('請填入至少一個 email。'));
if (count($emails) > Invites::MAX_PER_SEND) $back('room_error', t('一次最多寄給 {n} 位。', ['n' => Invites::MAX_PER_SEND]));

if (isset($_POST['title'])) Rooms::setTitle($name, (string)$_POST['title']);   // 對話框可補填 / 修改會議主題
Rooms::addAttendees($name, $emails);
$result = Invites::send($name, Rooms::get($name) ?? $room, $emails, $me);
if (!preg_match('/sent_(\d+)_fail_(\d+)/', $result, $x)) $back('room_error', t('邀請信寄送失敗，請稍後再試。'));
Audit::log('invite_sent', tk('會議室「{room}」寄給 {n} 位：{emails}', ['room' => $name, 'n' => count($emails), 'emails' => implode(', ', $emails)]));
if ((int)$x[2] > 0) {
  $back('room_error', t('邀請信：成功 {ok} 封、失敗 {fail} 封。請檢查 SMTP 設定或收件地址。', ['ok' => $x[1], 'fail' => $x[2]]));
}
$back('room_msg', t('已寄出會議「{room}」的邀請給 {n} 位（含行事曆邀請）。', ['room' => $name, 'n' => (int)$x[1]]));
