<?php
// === 版本 ===
// 每次有更新都要推進版本號（patch++ / 功能 minor++）。
define('APP_VERSION', '1.2.0');
define('APP_GITHUB_URL', 'https://github.com/jasoncheng7115/jt-vc-portal');

// === JaaS (8x8) 連線設定：改由管理介面設定，存於 settings.json，不再寫死 ===
require_once __DIR__ . '/lib/settings.php';
$__jaas = Settings::getJaas();
define('JITSI_APP_ID',    $__jaas['app_id']);
define('JITSI_TENANT_ID', $__jaas['app_id']);  // JaaS 的 tenant 即 app id
define('JITSI_KID',       $__jaas['kid']);
define('JITSI_DOMAIN',    $__jaas['domain']);
define('SITE_URL',        $__jaas['site_url']);

// === JWT 簽章 ===
define('JWT_ALG', 'RS256');
define('JWT_PRIVATE_KEY_PATH', '/var/www/html/keys/private.key');

// === 持久化資料卷（docker 重建不會遺失）===
define('DATA_DIR', '/var/jaas-data');
define('AUTO_ALLOW_FILE', DATA_DIR . '/auto-allow.json');
define('ROOM_TTL_SECONDS', 86400); // 24 hours

// === 初始管理員（僅首次無 users.json 時用以 bootstrap，之後一律從帳號管理頁維護）===
// 不在此寫死密碼。可用環境變數提供；若皆未提供，bootstrap 會自動產生隨機密碼，
// 並寫入 /var/jaas-data/INITIAL_ADMIN_PASSWORD.txt 供首次登入後刪除。
define('BOOTSTRAP_ADMIN_USERNAME', getenv('JTVC_ADMIN_USERNAME') ?: 'jtvc-admin');
define('BOOTSTRAP_ADMIN_EMAIL', getenv('JTVC_ADMIN_EMAIL') ?: 'admin@localhost');
define('BOOTSTRAP_ADMIN_PASSWORD', getenv('JTVC_ADMIN_PASSWORD') ?: '');

// === 主持人顯示名稱 ===
define('HOST_DISPLAY_NAME', 'Jason');

// === 時區（影響排程解析與顯示）===
date_default_timezone_set('Asia/Taipei');
