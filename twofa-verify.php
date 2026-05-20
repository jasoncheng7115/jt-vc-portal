<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/audit.php';

Auth::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['2fa_uid'])) {
  header('Location: /jt-login');
  exit;
}
Auth::csrfCheck();

$ip = Auth::clientIp();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$uid = $_SESSION['2fa_uid'];
$login = $_SESSION['2fa_login'] ?? '';
$code = (string)($_POST['code'] ?? '');

$user = Users::find($uid);
if (!$user || !empty($user['disabled']) || empty($user['totp_secret'])) {
  unset($_SESSION['2fa_uid'], $_SESSION['2fa_login']);
  header('Location: /jt-login');
  exit;
}

if (RateLimit::isLocked($ip)) {
  Audit::log('login_locked', "嘗試帳號：{$login}（2FA 階段）", ['actor' => $login, 'result' => 'warn']);
  unset($_SESSION['2fa_uid'], $_SESSION['2fa_login']);
  $_SESSION['login_error'] = '因多次失敗，此來源已被鎖定，請稍後再試。';
  header('Location: /jt-login');
  exit;
}

if (!Totp::verify($user['totp_secret'], $code)) {
  RateLimit::fail($ip);
  Audit::log('login_2fa_fail', "帳號：{$user['username']}", ['actor' => $user['username'], 'actor_name' => $user['display_name'] ?? '', 'role' => $user['role'] ?? '', 'result' => 'fail']);
  $_SESSION['2fa_error'] = '驗證碼錯誤，請再試一次。';
  header('Location: /twofa');
  exit;
}

// 通過
RateLimit::reset($ip);
Auth::login($user);
Audit::log('login', '密碼 + 2FA 登入');
header('Location: /dashboard');
exit;
