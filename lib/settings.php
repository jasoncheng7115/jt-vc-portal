<?php
/**
 * 站台設定（持久化於 /var/jaas-data/settings.json）。
 * 目前僅儲存 theme，但結構可擴充其他設定。
 */
require_once __DIR__ . '/store.php';

class Settings {
  const FILE = '/var/jaas-data/settings.json';
  /** 讀改寫鎖（lockForUpdate 取得、save 釋放），避免並發設定互相覆蓋。 */
  private static $lk = null;
  const VALID_THEMES = [
    'plain', 'soft', 'paper', 'mint', 'sky', 'rose',
    'grid', 'watermark',
    'glow', 'aurora', 'sunset', 'mesh', 'layered', 'hologram',
    'blueprint',
    'dark', 'midnight', 'matrix', 'terminal', 'synthwave', 'cyber', 'neon',
  ];
  const DARK_THEMES = ['dark', 'midnight', 'matrix', 'terminal', 'synthwave', 'cyber', 'neon'];
  const DEFAULT_THEME = 'mesh';

  public static function load(): array {
    return Store::read(self::FILE, []);
  }

  /** 取得排他鎖後讀取（之後必須呼叫 save() 或 unlock()）。 */
  private static function loadForUpdate(): array {
    if (self::$lk === null) {
      $lk = @fopen(self::FILE . '.lock', 'c');
      if ($lk) { @flock($lk, LOCK_EX); self::$lk = $lk; }
    }
    return self::load();
  }

  private static function unlock(): void {
    if (self::$lk !== null) { @flock(self::$lk, LOCK_UN); @fclose(self::$lk); self::$lk = null; }
  }

