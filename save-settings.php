<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/jtlw.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/logship.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/oidc.php';

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
  Settings::setMeetingLang($_POST['meeting_lang'] ?? Settings::MEETING_LANG_UI);
  Settings::setMeetingRetentionDays((int)($_POST['meeting_retention_days'] ?? 365));
  if (isset($_POST['audit_retention_days'])) Settings::setAuditRetentionDays((int)$_POST['audit_retention_days']);
  Settings::setGuestPollSeconds((int)($_POST['guest_poll_seconds'] ?? 30));
  Audit::log('settings_update', tk('會議室介面：語言 {lang}、記錄保留 {days} 天、等候檢查 {secs} 秒', [
    'lang' => Settings::MEETING_LANGS[Settings::getMeetingLang()] ?? '',
    'days' => Settings::getMeetingRetentionDays(),
    'secs' => Settings::getGuestPollSeconds(),
  ]));
  $back('set_msg', t('會議室設定已更新。'));
}

if ($section === 'recording') {
  Settings::setRecorderName($_POST['recorder_name'] ?? '');
  Audit::log('settings_update', tk('錄製設定：錄製者顯示名稱「{name}」', ['name' => Settings::getRecorderName()]));
  $back('set_msg', t('錄製設定已更新。'));
}

if ($section === 'jibri') {
  $jurl = trim($_POST['jibri_url'] ?? '');
  Settings::setJibri($jurl, $_POST['jibri_token'] ?? '');
  Audit::log('settings_update', tk('Jibri 錄影服務設定更新（{url}）', ['url' => Settings::getJibri()['url'] ?: t('未設定')]));
  if ($jurl === '') $back('set_msg', t('Jibri 錄影服務設定已清除。'));
  if (!Settings::hasJibri()) $back('set_err', t('已儲存服務 URL，但尚未設定 Token，請填入 Token 才能啟用。'));
  $ok = Recordings::ping();
  $back($ok ? 'set_msg' : 'set_err',
        $ok ? t('Jibri 錄影服務已連線。') : t('Jibri 服務設定已儲存，但目前無法連線，請確認 URL、Token 與來源 IP 允許清單。'));
}

if ($section === 'recording_retention') {
  if (!Settings::hasJibri()) $back('set_err', t('尚未設定 Jibri 錄影服務。'));
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
  if ($saved === null) $back('set_err', t('保留政策儲存失敗（Jibri 服務無法連線）。'));
  Audit::log('settings_update', tk('錄影保留政策更新：時間清理 {time}、容量清理 {cap}、殘留清理 {orphan}', [
    'time'   => $conf['time_enabled'] ? t('開（{days}天）', ['days' => $conf['time_days']]) : t('關'),
    'cap'    => $conf['cap_enabled'] ? t('開') : t('關'),
    'orphan' => $conf['orphan_auto'] ? t('開') : t('關'),
  ]));
  $back('set_msg', t('錄影保留政策已更新。'));
}

if ($section === 'meeting_custom') {
  if (Settings::getJaas()['mode'] !== 'selfhosted') $back('set_err', t('會議室自訂僅適用於自建 Jitsi Meet 模式。'));
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
    'bw_save_off' => !empty($_POST['bw_save_off']),
    'hq_small'   => !empty($_POST['hq_small']),
    'toolbar'    => $tb,
  ]);
  Audit::log('settings_update', tk('會議室自訂（自建 Jitsi Meet）'));
  $back('set_msg', t('會議室自訂已更新。'));
}

