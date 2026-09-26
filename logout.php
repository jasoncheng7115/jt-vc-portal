<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/settings.php';

Auth::start();
// 只有「確實登入中」或「2FA 進行中」才導回登入頁；未登入者一律回首頁，避免洩漏偽裝後的登入路徑。
$had = Auth::check() || !empty($_SESSION['2fa_uid']);
if (!empty($_SESSION['uid'])) {
  Audit::log('logout', '');
}
Auth::logout();
header('Location: ' . ($had ? Settings::loginUrl() : '/'));
exit;
