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
if (!$target) $back('acc_err', t('找不到該帳號。'));
if ($id === $me['id']) $back('acc_err', t('不能刪除自己的帳號。'));
if ($target['role'] === 'admin' && Users::adminCount() <= 1) $back('acc_err', t('系統至少需保留一位管理員。'));

Users::delete($id);
Audit::log('account_delete', t('帳號 {user}（{email}）', ['user' => $target['username'], 'email' => $target['email']]));
$back('acc_msg', t('已刪除帳號 {user}。', ['user' => $target['username']]));
