<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/settings.php';

Auth::start();
Users::bootstrap();

// 非 POST 一律 404（不轉址到登入頁，避免洩漏偽裝後的登入路徑）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  Auth::notFound();
}
Auth::csrfCheck();

$ip = Auth::clientIp();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$login = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

$fail = function (string $msg) {
  $_SESSION['login_error'] = $msg;
  header('Location: ' . Settings::loginUrl());
  exit;
};

// 1) 限流檢查（fail2ban）
if (RateLimit::isLocked($ip)) {
  Audit::log('login_locked', t('嘗試帳號：{login}', ['login' => $login]), ['actor' => $login, 'result' => 'warn']);
  $fail(t('因多次登入失敗，此來源已被暫時鎖定，請稍後再試。'));
}

// 1b) 帳號層鎖定（防分散 IP 暴力破解；不論帳號存在與否行為一致）
if (RateLimit::isAccountLocked($login)) {
  RateLimit::fail($ip);
  Audit::log('login_locked', t('嘗試帳號：{login}（帳號層鎖定）', ['login' => $login]), ['actor' => $login, 'result' => 'warn']);
  $fail(t('此帳號因多次登入失敗已暫時鎖定，請稍後再試。'));
}

// 2) 驗證帳密（帳號不存在時也做一次假雜湊，使回應時間一致，避免使用者列舉）
$user = Users::findByLogin($login);
if ($user && empty($user['disabled'])) {
  $ok = Users::verifyPassword($user, $password);
} else {
  Users::fakeVerify($password);
  $ok = false;
}

if (!$ok) {
  $st = RateLimit::fail($ip);
  RateLimit::failAccount($login);
  Audit::log('login_fail', t('嘗試帳號：{login}', ['login' => $login]), ['actor' => $login, 'result' => 'fail']);
  if ($st['locked']) {
    $fail(t('登入失敗次數過多，此來源已被鎖定，請於 {time} 後再試。', ['time' => date('H:i', $st['until'])]));
  }
  $fail(t('帳號或密碼錯誤。剩餘嘗試次數 {n} 次。', ['n' => (int)$st['remaining']]));
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
RateLimit::resetAccount($login);
Auth::login($user);
Audit::log('login', t('密碼登入'));
header('Location: /dashboard');
exit;
