<?php
/**
 * 單一登入緊急 CLI——僅供命令列使用，網頁存取一律 404。
 * 用途：IdP 故障或設定錯誤導致無人能登入時，從伺服器端還原本地密碼登入。
 *
 *   docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php show
 *   docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable-sso-only   # 保留 SSO，恢復本地密碼登入
 *   docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable            # 完全停用 SSO
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/audit.php';

$cmd = $argv[1] ?? 'show';
$o = Settings::getOidc();

if ($cmd === 'show') {
  fwrite(STDOUT, 'SSO enabled      : ' . ($o['enabled'] ? 'yes' : 'no') . "\n");
  fwrite(STDOUT, 'SSO only         : ' . ($o['sso_only'] ? 'yes' : 'no') . "\n");
  fwrite(STDOUT, 'Issuer           : ' . $o['issuer'] . "\n");
  fwrite(STDOUT, 'Client ID        : ' . $o['client_id'] . "\n");
  fwrite(STDOUT, 'Local login CIDRs: ' . ($o['local_login_cidrs'] !== '' ? $o['local_login_cidrs'] : '(localhost only)') . "\n");
  exit(0);
}
if ($cmd === 'disable-sso-only' || $cmd === 'disable') {
  if ($cmd === 'disable') $o['enabled'] = false;
  $o['sso_only'] = false;
  $o['client_secret'] = '';          // 空字串＝保留既有 secret
  Settings::setOidc($o);
  Audit::log('settings_update', 'CLI: sso-cli.php ' . $cmd, ['actor' => 'cli', 'role' => 'admin']);
  fwrite(STDOUT, t('完成：{cmd}', ['cmd' => $cmd]) . "\n");
  exit(0);
}
fwrite(STDERR, t('用法：php sso-cli.php [show|disable-sso-only|disable]') . "\n");
exit(1);
