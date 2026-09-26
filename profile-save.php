<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /profile'); exit; }
Auth::csrfCheck();

$back = function (string $key, string $msg) {
  $_SESSION[$key] = $msg;
  header('Location: /profile');
  exit;
};

// 介面語言偏好（'' = 自動：cookie / 瀏覽器判斷）
if (array_key_exists('lang', $_POST) && !isset($_POST['new_password']) && !array_key_exists('display_name', $_POST)) {
  $l = I18n::normalize((string)$_POST['lang']);
  Users::update($me['id'], ['lang' => $l]);
  if ($l !== null) { $_SESSION['lang'] = $l; I18n::set($l); I18n::remember($l); }
  else unset($_SESSION['lang']);
  Audit::log('profile_update', t('介面語言→{lang}', ['lang' => $l ?? 'auto']));
  $back('profile_msg', t('介面語言已更新。'));
}

// 更新顯示名稱（此表單不含密碼欄位）
if (array_key_exists('display_name', $_POST) && !isset($_POST['new_password'])) {
  $dn = trim($_POST['display_name']) ?: $me['username'];
  Users::update($me['id'], ['display_name' => $dn]);
  Audit::log('profile_update', t('顯示名稱→{name}', ['name' => $dn]));
  $back('profile_msg', t('顯示名稱已更新。'));
}

$cur  = (string)($_POST['current_password'] ?? '');
$np   = (string)($_POST['new_password'] ?? '');
$np2  = (string)($_POST['new_password2'] ?? '');

if (!Users::verifyPassword($me, $cur)) $back('profile_err', t('目前密碼不正確。'));
if (strlen($np) < 10) $back('profile_err', t('新密碼至少需 10 字。'));
if ($np !== $np2) $back('profile_err', t('兩次輸入的新密碼不一致。'));

Users::update($me['id'], ['password' => $np]);
Audit::log('password_change', t('變更自己的密碼'));
$back('profile_msg', t('密碼已更新。'));
