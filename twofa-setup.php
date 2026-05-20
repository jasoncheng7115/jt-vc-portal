<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /profile'); exit; }
Auth::csrfCheck();

$action = $_POST['action'] ?? '';
$back = function (string $key, string $msg) {
  $_SESSION[$key] = $msg;
  header('Location: /profile');
  exit;
};

if ($action === 'enable') {
  $secret = $_SESSION['pending_totp'] ?? '';
  $code = (string)($_POST['code'] ?? '');
  if ($secret === '') $back('profile_err', '設定逾時，請重新啟用 2FA。');
  if (!Totp::verify($secret, $code)) $back('profile_err', '驗證碼錯誤，請確認時間同步後再試。');
  Users::update($me['id'], ['totp_secret' => $secret, 'totp_enabled' => true]);
  unset($_SESSION['pending_totp']);
  Audit::log('2fa_enable', '啟用自己的兩步驟驗證');
  $back('profile_msg', '2FA 已啟用。');
}

if ($action === 'disable') {
  $pw = (string)($_POST['password'] ?? '');
  if (!Users::verifyPassword($me, $pw)) $back('profile_err', '密碼不正確，未停用 2FA。');
  Users::update($me['id'], ['totp_secret' => null, 'totp_enabled' => false]);
  Audit::log('2fa_disable', '停用自己的兩步驟驗證');
  $back('profile_msg', '2FA 已停用。');
}

header('Location: /profile');
exit;
