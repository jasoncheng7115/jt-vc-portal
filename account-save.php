<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/mailer.php';
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

if ($id === '') {
  // 新增
  $username = trim($_POST['username'] ?? '');
  $display  = trim($_POST['display_name'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $role     = $_POST['role'] ?? 'host';
  if ($username === '' || !Mailer::isValidEmail($email)) $back('acc_err', '帳號名稱或 Email 格式不正確。');
  if (strlen($password) < 8) $back('acc_err', '密碼至少需 8 字。');
  if (Users::findByLogin($email) || Users::findByLogin($username)) $back('acc_err', '帳號或 Email 已存在。');
  Users::create(['username' => $username, 'display_name' => $display, 'email' => $email, 'password' => $password, 'role' => $role]);
  Audit::log('account_create', "帳號 {$username}（{$email}，{$role}）");
  $back('acc_msg', "已建立帳號 {$username}。");
}

// 編輯既有
$target = Users::find($id);
if (!$target) $back('acc_err', '找不到該帳號。');

$role = $_POST['role'] ?? $target['role'];
$disabled = ($_POST['disabled'] ?? '0') === '1';
$password = (string)($_POST['password'] ?? '');

// 防呆：不能把唯一的啟用管理員降級 / 停用 / 改成自己停用
$wouldLoseAdmin = ($target['role'] === 'admin' && (!empty($disabled) || $role !== 'admin'));
if ($wouldLoseAdmin && Users::adminCount() <= 1) {
  $back('acc_err', '系統至少需保留一位啟用中的管理員。');
}
if ($target['id'] === $me['id'] && $disabled) {
  $back('acc_err', '不能停用自己的帳號。');
}

$fields = ['role' => $role, 'disabled' => $disabled];
if (array_key_exists('display_name', $_POST)) {
  $fields['display_name'] = trim($_POST['display_name']) ?: $target['username'];
}
if (array_key_exists('email', $_POST)) {
  $email = trim($_POST['email']);
  if (!Mailer::isValidEmail($email)) $back('acc_err', 'Email 格式不正確。');
  // 不可與其他帳號的 email / username 重複
  $clash = Users::findByLogin($email);
  if ($clash && $clash['id'] !== $id) $back('acc_err', '該 Email 已被其他帳號使用。');
  $fields['email'] = $email;
}
if ($password !== '') {
  if (strlen($password) < 8) $back('acc_err', '密碼至少需 8 字。');
  $fields['password'] = $password;
}
Users::update($id, $fields);
$changed = [];
if ($role !== $target['role']) $changed[] = "角色→{$role}";
if ($disabled !== !empty($target['disabled'])) $changed[] = $disabled ? '停用' : '啟用';
if ($password !== '') $changed[] = '重設密碼';
if (array_key_exists('email', $_POST) && $email !== ($target['email'] ?? '')) $changed[] = 'Email';
if (array_key_exists('display_name', $_POST)) $changed[] = '顯示名稱';
Audit::log('account_update', "帳號 {$target['username']}：" . (implode('、', $changed) ?: '無變更'));
$back('acc_msg', '帳號已更新。');
