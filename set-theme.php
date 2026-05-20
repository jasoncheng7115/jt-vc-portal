<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/audit.php';

// 主題是站台層級設定 → 限管理員
Auth::requireAdmin();
Auth::csrfCheck();

$theme = $_POST['theme'] ?? '';
if (Settings::setTheme($theme)) {
  Audit::log('theme_update', "切換主題：{$theme}");
}

header('Location: /settings#theme');
exit;
