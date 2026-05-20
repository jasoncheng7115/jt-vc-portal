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

// 更新顯示名稱（此表單不含密碼欄位）
if (array_key_exists('display_name', $_POST) && !isset($_POST['new_password'])) {
  $dn = trim($_POST['display_name']) ?: $me['username'];
  Users::update($me['id'], ['display_name' => $dn]);
  Audit::log('profile_update', "顯示名稱→{$dn}");
  $back('profile_msg', '顯示名稱已更新。');
}

$cur  = (string)($_POST['current_password'] ?? '');
$np   = (string)($_POST['new_password'] ?? '');
$np2  = (string)($_POST['new_password2'] ?? '');

if (!Users::verifyPassword($me, $cur)) $back('profile_err', '目前密碼不正確。');
if (strlen($np) < 8) $back('profile_err', '新密碼至少需 8 字。');
if ($np !== $np2) $back('profile_err', '兩次輸入的新密碼不一致。');

Users::update($me['id'], ['password' => $np]);
Audit::log('password_change', '變更自己的密碼');
$back('profile_msg', '密碼已更新。');
