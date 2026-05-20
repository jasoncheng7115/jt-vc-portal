<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/audit.php';

Auth::start();
Users::bootstrap();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /jt-login');
  exit;
}
Auth::csrfCheck();

$ip = Auth::clientIp();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$login = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

$fail = function (string $msg) {
  $_SESSION['login_error'] = $msg;
  header('Location: /jt-login');
  exit;
};

// 1) 限流檢查（fail2ban）
if (RateLimit::isLocked($ip)) {
  Audit::log('login_locked', "嘗試帳號：{$login}", ['actor' => $login, 'result' => 'warn']);
  $fail('因多次登入失敗，此來源已被暫時鎖定，請稍後再試。');
}

// 2) 驗證帳密
$user = Users::findByLogin($login);
$ok = $user && empty($user['disabled']) && Users::verifyPassword($user, $password);

if (!$ok) {
  $st = RateLimit::fail($ip);
  Audit::log('login_fail', "嘗試帳號：{$login}", ['actor' => $login, 'result' => 'fail']);
  if ($st['locked']) {
    $fail('登入失敗次數過多，此來源已被鎖定，請於 ' . date('H:i', $st['until']) . ' 後再試。');
  }
  $fail('帳號或密碼錯誤。剩餘嘗試次數 ' . (int)$st['remaining'] . ' 次。');
}

// 3) 需要 2FA → 進入 challenge（尚未建立完整 session）
if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
  session_regenerate_id(true);
  $_SESSION['2fa_uid'] = $user['id'];
  $_SESSION['2fa_login'] = $login;
  header('Location: /twofa');
  exit;
}

// 4) 直接登入成功
RateLimit::reset($ip);
Auth::login($user);
Audit::log('login', '密碼登入');
header('Location: /dashboard');
exit;