if ($section === 'oidc') {
  $v = $_POST;
  $v['issuer'] = rtrim(trim((string)($v['issuer'] ?? '')), '/');
  if (!empty($v['enabled'])) {
    if (!filter_var($v['issuer'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v['issuer'])) $back('set_err', t('Issuer 網址格式不正確。'));
    if (trim((string)($v['client_id'] ?? '')) === '') $back('set_err', t('請填寫 Client ID。'));
    if (trim((string)($v['admin_groups'] ?? '')) === '' && trim((string)($v['host_groups'] ?? '')) === '') $back('set_err', t('請至少設定一個管理員或主持人群組。'));
  }
  if (!empty($v['sso_only'])) {
    // 緊急用管理員保護：必須至少有一位啟用中的「本地」管理員，否則 IdP 故障時無人能登入
    $localAdmins = array_filter(Users::all(), fn($u) => ($u['role'] ?? '') === 'admin' && empty($u['disabled']) && !Users::isSso($u));
    if (!$localAdmins) $back('set_err', t('啟用「僅限單一登入」前，必須至少保留一位啟用中的本地管理員（緊急用）。'));
    foreach (preg_split('/\s*,\s*/', (string)($v['local_login_cidrs'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $cidr) {
      if (!preg_match('#^[0-9A-Fa-f:.]+(/\d{1,3})?$#', $cidr) || @inet_pton(explode('/', $cidr)[0]) === false) $back('set_err', t('IP / CIDR 格式不正確：{cidr}', ['cidr' => $cidr]));
    }
  }
  Settings::setOidc($v);
  Audit::log('settings_update', tk('單一登入設定（{state}）', ['state' => !empty($v['enabled']) ? t('啟用') : t('停用')]) . (!empty($v['sso_only']) ? t('（僅限單一登入）') : ''));
  if ($action === 'test') {
    try {
      $d = Oidc::discovery(true);
      $keys = Oidc::jwks(true);
      $back('set_msg', t('連線成功：已取得 IdP 設定與 {n} 把簽章金鑰。', ['n' => count($keys)]));
    } catch (Throwable $e) {
      Audit::log('sso_fail', 'oidc test: ' . Oidc::safeReason($e), ['result' => 'fail']);
      $back('set_err', t('連線失敗：{reason}', ['reason' => Oidc::safeReason($e)]));
    }
  }
  $back('set_msg', t('單一登入設定已儲存。'));
}

if ($section === 'transcribe') {
  $v = $_POST;
  $v['jtlw_url'] = rtrim(preg_replace('#/api/v1/?$#', '', trim((string)($v['jtlw_url'] ?? ''))), '/');
  $v['summarize'] = !empty($v['summarize']);
  if ($v['jtlw_url'] !== '' && (!filter_var($v['jtlw_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://[^/]+$#i', $v['jtlw_url']))) $back('set_err', t('語音服務網址格式不正確（例：https://10.0.0.30:8790）。'));
  $ca = trim((string)($v['jtlw_ca'] ?? ''));
  if ($ca !== '' && (!str_contains($ca, 'BEGIN CERTIFICATE') || @openssl_x509_read($ca) === false)) $back('set_err', t('憑證不是有效的 PEM 格式。'));
  $key = trim((string)($v['jtlw_key'] ?? ''));
  if (stripos($key, 'bearer ') === 0) $v['jtlw_key'] = trim(substr($key, 7));      // 誤貼「Bearer 」前綴
  $cur = Settings::getTranscribe();
  if (!empty($v['enabled']) && ($v['jtlw_url'] === '' || ($v['jtlw_key'] === '' && $cur['jtlw_key'] === ''))) $back('set_err', t('啟用前請填寫語音服務網址與金鑰。'));
  if (!empty($v['enabled']) && !Settings::hasJibri()) $back('set_err', t('逐字稿需要自建 Jibri 錄影服務，請先完成錄製設定。'));
  $v['webhook_endpoint_id'] = $cur['webhook_endpoint_id'];                          // 由「註冊 webhook」設定，不從表單改
  $v['webhook_secret'] = '';
  $v['backend'] = 'jtlw';
  Settings::setTranscribe($v);
  Audit::log('settings_update', tk('逐字稿與摘要設定（{state}）', ['state' => !empty($v['enabled']) ? t('啟用') : t('停用')]));
  if ($action === 'test' || $action === 'webhook') {
    try {
      $cap = Jtlw::capabilities();
      $prof = Jtlw::profiles();
      $ids = array_map(fn($p) => (string)($p['id'] ?? ''), (array)($prof['profiles'] ?? $prof));
      $cfg = Settings::getTranscribe();
      if (!in_array($cfg['profile_id'], $ids, true)) $back('set_err', t('連線成功，但語音服務沒有辨識模式「{p}」（可用：{list}）。', ['p' => $cfg['profile_id'], 'list' => implode(', ', $ids)]));
      if ($action === 'webhook') {
        $url = rtrim(SITE_URL, '/') . '/jtlw-webhook';
        $wh = Jtlw::createWebhook($url);
        Settings::setTranscribe(['webhook_endpoint_id' => (string)$wh['endpoint_id'], 'webhook_secret' => (string)$wh['secret']], true);
        Audit::log('settings_update', tk('已向語音服務註冊 webhook：{url}', ['url' => $url]));
        $back('set_msg', t('已註冊 webhook（{url}）。語音服務做完會通知本系統；另有每分鐘的排程保底。', ['url' => $url]));
      }
      $back('set_msg', t('連線成功：語音服務 API {rev}，目前排隊 {q} 件。', ['rev' => (string)($cap['api_revision'] ?? '?'), 'q' => (int)($cap['queue']['depth'] ?? 0)]));
    } catch (JtlwError $e) {
      $back('set_err', t('連線失敗：{reason}', ['reason' => Jtlw::describe($e->errCode(), $e->reason()) . ($e->status ? ' (HTTP ' . $e->status . ')' : '')]));
    }
  }
  $back('set_msg', t('逐字稿與摘要設定已儲存。'));
}

if ($section === 'login_path') {
  $p = trim((string)($_POST['login_path'] ?? ''), '/');
  if ($p === '') $p = Settings::DEFAULT_LOGIN_PATH;
  if (!Settings::validLoginPath($p)) $back('set_err', t('登入路徑格式不合法（僅允許英數與 . _ -，長度 1–64）。'));
  // 避免與既有頁面 / 實體檔衝突而讓登入頁無法到達（jt-login 本身是登入處理器，允許）。
  if ($p !== 'jt-login' && file_exists(__DIR__ . '/' . $p . '.php')) {
    $back('set_err', t('此路徑與既有頁面衝突，請換一個。'));
  }
  Settings::setLoginPath($p);
  // 基於安全不在稽核 / SIEM 記錄實際路徑值。
  Audit::log('settings_update', tk('登入路徑已變更（為安全不記錄實際值）'));
  $back('set_msg', t('登入路徑已更新，請改用新路徑登入；忘記時可用 CLI 還原。'));
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
  // 共享密鑰不回填到頁面；留空＝沿用既有值
  $newSecret = (string)($_POST['sh_secret'] ?? '');
  if ($newSecret !== '') $cur['sh_secret'] = $newSecret;
  $cur['sh_sub']    = trim($_POST['sh_sub'] ?? '');
  Settings::setSection('jaas', $cur);
  Audit::log('settings_update', tk('連線模式設定（{mode}）', ['mode' => $mode]));
  $back('set_msg', t('連線設定已更新。'));
}

if ($section === 'plan') {
  Settings::setPlanLimit((int)($_POST['plan_mau_limit'] ?? 25));
  if (isset($_POST['billing_start_day'])) {
    Settings::setBillingStartDay((int)$_POST['billing_start_day']);
  }
  Audit::log('settings_update', tk('方案上限 / 計費週期'));
  $back('set_msg', t('方案設定已更新。'));
}

if ($section === 'usage_baseline') {
  $val = max(0, (int)($_POST['current_value'] ?? 0));
  Usage::setCurrentValue($val);
  Audit::log('usage_baseline', tk('校正本期用量為 {value}', ['value' => $val]));
  $back('set_msg', t('本期用量已校正。'));
}

if ($section === 'smtp') {
  $cfg = [
    'enabled'    => !empty($_POST['enabled']),
    'host'       => trim($_POST['host'] ?? ''),
    'port'       => (int)($_POST['port'] ?? 587),
    'security'   => $_POST['security'] ?? 'starttls',
    'username'   => trim($_POST['username'] ?? ''),
    // 密碼不回填到頁面；留空＝沿用既有值，勾「清除」才清掉
    'password'   => !empty($_POST['password_clear']) ? ''
                    : ((string)($_POST['password'] ?? '') !== '' ? (string)$_POST['password'] : (Mailer::config()['password'] ?? '')),
    'from_email' => trim($_POST['from_email'] ?? ''),
    'from_name'  => trim((string)($_POST['from_name'] ?? '')),   // 空白＝依寄件者語言用預設名稱
    // 預設範本存空字串（寄信時依寄件者介面語言套用預設）；只有真正自訂才保存內容
    'subject_tpl'=> Mailer::isDefaultTpl(trim($_POST['subject_tpl'] ?? ''), 'subject') ? '' : trim($_POST['subject_tpl']),
    'body_tpl'   => Mailer::isDefaultTpl((string)($_POST['body_tpl'] ?? ''), 'body') ? '' : (string)$_POST['body_tpl'],
  ];
  Settings::setSection('smtp', $cfg);
  Audit::log('settings_update', $cfg['enabled'] ? t('SMTP 寄信（啟用）') : t('SMTP 寄信（停用）'));

  if ($action === 'test') {
    $to = trim($_POST['test_to'] ?? '');
    if (!Mailer::isValidEmail($to)) $back('set_err', t('請填寫有效的測試收件人。設定已儲存。'));
    [$ok, $e] = Mailer::sendInvite([
      'to' => $to,
      'subject' => t('JT 視訊會議 — SMTP 測試'),
      'bodyText' => t("這是一封測試信，若您收到代表 SMTP 設定正確。\n\n— JT 視訊會議"),
      'icsContent' => '',
    ], $cfg);
    $ok ? $back('set_msg', t('測試信已寄出至 {to}。', ['to' => $to])) : $back('set_err', t('測試寄信失敗：{error}（設定已儲存）', ['error' => $e]));
  }
  $back('set_msg', t('SMTP 設定已儲存。'));
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
  Audit::log('settings_update', $cfg['enabled']
    ? t('登入記錄外拋（啟用 {format}/{protocol}）', ['format' => $cfg['format'], 'protocol' => $cfg['protocol']])
    : t('登入記錄外拋（停用）'));

  if ($action === 'test') {
    [$ok, $e] = LogShip::send([
      'type' => 'test', 'result' => 'test', 'user' => $me['email'],
      'ip' => Auth::clientIp(), 'severity' => 'info',
      'message' => 'jaas-auth logship test event',
    ], $cfg);
    $ok ? $back('set_msg', t('測試事件已送出。')) : $back('set_err', t('測試送出失敗：{error}（設定已儲存）', ['error' => $e]));
  }
  $back('set_msg', t('外拋設定已儲存。'));
}

$back('set_err', t('未知的設定區塊。'));
