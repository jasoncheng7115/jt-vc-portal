<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /settings'); exit; }
Auth::csrfCheck();

$payload = [
  '_type'        => 'jt-vc-portal-settings',
  '_app_version' => defined('APP_VERSION') ? APP_VERSION : '',
  '_exported_at' => date('c'),
  'settings'     => Settings::exportData(),
];

// 一併帶出自訂 logo 圖檔（base64），讓還原更完整
$site = Settings::getSite();
$logoPath = DATA_DIR . '/logo.img';
if ($site['logo_mime'] !== '' && is_file($logoPath)) {
  $bytes = @file_get_contents($logoPath);
  if ($bytes !== false) {
    $payload['logo'] = ['mime' => $site['logo_mime'], 'data_b64' => base64_encode($bytes)];
  }
}

Audit::log('settings_export', '匯出系統設定');

$fname = 'jt-vc-portal-settings-' . date('Ymd-His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
