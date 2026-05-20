<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /accounts'); exit; }
Auth::csrfCheck();

$back = function (string $key, string $msg) {
  $_SESSION[$key] = $msg;
  header('Location: /accounts');
  exit;
};

$id = trim($_POST['id'] ?? '');
$target = Users::find($id);
if (!$target) $back('acc_err', '找不到該帳號。');
if ($id === $me['id']) $back('acc_err', '不能刪除自己的帳號。');
if ($target['role'] === 'admin' && Users::adminCount() <= 1) $back('acc_err', '系統至少需保留一位管理員。');

Users::delete($id);
Audit::log('account_delete', "帳號 {$target['username']}（{$target['email']}）");
$back('acc_msg', "已刪除帳號 {$target['username']}。");
