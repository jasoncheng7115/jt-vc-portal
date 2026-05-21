<?php
/**
 * 登入路徑 CLI 工具——僅供命令列使用，網頁存取一律 404。
 * 用途：管理者忘記或鎖死自訂登入路徑時，從伺服器端還原回預設 /jt-login。
 *
 *   docker exec -u www-data jaas-auth php /var/www/html/login-path.php show
 *   docker exec -u www-data jaas-auth php /var/www/html/login-path.php reset
 *   docker exec -u www-data jaas-auth php /var/www/html/login-path.php set <path>
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/settings.php';

$cmd = $argv[1] ?? 'show';

if ($cmd === 'show') {
  fwrite(STDOUT, '目前登入路徑：/' . Settings::getLoginPath() . "\n");
  exit(0);
}
if ($cmd === 'reset') {
  Settings::setLoginPath(Settings::DEFAULT_LOGIN_PATH);
  fwrite(STDOUT, '已還原登入路徑為 /' . Settings::DEFAULT_LOGIN_PATH . "\n");
  exit(0);
}
if ($cmd === 'set') {
  $p = $argv[2] ?? '';
  if (!Settings::validLoginPath($p)) {
    fwrite(STDERR, "路徑格式不合法（僅允許英數與 . _ -，長度 1–64）。\n");
    exit(1);
  }
  Settings::setLoginPath($p);
  fwrite(STDOUT, '已設定登入路徑為 /' . Settings::getLoginPath() . "\n");
  exit(0);
}

fwrite(STDERR, "用法：php login-path.php [show|reset|set <path>]\n");
exit(1);
