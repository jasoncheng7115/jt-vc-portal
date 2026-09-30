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
  if ($username === '' || !Mailer::isValidEmail($email)) $back('acc_err', t('帳號名稱或 Email 格式不正確。'));
  if (strlen($password) < 10) $back('acc_err', t('密碼至少需 10 字。'));
  if (Users::findByLogin($email) || Users::findByLogin($username)) $back('acc_err', t('帳號或 Email 已存在。'));
  Users::create(['username' => $username, 'display_name' => $display, 'email' => $email, 'password' => $password, 'role' => $role]);
  Audit::log('account_create', tk('帳號 {user}（{email}，{role}）', ['user' => $username, 'email' => $email, 'role' => $role]));
  $back('acc_msg', t('已建立帳號 {user}。', ['user' => $username]));
}

// 編輯既有
$target = Users::find($id);
if (!$target) $back('acc_err', t('找不到該帳號。'));

$role = $_POST['role'] ?? $target['role'];
$disabled = ($_POST['disabled'] ?? '0') === '1';
$password = (string)($_POST['password'] ?? '');

// 防呆：不能把唯一的啟用管理員降級 / 停用 / 改成自己停用
$wouldLoseAdmin = ($target['role'] === 'admin' && (!empty($disabled) || $role !== 'admin'));
if ($wouldLoseAdmin && Users::adminCount() <= 1) {
  $back('acc_err', t('系統至少需保留一位啟用中的管理員。'));
}
if ($target['id'] === $me['id'] && $disabled) {
  $back('acc_err', t('不能停用自己的帳號。'));
}

$fields = ['role' => $role, 'disabled' => $disabled];
if (array_key_exists('transcribe', $_POST)) {
  $tp = (string)$_POST['transcribe'];
  if (!in_array($tp, ['none', 'manual', 'auto'], true)) $back('acc_err', t('參數錯誤。'));
  $fields['transcribe'] = $tp;
}
if (array_key_exists('display_name', $_POST)) {
  $fields['display_name'] = trim($_POST['display_name']) ?: $target['username'];
}
if (array_key_exists('email', $_POST)) {
  $email = trim($_POST['email']);
  if (!Mailer::isValidEmail($email)) $back('acc_err', t('Email 格式不正確。'));
  // 不可與其他帳號的 email / username 重複
  $clash = Users::findByLogin($email);
  if ($clash && $clash['id'] !== $id) $back('acc_err', t('該 Email 已被其他帳號使用。'));
  $fields['email'] = $email;
}
if ($password !== '') {
  if (strlen($password) < 10) $back('acc_err', t('密碼至少需 10 字。'));
  $fields['password'] = $password;
}
$revoke = !empty($_POST['revoke']);
if ($revoke) $fields['revoke_sessions'] = true;
Users::update($id, $fields);
$changed = [];
if ($role !== $target['role']) $changed[] = t('角色→{role}', ['role' => $role]);
if (isset($fields['transcribe']) && $fields['transcribe'] !== ($target['transcribe'] ?? 'none')) $changed[] = t('逐字稿權限→{p}', ['p' => $fields['transcribe']]);
if ($disabled !== !empty($target['disabled'])) $changed[] = $disabled ? t('停用') : t('啟用');
if ($password !== '') $changed[] = t('重設密碼');
if ($revoke) $changed[] = t('強制登出所有裝置');
if (array_key_exists('email', $_POST) && $email !== ($target['email'] ?? '')) $changed[] = 'Email';
if (array_key_exists('display_name', $_POST)) $changed[] = t('顯示名稱');
Audit::log('account_update', tk('帳號 {user}：{changes}', ['user' => $target['username'], 'changes' => (implode(t('、'), $changed) ?: t('無變更'))]));
$back('acc_msg', t('帳號已更新。'));
