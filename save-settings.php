<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/logship.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /settings'); exit; }
Auth::csrfCheck();

$back = function (string $key, string $msg) {
  $_SESSION[$key] = $msg;
  header('Location: /settings');
  exit;
};

$section = $_POST['section'] ?? '';
$action  = $_POST['action'] ?? 'save';

if ($section === 'meeting') {
  Settings::setMeetingLang($_POST['meeting_lang'] ?? 'zhTW');
  Audit::log('settings_update', '會議室預設語言：' . (Settings::MEETING_LANGS[Settings::getMeetingLang()] ?? ''));
  $back('set_msg', '會議室語言已更新。');
}

if ($section === 'jaas') {
  Settings::setSection('jaas', [
    'app_id'   => trim($_POST['app_id'] ?? ''),
    'kid'      => trim($_POST['kid'] ?? ''),
    'domain'   => trim($_POST['domain'] ?? '') ?: '8x8.vc',
    'site_url' => rtrim(trim($_POST['site_url'] ?? ''), '/'),
  ]);
  Audit::log('settings_update', '連線模式設定（' . ($_POST['mode'] ?? '') . '）');
  $back('set_msg', 'JaaS 連線設定已更新。');
}

if ($section === 'plan') {
  Settings::setPlanLimit((int)($_POST['plan_mau_limit'] ?? 25));
  if (isset($_POST['billing_start_day'])) {
    Settings::setBillingStartDay((int)$_POST['billing_start_day']);
  }
  Audit::log('settings_update', '方案上限 / 計費週期');
  $back('set_msg', '方案設定已更新。');
}

if ($section === 'usage_baseline') {
  $val = max(0, (int)($_POST['current_value'] ?? 0));
  Usage::setCurrentValue($val);
  Audit::log('usage_baseline', "校正本期用量為 {$val}");
  $back('set_msg', '本期用量已校正。');
}

if ($section === 'smtp') {
  $cfg = [
    'enabled'    => !empty($_POST['enabled']),
    'host'       => trim($_POST['host'] ?? ''),
    'port'       => (int)($_POST['port'] ?? 587),
    'security'   => $_POST['security'] ?? 'starttls',
    'username'   => trim($_POST['username'] ?? ''),
    'password'   => (string)($_POST['password'] ?? ''),
    'from_email' => trim($_POST['from_email'] ?? ''),
    'from_name'  => trim($_POST['from_name'] ?? 'JT 視訊會議'),
    'subject_tpl'=> trim($_POST['subject_tpl'] ?? '') ?: Mailer::DEFAULT_SUBJECT_TPL,
    'body_tpl'   => ($_POST['body_tpl'] ?? '') !== '' ? (string)$_POST['body_tpl'] : Mailer::DEFAULT_BODY_TPL,
  ];
  Settings::setSection('smtp', $cfg);
  Audit::log('settings_update', 'SMTP 寄信（' . ($cfg['enabled'] ? '啟用' : '停用') . '）');

  if ($action === 'test') {
    $to = trim($_POST['test_to'] ?? '');
    if (!Mailer::isValidEmail($to)) $back('set_err', '請填寫有效的測試收件人。設定已儲存。');
    [$ok, $e] = Mailer::sendInvite([
      'to' => $to,
      'subject' => 'JT 視訊會議 — SMTP 測試',
      'bodyText' => "這是一封測試信，若您收到代表 SMTP 設定正確。\n\n— JT 視訊會議",
      'icsContent' => '',
    ], $cfg);
    $ok ? $back('set_msg', "測試信已寄出至 {$to}。") : $back('set_err', "測試寄信失敗：{$e}（設定已儲存）");
  }
  $back('set_msg', 'SMTP 設定已儲存。');
}

if ($section === 'logship') {
  $cfg = [
    'enabled'  => !empty($_POST['enabled']),
    'host'     => trim($_POST['host'] ?? ''),
    'port'     => (int)($_POST['port'] ?? 514),
    'protocol' => $_POST['protocol'] ?? 'udp',
    'format'   => $_POST['format'] ?? 'syslog',
    'facility' => (int)($_POST['facility'] ?? 16),
  ];
  Settings::setSection('logship', $cfg);
  Audit::log('settings_update', '登入記錄外拋（' . ($cfg['enabled'] ? '啟用 ' . $cfg['format'] . '/' . $cfg['protocol'] : '停用') . '）');

  if ($action === 'test') {
    [$ok, $e] = LogShip::send([
      'type' => 'test', 'result' => 'test', 'user' => $me['email'],
      'ip' => Auth::clientIp(), 'severity' => 'info',
      'message' => 'jaas-auth logship test event',
    ], $cfg);
    $ok ? $back('set_msg', '測試事件已送出。') : $back('set_err', "測試送出失敗：{$e}（設定已儲存）");
  }
  $back('set_msg', '外拋設定已儲存。');
}

$back('set_err', '未知的設定區塊。');
