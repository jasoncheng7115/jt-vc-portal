<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/oidc.php';

Auth::start();
// 登出只接受 POST + CSRF（防跨站連結把使用者登出）。GET 不做事：登入中回儀表板，否則回首頁。
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . (Auth::check() ? '/dashboard' : '/'));
  exit;
}
Auth::csrfCheck();
// 只有「確實登入中」或「2FA 進行中」才導回登入頁；其餘回首頁，避免洩漏偽裝後的登入路徑。
$had = Auth::check() || !empty($_SESSION['2fa_uid']);
if (!empty($_SESSION['uid'])) {
  Audit::log('logout', '');
}
// SSO 登入者：先算出 IdP 登出網址（帶 id_token_hint），再銷毀本地 session，最後導向 IdP 一併登出
$idp = !empty($_SESSION['oidc_id_token']) ? Oidc::logoutUrl((string)$_SESSION['oidc_id_token']) : null;
Auth::logout();
header('Location: ' . ($idp ?? ($had ? Settings::loginUrl() : '/')));
exit;
