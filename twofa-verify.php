<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/settings.php';

Auth::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['2fa_uid'])) {
  header('Location: ' . Settings::loginUrl());
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
  header('Location: ' . Settings::loginUrl());
  exit;
}

if (RateLimit::isLocked($ip)) {
  Audit::log('login_locked', "嘗試帳號：{$login}（2FA 階段）", ['actor' => $login, 'result' => 'warn']);
  unset($_SESSION['2fa_uid'], $_SESSION['2fa_login']);
  $_SESSION['login_error'] = '因多次失敗，此來源已被鎖定，請稍後再試。';
  header('Location: ' . Settings::loginUrl());
  exit;
}

$ctr = Totp::verifyCounter($user['totp_secret'], $code);
$lastCtr = (int)($user['totp_last_counter'] ?? 0);
// 碼錯誤，或該碼（含同窗鄰近碼）已用過 → 拒絕（重放保護）。
if ($ctr === 0 || $ctr <= $lastCtr) {
  RateLimit::fail($ip);
  $reason = ($ctr !== 0 && $ctr <= $lastCtr) ? '（驗證碼已使用）' : '';
  Audit::log('login_2fa_fail', "帳號：{$user['username']}{$reason}", ['actor' => $user['username'], 'actor_name' => $user['display_name'] ?? '', 'role' => $user['role'] ?? '', 'result' => 'fail']);
  $_SESSION['2fa_error'] = '驗證碼錯誤或已使用，請等待下一組碼再試。';
  header('Location: /twofa');
  exit;
}

// 通過 → 記下已用 counter，避免有效窗內重放
Users::update($uid, ['totp_last_counter' => $ctr]);
RateLimit::reset($ip);
Auth::login($user);
Audit::log('login', '密碼 + 2FA 登入');
header('Location: /dashboard');
exit;
