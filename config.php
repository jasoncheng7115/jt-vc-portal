<?php
// === 版本 ===
// 每次有更新都要推進版本號（patch++ / 功能 minor++）。
define('APP_VERSION', '1.6.3');
define('APP_GITHUB_URL', 'https://github.com/jasoncheng7115/jt-vc-portal');

// === CSP 預設（A05 縱深防禦）===
// 每個 PHP 回應先帶最嚴格的 CSP；會輸出頁面的 render_head() / send_meeting_csp() 以同名 header 覆蓋成含 nonce 的版本。
// 確保 403 / 轉址 / JSON 等沒經過版面的回應也有 CSP（ZAP 10038）。
if (PHP_SAPI !== 'cli' && !headers_sent()) {
  header("Content-Security-Policy: default-src 'none'; style-src-attr 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

// === JaaS (8x8) 連線設定：改由管理介面設定，存於 settings.json，不再寫死 ===
require_once __DIR__ . '/lib/settings.php';
Settings::migrate();   // 升級後自動把舊版設定轉成新結構（冪等；無變更不寫檔）
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

// === 反向代理信任清單（A01/A07）===
// 逗號分隔的 IP / CIDR；只有當 REMOTE_ADDR 落在此清單時，才採信 X-Real-IP /
// X-Forwarded-For / X-Forwarded-Proto 標頭（否則用 REMOTE_ADDR，避免來源 IP 偽造
// 繞過 fail2ban 或污染稽核）。留空＝沿用舊行為（盲信標頭）——務必同時做網路隔離，
// 讓容器埠只有反向代理可達。範例：JTVC_TRUSTED_PROXIES=127.0.0.1,172.16.0.0/12
define('TRUSTED_PROXIES', getenv('JTVC_TRUSTED_PROXIES') ?: '');

// === Session 逾時（A07）===
// 閒置逾時：超過此秒數無活動即登出（預設 30 分鐘）。
define('SESSION_IDLE_SECONDS', (int)(getenv('JTVC_SESSION_IDLE') ?: 1800));
// 絕對逾時：登入後超過此秒數一律重新登入（預設 12 小時）。
define('SESSION_ABSOLUTE_SECONDS', (int)(getenv('JTVC_SESSION_ABSOLUTE') ?: 43200));

// === 主持人顯示名稱 ===
define('HOST_DISPLAY_NAME', 'Jason');

// === 時區（影響排程解析與顯示）===
date_default_timezone_set('Asia/Taipei');