  /** 原子寫入並釋放讀改寫鎖。 */
  public static function save(array $data): void {
    Store::write(self::FILE, $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    self::unlock();
  }

  public static function getTheme(): string {
    $t = self::load()['theme'] ?? self::DEFAULT_THEME;
    return in_array($t, self::VALID_THEMES, true) ? $t : self::DEFAULT_THEME;
  }

  public static function setTheme(string $theme): bool {
    if (!in_array($theme, self::VALID_THEMES, true)) return false;
    $d = self::loadForUpdate();
    $d['theme'] = $theme;
    self::save($d);
    return true;
  }

  public static function isDark(string $theme): bool {
    return in_array($theme, self::DARK_THEMES, true);
  }

  /** 取回 webhook 簽章用 secret；不存在則自動產生並持久化。 */
  public static function getWebhookSecret(): string {
    $d = self::loadForUpdate();
    if (empty($d['webhook_secret'])) {
      $d['webhook_secret'] = bin2hex(random_bytes(24)); // 48 hex chars
      self::save($d);
    }
    return $d['webhook_secret'];
  }

  /** 主持人方案的 MAU 上限（顯示用，預設 25）。 */
  public static function getPlanLimit(): int {
    return max(1, (int)(self::load()['plan_mau_limit'] ?? 25));
  }

  public static function setPlanLimit(int $limit): void {
    $d = self::loadForUpdate();
    $d['plan_mau_limit'] = max(1, $limit);
    self::save($d);
  }

  /** 計費週期每月起始日（對齊 8x8 訂閱週期），1–28，預設 1。 */
  public static function getBillingStartDay(): int {
    $d = (int)(self::load()['billing_start_day'] ?? 1);
    return ($d >= 1 && $d <= 28) ? $d : 1;
  }

  public static function setBillingStartDay(int $day): void {
    $d = self::loadForUpdate();
    $d['billing_start_day'] = max(1, min(28, $day));
    self::save($d);
  }

  /** 會議記錄（meetings.jsonl）保留天數，預設 365；範圍 7–3650。 */
  public static function getMeetingRetentionDays(): int {
    $d = (int)(self::load()['meeting_retention_days'] ?? 365);
    return max(7, min(3650, $d));
  }

  public static function setMeetingRetentionDays(int $days): void {
    $d = self::loadForUpdate();
    $d['meeting_retention_days'] = max(7, min(3650, $days));
    self::save($d);
  }

  /** 稽核記錄保留天數，預設 365；範圍 30–3650（超過者於查詢頁自動清除）。 */
  public static function getAuditRetentionDays(): int {
    $d = (int)(self::load()['audit_retention_days'] ?? 365);
    return max(30, min(3650, $d));
  }

  public static function setAuditRetentionDays(int $days): void {
    $d = self::loadForUpdate();
    $d['audit_retention_days'] = max(30, min(3650, $days));
    self::save($d);
  }

  /** 來賓等候頁自動檢查間隔（秒），預設 30；範圍 10–600。 */
  public static function getGuestPollSeconds(): int {
    $d = (int)(self::load()['guest_poll_seconds'] ?? 30);
    return max(10, min(600, $d));
  }

  public static function setGuestPollSeconds(int $s): void {
    $d = self::loadForUpdate();
    $d['guest_poll_seconds'] = max(10, min(600, $s));
    self::save($d);
  }

  /** 錄製者（無名稱與會者）顯示名稱，取代 Jitsi 預設「Fellow Jitster」；預設「會議錄影」。 */
  public static function getRecorderName(): string {
    $v = trim((string)(self::load()['recorder_name'] ?? ''));
    if (I18n::isDefaultRecorder($v)) $v = '';   // 預設值（任一語言）→ 依目前語言顯示
    return $v !== '' ? $v : t('會議錄影');
  }

  public static function setRecorderName(string $name): void {
    $d = self::loadForUpdate();
    $d['recorder_name'] = mb_substr(trim($name), 0, 40);
    self::save($d);
  }

  // === Jibri 錄影調閱服務（自建 Jibri 主機上的 jibri-recordings-api）===
  /** 服務連線設定：URL（已去尾斜線）與 Bearer token。 */
  public static function getJibri(): array {
    $d = self::load();
    return [
      'url'   => rtrim(trim((string)($d['jibri_url'] ?? '')), '/'),
      'token' => (string)($d['jibri_token'] ?? ''),
    ];
  }

  /** 儲存服務設定；token 留空表示沿用既有（前端以遮罩顯示，不必每次重輸）。URL 留空＝整組清除。 */
  public static function setJibri(string $url, string $token): void {
    $d = self::loadForUpdate();
    $url = rtrim(trim($url), '/');
    if ($url === '') {
      unset($d['jibri_url'], $d['jibri_token']);   // 清除整組設定
    } else {
      $d['jibri_url'] = $url;
      if (trim($token) !== '') $d['jibri_token'] = trim($token);
    }
    self::save($d);
  }

  /** 是否已設定 Jibri 服務（URL + token 皆有）。 */
  public static function hasJibri(): bool {
    $j = self::getJibri();
    return $j['url'] !== '' && $j['token'] !== '';
  }

  // === OIDC 單一登入（v1.10.0；設計參考 jt-doc-tools）===
  const OIDC_DEFAULTS = [
    'enabled' => false, 'display_name' => '', 'issuer' => '', 'client_id' => '', 'client_secret' => '',
    'require_https' => true, 'scopes' => 'openid email profile',
    'username_claim' => 'preferred_username', 'email_claim' => 'email', 'name_claim' => 'name', 'groups_claim' => 'groups',
    'admin_groups' => '', 'host_groups' => '', 'sso_only' => false, 'local_login_cidrs' => '',
  ];

  /** OIDC 設定（含 client_secret；頁面顯示請用 hasOidcSecret 判斷，不回填）。 */
  public static function getOidc(): array {
    $c = self::load()['oidc'] ?? [];
    $c = is_array($c) ? $c : [];
    $o = self::OIDC_DEFAULTS;
    foreach ($o as $k => $def) {
      if (!array_key_exists($k, $c)) continue;
      $o[$k] = is_bool($def) ? (bool)$c[$k] : trim((string)$c[$k]);
    }
    $o['issuer'] = rtrim($o['issuer'], '/');
    foreach (['username_claim' => 'preferred_username', 'email_claim' => 'email', 'name_claim' => 'name', 'groups_claim' => 'groups', 'scopes' => 'openid email profile'] as $k => $d) {
      if ($o[$k] === '') $o[$k] = $d;
    }
    if (!preg_match('/(^|\s)openid(\s|$)/', $o['scopes'])) $o['scopes'] = 'openid ' . $o['scopes'];
    return $o;
  }

  /** 儲存 OIDC 設定；client_secret 空字串＝沿用既有值（頁面不回填密鑰）。 */
  public static function setOidc(array $v): void {
    $d = self::loadForUpdate();
    $cur = is_array($d['oidc'] ?? null) ? $d['oidc'] : [];
    $o = [];
    foreach (self::OIDC_DEFAULTS as $k => $def) {
      $o[$k] = is_bool($def) ? !empty($v[$k]) : mb_substr(trim((string)($v[$k] ?? '')), 0, 2000);
    }
    if ($o['client_secret'] === '') $o['client_secret'] = (string)($cur['client_secret'] ?? '');
    $d['oidc'] = $o;
    self::save($d);
  }

  // === 錄影逐字稿與會議摘要（v1.12.0；目前後端：JTLW = jt-live-whisper）===
  const TRANSCRIBE_DEFAULTS = [
    'enabled'             => false,
    'backend'             => 'jtlw',                 // 之後會加 'jtdt'
    'jtlw_url'            => '',                     // 例：https://10.0.0.30:8790（程式自己接 /api/v1）
    'jtlw_key'            => '',                     // 金鑰本身（不含 Bearer）；頁面不回填
    'jtlw_ca'             => '',                     // 自簽憑證 PEM（信任它，不關驗證）
    'language'            => 'zh-Hant',              // zh-Hant | en | ja | ko | auto
    'profile_id'          => 'meeting.balanced',
    'summarize'           => true,
    'diarize_engine'      => 'auto',                 // auto（JTLW api 2.5：Nemotron，最多 8 人，超過自動退回）| legacy
    'webhook_endpoint_id' => '',
    'webhook_secret'      => '',                     // 註冊時 JTLW 只給一次；頁面不回填
  ];
  const TRANSCRIBE_LANGS = ['zh-Hant', 'en', 'ja', 'ko', 'nan-Hant', 'auto'];

  public static function getTranscribe(): array {
    $c = self::load()['transcribe'] ?? [];
    $c = is_array($c) ? $c : [];
    $o = self::TRANSCRIBE_DEFAULTS;
    foreach ($o as $k => $def) {
      if (!array_key_exists($k, $c)) continue;
      $o[$k] = is_bool($def) ? (bool)$c[$k] : trim((string)$c[$k]);
    }
    $o['jtlw_url'] = rtrim(preg_replace('#/api/v1/?$#', '', $o['jtlw_url']), '/');
    if (!in_array($o['language'], self::TRANSCRIBE_LANGS, true)) $o['language'] = 'zh-Hant';
    // meeting.detailed 已由 JTLW 停用（v2.25.3，從來沒有與 balanced 不同的處理）→ 一律用 balanced
    if (!preg_match('/^[a-z0-9._-]{1,64}$/', $o['profile_id']) || $o['profile_id'] === 'meeting.detailed') $o['profile_id'] = 'meeting.balanced';
    if ($o['backend'] !== 'jtlw') $o['backend'] = 'jtlw';
    if (!in_array($o['diarize_engine'], ['auto', 'legacy'], true)) $o['diarize_engine'] = 'auto';
    return $o;
  }

  /** 儲存；jtlw_key / webhook_secret 空字串＝沿用既有值（頁面不回填密鑰）。$merge=true 只覆寫有給的鍵。 */
  public static function setTranscribe(array $v, bool $merge = false): void {
    $d = self::loadForUpdate();
    $cur = is_array($d['transcribe'] ?? null) ? $d['transcribe'] : [];
    $o = [];
    foreach (self::TRANSCRIBE_DEFAULTS as $k => $def) {
      if ($merge && !array_key_exists($k, $v)) { $o[$k] = $cur[$k] ?? $def; continue; }
      $o[$k] = is_bool($def) ? !empty($v[$k]) : mb_substr(trim((string)($v[$k] ?? '')), 0, 20000);
    }
    foreach (['jtlw_key', 'webhook_secret'] as $k) {
      if ($o[$k] === '') $o[$k] = (string)($cur[$k] ?? '');
    }
    // 自動產生只處理「啟用之後」錄的會議（避免一啟用就把過去所有錄影送出）
    $o['auto_since'] = (int)($cur['auto_since'] ?? 0);
    if ($o['enabled'] && $o['auto_since'] === 0) $o['auto_since'] = time();
    $d['transcribe'] = $o;
    self::save($d);
  }

  public static function transcribeReady(): bool {
    $t = self::getTranscribe();
    return $t['enabled'] && $t['jtlw_url'] !== '' && $t['jtlw_key'] !== '' && self::hasJibri();
  }

  // === 登入頁路由偽裝 ===
  const DEFAULT_LOGIN_PATH = 'jt-login';
  /** 合法的登入路徑格式（單段、無斜線）。 */
  public static function validLoginPath(string $p): bool {
    return (bool)preg_match('/^[A-Za-z0-9._-]{1,64}$/', trim($p, '/'));
  }
  /** 目前登入頁路徑（不含前導斜線），預設 jt-login。 */
  public static function getLoginPath(): string {
    $p = trim((string)(self::load()['login_path'] ?? ''), '/');
    return self::validLoginPath($p) ? $p : self::DEFAULT_LOGIN_PATH;
  }
  /** 設定登入頁路徑；空字串還原為預設。回傳是否成功。 */
  public static function setLoginPath(string $p): bool {
    $p = trim($p, '/');
    if ($p === '') $p = self::DEFAULT_LOGIN_PATH;
    if (!self::validLoginPath($p)) return false;
    $d = self::loadForUpdate();
    $d['login_path'] = $p;
    self::save($d);
    return true;
  }
  /** 登入頁完整路徑（含前導斜線），供轉址用。 */
  public static function loginUrl(): string {
    return '/' . self::getLoginPath();
  }

  // === 會議室自訂（僅自建 Jitsi Meet 模式套用）===
  /** 一律保留、不開放關閉的工具列按鈕。 */
  const MEETING_BASE_BUTTONS = ['camera','microphone','toggle-camera','hangup','fullscreen','videoquality','profile','settings','filmstrip','highlight','help','shortcuts','mute-everyone','mute-video-everyone'];
  /** 可逐項開關的工具列功能（button key 清單；標籤見 toggleButtonLabels()）。 */
  const MEETING_TOGGLE_BUTTONS = [
    'chat', 'desktop', 'raisehand', 'recording',
    'select-background', 'sharedvideo', 'shareaudio',
    'etherpad', 'tileview', 'stats',
    'participants-pane', 'security',
  ];

  /** 工具列功能顯示標籤（button key => 目前語言標籤）。 */
  public static function toggleButtonLabels(): array {
    return [
      'chat' => t('聊天'), 'desktop' => t('螢幕分享'), 'raisehand' => t('舉手'), 'recording' => t('錄影'),
      'select-background' => t('虛擬背景'), 'sharedvideo' => t('分享影片'), 'shareaudio' => t('分享音訊'),
      'etherpad' => t('共享文件'), 'tileview' => t('並排檢視'), 'stats' => t('連線統計'),
      'participants-pane' => t('參與者面板'), 'security' => t('安全選項'),
    ];
  }

  /** 讀取會議室自訂設定（正規化＋預設；未設過時功能全開、進入靜音）。 */
  public static function getMeetingCustom(): array {
    $d = self::load()['meeting_custom'] ?? [];
    $configured = isset($d['toolbar']) && is_array($d['toolbar']);
    $toolbar = [];
    foreach (self::MEETING_TOGGLE_BUTTONS as $k) {
      $toolbar[$k] = $configured ? !empty($d['toolbar'][$k]) : true;
    }
    $mode = in_array($d['logo_mode'] ?? 'site', ['site','custom','none'], true) ? ($d['logo_mode'] ?? 'site') : 'site';
    $res  = (int)($d['resolution'] ?? 1080);
    $view = in_array($d['default_view'] ?? 'speaker', ['speaker','tile'], true) ? ($d['default_view'] ?? 'speaker') : 'speaker';
    return [
      'logo_mode'  => $mode,
      'logo_url'   => (string)($d['logo_url'] ?? ''),
      'logo_link'  => (string)($d['logo_link'] ?? ''),
      'mute_audio' => $d['mute_audio'] ?? true,
      'mute_video' => $d['mute_video'] ?? true,
      'resolution' => in_array($res, [720, 1080], true) ? $res : 1080,
      'default_view' => $view,
      'bw_save_off' => $d['bw_save_off'] ?? true,   // 關閉「視訊省頻寬」自動降載（預設開＝不自動關他人視訊）
      'hq_small'   => (bool)($d['hq_small'] ?? false),   // 小畫面（手機、多人並排）也收較高畫質（v1.18.0；較耗頻寬，預設關）
      'toolbar'    => $toolbar,
    ];
  }

  public static function setMeetingCustom(array $v): void {
    $d = self::loadForUpdate();
    $toolbar = [];
    foreach (self::MEETING_TOGGLE_BUTTONS as $k) $toolbar[$k] = !empty($v['toolbar'][$k]);
    $mode = in_array($v['logo_mode'] ?? 'site', ['site','custom','none'], true) ? ($v['logo_mode'] ?? 'site') : 'site';
    $res  = (int)($v['resolution'] ?? 1080);
    $view = in_array($v['default_view'] ?? 'speaker', ['speaker','tile'], true) ? ($v['default_view'] ?? 'speaker') : 'speaker';
    $d['meeting_custom'] = [
      'logo_mode'  => $mode,
      'logo_url'   => trim((string)($v['logo_url'] ?? '')),
      'logo_link'  => trim((string)($v['logo_link'] ?? '')),
      'mute_audio' => !empty($v['mute_audio']),
      'mute_video' => !empty($v['mute_video']),
      'resolution' => in_array($res, [720, 1080], true) ? $res : 1080,
      'default_view' => $view,
      'bw_save_off' => !empty($v['bw_save_off']),
      'hq_small'   => !empty($v['hq_small']),
      'toolbar'    => $toolbar,
    ];
    self::save($d);
  }

  /** 解析實際要套用到會議的 UI 設定（JaaS 用既有預設；自建用 meeting_custom）。 */
  public static function resolveMeetingUi(): array {
    $site = (defined('SITE_URL') && SITE_URL !== '') ? SITE_URL : '';
    $allToggles = self::MEETING_TOGGLE_BUTTONS;
    if (self::getJaas()['mode'] !== 'selfhosted') {
      return [
        'logo_url'   => $site !== '' ? $site . '/logo' : '',
        'logo_link'  => $site,
        'mute_audio' => true, 'mute_video' => true, 'resolution' => 1080,
        'default_view' => 'speaker',
        'bw_save_off' => false,
        'hq_small'   => false,
        'toolbar'    => array_merge(self::MEETING_BASE_BUTTONS, $allToggles),
      ];
    }
    $mc = self::getMeetingCustom();
    $logo = $mc['logo_mode'] === 'site' ? ($site !== '' ? $site . '/logo' : '')
          : ($mc['logo_mode'] === 'custom' ? $mc['logo_url'] : '');  // none → ''（隱藏）
    $toolbar = self::MEETING_BASE_BUTTONS;
    foreach ($allToggles as $k) if (!empty($mc['toolbar'][$k])) $toolbar[] = $k;
    return [
      'logo_url'   => $logo,
      'logo_link'  => $mc['logo_link'] !== '' ? $mc['logo_link'] : $site,
      'mute_audio' => (bool)$mc['mute_audio'],
      'mute_video' => (bool)$mc['mute_video'],
      'resolution' => (int)$mc['resolution'],
      'default_view' => $mc['default_view'],
      'bw_save_off' => (bool)$mc['bw_save_off'],
      'hq_small'   => (bool)$mc['hq_small'],
      'toolbar'    => array_values($toolbar),
    ];
  }

  /** 會議室預設 UI 語言（Jitsi 語言碼，現行用連字號式 zh-TW），預設繁體中文。 */
  const MEETING_LANGS = [
    // 常用優先
    'zh-TW' => '繁體中文',  // i18n-ignore
    'zh-CN' => '简体中文',  // i18n-ignore
    'en'    => 'English',
    'ja'    => '日本語',  // i18n-ignore
    'ko'    => '한국어',  // i18n-ignore
    // 其餘 Jitsi Meet 支援語言（依語言碼排序）
    'af'    => 'Afrikaans',
    'ar'    => 'العربية',
    'be'    => 'Беларуская',
    'bg'    => 'Български',
    'ca'    => 'Català',
    'cs'    => 'Čeština',
    'da'    => 'Dansk',
    'de'    => 'Deutsch',
    'dsb'   => 'Dolnoserbšćina',
    'el'    => 'Ελληνικά',
    'eo'    => 'Esperanto',
    'es'    => 'Español',
    'es-US' => 'Español (EE. UU.)',
    'et'    => 'Eesti',
    'eu'    => 'Euskara',
    'fa'    => 'فارسی',
    'fi'    => 'Suomi',
    'fr'    => 'Français',
    'fr-CA' => 'Français (Canada)',
    'gl'    => 'Galego',
    'he'    => 'עברית',
    'hi'    => 'हिन्दी',
    'hr'    => 'Hrvatski',
    'hsb'   => 'Hornjoserbšćina',
    'hu'    => 'Magyar',
    'hy'    => 'Հայերեն',
    'id'    => 'Bahasa Indonesia',
    'is'    => 'Íslenska',
    'it'    => 'Italiano',
    'kab'   => 'Taqbaylit',
    'kk'    => 'Қазақша',
    'lt'    => 'Lietuvių',
    'lv'    => 'Latviešu',
    'ml'    => 'മലയാളം',
    'mn'    => 'Монгол',
    'mr'    => 'मराठी',
    'nb'    => 'Norsk (bokmål)',
    'nl'    => 'Nederlands',
    'no'    => 'Norsk',
    'oc'    => 'Occitan',
    'pl'    => 'Polski',
    'pt'    => 'Português',
    'pt-BR' => 'Português (Brasil)',
    'ro'    => 'Română',
    'ru'    => 'Русский',
    'sc'    => 'Sardu',
    'sk'    => 'Slovenčina',
    'sl'    => 'Slovenščina',
    'sq'    => 'Shqip',
    'sr'    => 'Српски',
    'sv'    => 'Svenska',
    'te'    => 'తెలుగు',
    'tr'    => 'Türkçe',
    'uk'    => 'Українська',
    'vi'    => 'Tiếng Việt',
  ];
  /** 「跟隨介面語言」選項值（新安裝預設）。 */
  const MEETING_LANG_UI = 'ui';

  /** 實際套用到 Jitsi 的語言碼（設定為 ui 時依目前介面語言）。 */
  public static function getMeetingLang(): string {
    $l = self::getMeetingLangSetting();
    return $l === self::MEETING_LANG_UI ? I18n::jitsiLang() : $l;
  }
  /** 設定頁用：原始設定值（ui 或語言碼）。 */
  public static function getMeetingLangSetting(): string {
    $l = self::load()['meeting_lang'] ?? self::MEETING_LANG_UI;
    return ($l === self::MEETING_LANG_UI || array_key_exists($l, self::MEETING_LANGS)) ? $l : self::MEETING_LANG_UI;
  }
  public static function setMeetingLang(string $lang): void {
    if ($lang !== self::MEETING_LANG_UI && !array_key_exists($lang, self::MEETING_LANGS)) $lang = self::MEETING_LANG_UI;
    $d = self::loadForUpdate();
    $d['meeting_lang'] = $lang;
    self::save($d);
  }

  /** 整段覆寫某個設定區塊（如 smtp、logship）。 */
  public static function setSection(string $key, array $value): void {
    $d = self::loadForUpdate();
    $d[$key] = $value;
    self::save($d);
  }

  public static function getSection(string $key): array {
    $v = self::load()[$key] ?? [];
    return is_array($v) ? $v : [];
  }

  // === 設定匯出 / 匯入 ===
  /** 允許匯出 / 匯入的頂層設定鍵（白名單，避免匯入夾帶未知結構）。 */
  const EXPORTABLE_KEYS = [
    'theme', 'webhook_secret', 'plan_mau_limit', 'billing_start_day',
    'meeting_retention_days', 'audit_retention_days', 'guest_poll_seconds', 'meeting_lang',
    'recorder_name', 'jibri_url', 'jibri_token', 'login_path',
    'meeting_custom', 'smtp', 'logship', 'site', 'jaas', 'oidc', 'transcribe',
  ];
  /** 結構鍵：值必須是物件 / 陣列，否則略過（避免匯入錯型把設定弄壞）。 */
  const EXPORT_ARRAY_KEYS = ['meeting_custom', 'smtp', 'logship', 'site', 'jaas', 'oidc', 'transcribe'];

  /** 匯出用：只輸出白名單內（本版應有）的設定鍵，與匯入範圍一致。 */
  public static function exportData(): array {
    $d = self::load();
    $out = [];
    foreach (self::EXPORTABLE_KEYS as $k) {
      if (array_key_exists($k, $d)) $out[$k] = $d[$k];
    }
    return $out;
  }

  /** 匯入：只併入白名單內的鍵（present 才覆寫），並驗證型別，其餘保留原值。 */
  public static function importData(array $incoming): void {
    $d = self::loadForUpdate();
    foreach (self::EXPORTABLE_KEYS as $k) {
      if (!array_key_exists($k, $incoming)) continue;
      $v = $incoming[$k];
      if (in_array($k, self::EXPORT_ARRAY_KEYS, true)) {
        if (is_array($v)) $d[$k] = $v;        // 結構鍵須為陣列
      } elseif (is_scalar($v)) {
        $d[$k] = $v;                          // 純量鍵
      }
    }
    self::save($d);
  }

  /** 站台外觀：品牌名稱與自訂 logo。 */
  public static function getSite(): array {
    $c = self::load()['site'] ?? [];
    $name = trim($c['brand_name'] ?? '');
    if (I18n::isDefaultBrand($name)) $name = '';   // 預設名稱（任一語言）→ 依目前語言顯示
    return [
      'brand_name' => $name !== '' ? $name : t('JT 視訊會議'),
      'logo_mime'  => $c['logo_mime'] ?? '',          // 空 = 用預設 logo
      'logo_v'     => (int)($c['logo_v'] ?? 0),        // 版本號（cache busting）
    ];
  }

  // === 連線設定（JaaS / 自建 Jitsi Meet，兩種模式設定分開存、互不覆蓋）===
  const JAAS_SCHEMA_VERSION = 2;

  /**
   * 連線設定 schema 遷移（純記憶體、冪等）。
   * v1（舊）：domain / app_id 為「兩模式共用」的單一欄位。
   * v2（新）：JaaS 用 domain / app_id；自建用 sh_domain / sh_app_id，互不覆蓋。
   * 遷移時依「當時的 mode」把舊共用值分流到對應模式的專屬欄位，確保升級不壞、舊設定不丟。
   */
  private static function migrateJaas(array $c): array {
    if ((int)($c['_v'] ?? 1) >= self::JAAS_SCHEMA_VERSION) return $c;
    $mode = (($c['mode'] ?? 'jaas') === 'selfhosted') ? 'selfhosted' : 'jaas';
    if ($mode === 'selfhosted') {
      // 舊自建設定把網域 / app_id 存在共用欄位 → 移到自建專屬，JaaS 專屬回預設
      if (!array_key_exists('sh_domain', $c) && array_key_exists('domain', $c)) $c['sh_domain'] = $c['domain'];
      if (!array_key_exists('sh_app_id', $c) && array_key_exists('app_id', $c)) $c['sh_app_id'] = $c['app_id'];
      $c['domain'] = '8x8.vc';
      $c['app_id'] = '';
    }
    // mode = jaas：domain / app_id 原本就是 JaaS 值，保留即可；sh_* 預設空
    $c['_v'] = self::JAAS_SCHEMA_VERSION;
    return $c;
  }

  /** 升級後一次性把舊版連線設定寫回新結構（冪等；無 jaas 或已是新版則不動）。 */
  public static function migrate(): void {
    $d = self::loadForUpdate();
    if (!isset($d['jaas']) || !is_array($d['jaas'])) return;
    if ((int)($d['jaas']['_v'] ?? 1) >= self::JAAS_SCHEMA_VERSION) return;
    $d['jaas'] = self::migrateJaas($d['jaas']);
    self::save($d);
  }

  /**
   * 連線設定。回傳值含「目前模式解析後」的 domain / app_id（其餘程式只看這兩個），
   * 另附兩組模式專屬原始值（jaas_domain / jaas_app_id / sh_domain / sh_app_id）供設定表單渲染。
   */
  public static function getJaas(): array {
    $c = self::migrateJaas(self::load()['jaas'] ?? []);
    $mode = $c['mode'] ?? 'jaas';
    if (!in_array($mode, ['jaas', 'selfhosted'], true)) $mode = 'jaas';
    $shAuth = $c['sh_auth'] ?? 'none';
    if (!in_array($shAuth, ['none', 'jwt'], true)) $shAuth = 'none';
    $jaasDomain = ($c['domain'] ?? '') !== '' ? $c['domain'] : '8x8.vc';
    $jaasAppId  = $c['app_id'] ?? '';
    $shDomain   = $c['sh_domain'] ?? '';
    $shAppId    = $c['sh_app_id'] ?? '';
    // 依目前模式解析實際生效的 domain / app_id
    $domain = $mode === 'jaas' ? $jaasDomain : $shDomain;
    $appId  = $mode === 'jaas' ? $jaasAppId  : $shAppId;
    return [
      'mode'        => $mode,                               // jaas | selfhosted
      'app_id'      => $appId,                              // 解析後（目前模式）
      'kid'         => $c['kid'] ?? '',                     // JaaS: JWT header kid
      'domain'      => $domain,                             // 解析後（目前模式）
      'site_url'    => rtrim($c['site_url'] ?? '', '/'),    // 本系統對外網址（共用）
      'sh_auth'     => $shAuth,                             // 自建是否需 JWT
      'sh_secret'   => $c['sh_secret'] ?? '',               // 自建 JWT HS256 共享密鑰
      'sh_sub'      => $c['sh_sub'] ?? '',                  // 自建 JWT sub
      // 兩組模式專屬原始值（設定表單用，互不覆蓋）
      'jaas_domain' => $jaasDomain,
      'jaas_app_id' => $jaasAppId,
      'sh_domain'   => $shDomain,
      'sh_app_id'   => $shAppId,
    ];
  }

  public static function themeOptions(): array {
    return [
      // 純色系
      'plain'     => ['name' => t('純白'),        'desc' => t('最簡潔，零視覺干擾')],
      'soft'      => ['name' => t('柔灰'),        'desc' => t('淺灰底凸顯卡片層次')],
      'paper'     => ['name' => t('米紙'),        'desc' => t('溫暖米色，文件感')],
      'mint'      => ['name' => t('薄荷'),        'desc' => t('清新淡綠')],
      'sky'       => ['name' => t('天藍'),        'desc' => t('清爽淡藍')],
      'rose'      => ['name' => t('玫瑰'),        'desc' => t('柔和淡粉')],
      // 紋理
      'grid'      => ['name' => t('點陣'),        'desc' => t('工程師風淡點陣')],
      'watermark' => ['name' => t('浮水印'),      'desc' => t('空曠頁淡淡 logo 浮水印')],
      // 光暈漸層
      'glow'      => ['name' => t('光暈'),        'desc' => t('白底加品牌色漸層')],
      'aurora'    => ['name' => t('極光'),        'desc' => t('紫藍粉多色光暈')],
      'sunset'    => ['name' => t('夕陽'),        'desc' => t('暖橘紅漸層')],
      'mesh'      => ['name' => t('彩雲（預設）'),'desc' => t('流行多色 mesh gradient')],
      'layered'   => ['name' => t('混搭'),        'desc' => t('柔灰 + 光暈 + 浮水印')],
      'hologram'  => ['name' => t('全像'),        'desc' => t('彩虹流光科幻感')],
      // 工程感
      'blueprint' => ['name' => t('藍圖'),        'desc' => t('工程藍方格底')],
      // 深色 / 科技
      'dark'      => ['name' => t('深邃'),        'desc' => t('純黑底白卡飄浮')],
      'midnight'  => ['name' => t('夜空'),        'desc' => t('深藍底點點繁星')],
      'matrix'    => ['name' => 'Matrix',     'desc' => t('黑底螢光綠數位雨')],
      'terminal'  => ['name' => t('終端'),        'desc' => t('純黑終端機掃描線')],
      'synthwave' => ['name' => 'Synthwave',  'desc' => t('80s 紫粉透視格線')],
      'cyber'     => ['name' => t('電馭'),        'desc' => t('青粉霓虹龐克')],
      'neon'      => ['name' => t('霓虹'),        'desc' => t('深紫底粉藍光球')],
    ];
  }
}
