<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /settings'); exit; }
Auth::csrfCheck();

$back = function (string $key, string $msg) {
  $_SESSION[$key] = $msg;
  header('Location: /settings');
  exit;
};

if (empty($_FILES['settings_file']['tmp_name']) || !is_uploaded_file($_FILES['settings_file']['tmp_name'])) {
  $back('set_err', '請選擇要匯入的設定檔。');
}
if (($_FILES['settings_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
  $back('set_err', '設定檔上傳失敗。');
}
if ($_FILES['settings_file']['size'] > 1024 * 1024) {
  $back('set_err', '設定檔請小於 1MB。');
}

$raw  = @file_get_contents($_FILES['settings_file']['tmp_name']);
$json = json_decode((string)$raw, true);
if (!is_array($json)) {
  $back('set_err', '設定檔格式錯誤（非有效 JSON）。');
}

// 接受兩種格式：① 含 _type 包裝的匯出檔（建議）；② 純 settings 物件
if (isset($json['_type'])) {
  if ($json['_type'] !== 'jt-vc-portal-settings' || !isset($json['settings']) || !is_array($json['settings'])) {
    $back('set_err', '這不是 jt-vc-portal 的設定匯出檔。');
  }
  $settings = $json['settings'];
  $logo = (isset($json['logo']) && is_array($json['logo'])) ? $json['logo'] : null;
} else {
  $settings = $json;
  $logo = null;
}

Settings::importData($settings);

// 還原自訂 logo 圖檔（若匯出檔含 logo）
if ($logo !== null && !empty($logo['data_b64']) && !empty($logo['mime'])) {
  $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
  if (in_array($logo['mime'], $allowed, true)) {
    $bytes = base64_decode((string)$logo['data_b64'], true);
    if ($bytes !== false && strlen($bytes) <= 2 * 1024 * 1024) {
      @file_put_contents(DATA_DIR . '/logo.img', $bytes);
      @chmod(DATA_DIR . '/logo.img', 0644);
    }
  }
}

Audit::log('settings_import', '匯入系統設定');
$back('set_msg', '系統設定已匯入並套用。');
