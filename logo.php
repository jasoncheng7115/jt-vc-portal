<?php
/** 提供站台 logo：有自訂（/var/jaas-data/logo.img）就用，否則回預設 assets/logo-64.png。 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/settings.php';

$site = Settings::getSite();
$path = DATA_DIR . '/logo.img';
$allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

if ($site['logo_mime'] !== '' && in_array($site['logo_mime'], $allowed, true) && is_file($path)) {
  header('Content-Type: ' . $site['logo_mime']);
  readfile($path);
  exit;
}

// 預設
header('Content-Type: image/png');
readfile(__DIR__ . '/assets/logo-64.png');
