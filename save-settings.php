<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/logship.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/recordings.php';

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
  Settings::setMeetingRetentionDays((int)($_POST['meeting_retention_days'] ?? 365));
  Settings::setGuestPollSeconds((int)($_POST['guest_poll_seconds'] ?? 30));
  Audit::log('settings_update', '會議室介面：語言 ' . (Settings::MEETING_LANGS[Settings::getMeetingLang()] ?? '') . '、記錄保留 ' . Settings::getMeetingRetentionDays() . ' 天、等候檢查 ' . Settings::getGuestPollSeconds() . ' 秒');
  $back('set_msg', '會議室設定已更新。');
}

if ($section === 'recording') {
  Settings::setRecorderName($_POST['recorder_name'] ?? '');
  Audit::log('settings_update', '錄製設定：錄製者顯示名稱「' . Settings::getRecorderName() . '」');
  $back('set_msg', '錄製設定已更新。');
}

if ($section === 'jibri') {
  $jurl = trim($_POST['jibri_url'] ?? '');
  Settings::setJibri($jurl, $_POST['jibri_token'] ?? '');
  Audit::log('settings_update', 'Jibri 錄影服務設定更新（' . (Settings::getJibri()['url'] ?: '未設定') . '）');
  if ($jurl === '') $back('set_msg', 'Jibri 錄影服務設定已清除。');
  if (!Settings::hasJibri()) $back('set_err', '已儲存服務 URL，但尚未設定 Token，請填入 Token 才能啟用。');
  $ok = Recordings::ping();
  $back($ok ? 'set_msg' : 'set_err',
        $ok ? 'Jibri 錄影服務已連線。' : 'Jibri 服務設定已儲存，但目前無法連線，請確認 URL、Token 與來源 IP 允許清單。');
}

if ($section === 'recording_retention') {
  if (!Settings::hasJibri()) $back('set_err', '尚未設定 Jibri 錄影服務。');
  $conf = [
    'time_enabled'     => !empty($_POST['time_enabled']),
    'time_days'        => (int)($_POST['time_days'] ?? 30),
    'cap_enabled'      => !empty($_POST['cap_enabled']),
    'cap_mode'         => in_array($_POST['cap_mode'] ?? '', ['min_free_gb', 'max_used_gb'], true) ? $_POST['cap_mode'] : 'min_free_gb',
    'cap_value_gb'     => (int)($_POST['cap_value_gb'] ?? 10),
    'orphan_auto'      => !empty($_POST['orphan_auto']),
    'orphan_age_hours' => (int)($_POST['orphan_age_hours'] ?? 24),
  ];
  $saved = Recordings::setConfig($conf);
  if ($saved === null) $back('set_err', '保留政策儲存失敗（Jibri 服務無法連線）。');
  Audit::log('settings_update', '錄影保留政策更新：時間清理 ' . ($conf['time_enabled'] ? '開（' . $conf['time_days'] . '天）' : '關') . '、容量清理 ' . ($conf['cap_enabled'] ? '開' : '關') . '、殘留清理 ' . ($conf['orphan_auto'] ? '開' : '關'));
  $back('set_msg', '錄影保留政策已更新。');
}

if ($section === 'meeting_custom') {
  if (Settings::getJaas()['mode'] !== 'selfhosted') $back('set_err', '會議室自訂僅適用於自建 Jitsi Meet 模式。');
  $tb = is_array($_POST['tb'] ?? null) ? $_POST['tb'] : [];
  $cur_mc = Settings::getMeetingCustom();   // logo 已移到 Jitsi 伺服器設定，沿用既有值不從表單讀
  Settings::setMeetingCustom([
    'logo_mode'  => $cur_mc['logo_mode'] ?? 'site',
    'logo_url'   => $cur_mc['logo_url'] ?? '',
    'logo_link'  => $cur_mc['logo_link'] ?? '',
    'mute_audio' => !empty($_POST['mute_audio']),
    'mute_video' => !empty($_POST['mute_video']),
    'resolution' => (int)($_POST['resolution'] ?? 1080),
    'default_view' => $_POST['default_view'] ?? 'speaker',
    'toolbar'    => $tb,
  ]);
  Audit::log('settings_update', '會議室自訂（自建 Jitsi Meet）');
  $back('set_msg', '會議室自訂已更新。');
}

if ($section === 'login_path') {
  $p = trim((string)($_POST['login_path'] ?? ''), '/');
  if ($p === '') $p = Settings::DEFAULT_LOGIN_PATH;
  if (!Settings::validLoginPath($p)) $back('set_err', '登入路徑格式不合法（僅允許英數與 . _ -，長度 1–64）。');
  // 避免與既有頁面 / 實體檔衝突而讓登入頁無法到達（jt-login 本身是登入處理器，允許）。
  if ($p !== 'jt-login' && file_exists(__DIR__ . '/' . $p . '.php')) {
    $back('set_err', '此路徑與既有頁面衝突，請換一個。');
  }
  Settings::setLoginPath($p);
  // 基於安全不在稽核 / SIEM 記錄實際路徑值。
  Audit::log('settings_update', '登入路徑已變更（為安全不記錄實際值）');
  $back('set_msg', '登入路徑已更新，請改用新路徑登入；忘記時可用 CLI 還原。');
}

if ($section === 'jaas') {
  $mode   = in_array($_POST['mode'] ?? 'jaas', ['jaas', 'selfhosted'], true) ? $_POST['mode'] : 'jaas';
  $shAuth = in_array($_POST['sh_auth'] ?? 'none', ['none', 'jwt'], true) ? $_POST['sh_auth'] : 'none';
  // 以既有設定為基礎合併，兩模式設定分開存、互不覆蓋
  $cur = Settings::getSection('jaas');
  $cur['_v']        = Settings::JAAS_SCHEMA_VERSION;
  $cur['mode']      = $mode;
  $cur['site_url']  = rtrim(trim($_POST['site_url'] ?? ''), '/');   // 共用
  // JaaS 專屬
  $cur['domain']    = trim($_POST['domain'] ?? '') ?: '8x8.vc';
  $cur['app_id']    = trim($_POST['app_id'] ?? '');
  $cur['kid']       = trim($_POST['kid'] ?? '');
  // 自建專屬
  $cur['sh_domain'] = trim($_POST['sh_domain'] ?? '');
  $cur['sh_app_id'] = trim($_POST['sh_app_id'] ?? '');
  $cur['sh_auth']   = $shAuth;
  $cur['sh_secret'] = (string)($_POST['sh_secret'] ?? '');
  $cur['sh_sub']    = trim($_POST['sh_sub'] ?? '');
  Settings::setSection('jaas', $cur);
  Audit::log('settings_update', '連線模式設定（' . $mode . '）');
  $back('set_msg', '連線設定已更新。');
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
