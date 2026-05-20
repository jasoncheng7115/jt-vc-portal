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

$cur = Settings::getSection('site');

// 品牌名稱
$name = trim((string)($_POST['brand_name'] ?? ''));
$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
$name = mb_substr($name, 0, 40);
$cur['brand_name'] = $name;

// 移除自訂 logo
if (!empty($_POST['remove_logo'])) {
  @unlink(DATA_DIR . '/logo.img');
  $cur['logo_mime'] = '';
  $cur['logo_v'] = (int)($cur['logo_v'] ?? 0) + 1;
  Settings::setSection('site', $cur);
  Audit::log('site_update', '恢復預設 logo');
  $back('set_msg', '已恢復預設 logo。');
}

// 上傳新 logo
if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
  if (($_FILES['logo']['error'] ?? 1) !== UPLOAD_ERR_OK) $back('set_err', 'logo 上傳失敗（設定其他欄位已儲存）。');
  if ($_FILES['logo']['size'] > 2 * 1024 * 1024) $back('set_err', 'logo 檔案請小於 2MB。');

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($_FILES['logo']['tmp_name']);
  $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
  if (!in_array($mime, $allowed, true)) $back('set_err', '僅接受 PNG / JPEG / WebP / GIF 圖片。');

  if (!move_uploaded_file($_FILES['logo']['tmp_name'], DATA_DIR . '/logo.img')) {
    $back('set_err', '無法儲存 logo，請確認資料卷權限。');
  }
  @chmod(DATA_DIR . '/logo.img', 0644);
  $cur['logo_mime'] = $mime;
  $cur['logo_v'] = (int)($cur['logo_v'] ?? 0) + 1;
  Settings::setSection('site', $cur);
  Audit::log('site_update', "名稱「{$name}」+ 更新 logo");
  $back('set_msg', '站台設定已更新（含 logo）。');
}

Settings::setSection('site', $cur);
Audit::log('site_update', "名稱「{$name}」");
$back('set_msg', '站台設定已更新。');
