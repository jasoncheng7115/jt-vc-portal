<?php
/**
 * 站台設定（持久化於 /var/jaas-data/settings.json）。
 * 目前僅儲存 theme，但結構可擴充其他設定。
 */
class Settings {
  const FILE = '/var/jaas-data/settings.json';
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
    if (!file_exists(self::FILE)) return [];
    $d = json_decode(@file_get_contents(self::FILE), true);
    return is_array($d) ? $d : [];
  }

  public static function save(array $data): void {
    @file_put_contents(self::FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  }

  public static function getTheme(): string {
    $t = self::load()['theme'] ?? self::DEFAULT_THEME;
    return in_array($t, self::VALID_THEMES, true) ? $t : self::DEFAULT_THEME;
  }

  public static function setTheme(string $theme): bool {
    if (!in_array($theme, self::VALID_THEMES, true)) return false;
    $d = self::load();
    $d['theme'] = $theme;
    self::save($d);
    return true;
  }

  public static function isDark(string $theme): bool {
    return in_array($theme, self::DARK_THEMES, true);
  }

  /** 取回 webhook 簽章用 secret；不存在則自動產生並持久化。 */
  public static function getWebhookSecret(): string {
    $d = self::load();
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
    $d = self::load();
    $d['plan_mau_limit'] = max(1, $limit);
    self::save($d);
  }

  /** 計費週期每月起始日（對齊 8x8 訂閱週期），1–28，預設 1。 */
  public static function getBillingStartDay(): int {
    $d = (int)(self::load()['billing_start_day'] ?? 1);
    return ($d >= 1 && $d <= 28) ? $d : 1;
  }

  public static function setBillingStartDay(int $day): void {
    $d = self::load();
    $d['billing_start_day'] = max(1, min(28, $day));
    self::save($d);
  }

  /** 會議記錄（meetings.jsonl）保留天數，預設 365；範圍 7–3650。 */
  public static function getMeetingRetentionDays(): int {
    $d = (int)(self::load()['meeting_retention_days'] ?? 365);
    return max(7, min(3650, $d));
  }

  public static function setMeetingRetentionDays(int $days): void {
    $d = self::load();
    $d['meeting_retention_days'] = max(7, min(3650, $days));
    self::save($d);
  }

  /** 來賓等候頁自動檢查間隔（秒），預設 30；範圍 10–600。 */
  public static function getGuestPollSeconds(): int {
    $d = (int)(self::load()['guest_poll_seconds'] ?? 30);
    return max(10, min(600, $d));
  }

  public static function setGuestPollSeconds(int $s): void {
    $d = self::load();
    $d['guest_poll_seconds'] = max(10, min(600, $s));
    self::save($d);
  }

  /** 錄製者（無名稱與會者）顯示名稱，取代 Jitsi 預設「Fellow Jitster」；預設「會議錄影」。 */
  public static function getRecorderName(): string {
    $v = trim((string)(self::load()['recorder_name'] ?? ''));
    return $v !== '' ? $v : '會議錄影';
  }

  public static function setRecorderName(string $name): void {
    $d = self::load();
    $d['recorder_name'] = mb_substr(trim($name), 0, 40);
    self::save($d);
  }

  // === 會議室自訂（僅自建 Jitsi Meet 模式套用）===
  /** 一律保留、不開放關閉的工具列按鈕。 */
  const MEETING_BASE_BUTTONS = ['camera','microphone','toggle-camera','hangup','fullscreen','videoquality','profile','settings','filmstrip','highlight','help','shortcuts','mute-everyone','mute-video-everyone'];
  /** 可逐項開關的工具列功能（button key => 中文標籤）。 */
  const MEETING_TOGGLE_BUTTONS = [
    'chat' => '聊天', 'desktop' => '螢幕分享', 'raisehand' => '舉手', 'recording' => '錄影',
    'select-background' => '虛擬背景', 'sharedvideo' => '分享影片', 'shareaudio' => '分享音訊',
    'etherpad' => '共享文件', 'tileview' => '並排檢視', 'stats' => '連線統計',
    'participants-pane' => '參與者面板', 'security' => '安全選項',
  ];

  /** 讀取會議室自訂設定（正規化＋預設；未設過時功能全開、進入靜音）。 */
  public static function getMeetingCustom(): array {
    $d = self::load()['meeting_custom'] ?? [];
    $configured = isset($d['toolbar']) && is_array($d['toolbar']);
    $toolbar = [];
    foreach (array_keys(self::MEETING_TOGGLE_BUTTONS) as $k) {
      $toolbar[$k] = $configured ? !empty($d['toolbar'][$k]) : true;
    }
    $mode = in_array($d['logo_mode'] ?? 'site', ['site','custom','none'], true) ? $d['logo_mode'] : 'site';
    $res  = (int)($d['resolution'] ?? 1080);
    $view = in_array($d['default_view'] ?? 'speaker', ['speaker','tile'], true) ? $d['default_view'] : 'speaker';
    return [
      'logo_mode'  => $mode,
      'logo_url'   => (string)($d['logo_url'] ?? ''),
      'logo_link'  => (string)($d['logo_link'] ?? ''),
      'mute_audio' => $d['mute_audio'] ?? true,
      'mute_video' => $d['mute_video'] ?? true,
      'resolution' => in_array($res, [720, 1080], true) ? $res : 1080,
      'default_view' => $view,
      'toolbar'    => $toolbar,
    ];
  }

  public static function setMeetingCustom(array $v): void {
    $d = self::load();
    $toolbar = [];
    foreach (array_keys(self::MEETING_TOGGLE_BUTTONS) as $k) $toolbar[$k] = !empty($v['toolbar'][$k]);
    $mode = in_array($v['logo_mode'] ?? 'site', ['site','custom','none'], true) ? $v['logo_mode'] : 'site';
    $res  = (int)($v['resolution'] ?? 1080);
    $view = in_array($v['default_view'] ?? 'speaker', ['speaker','tile'], true) ? $v['default_view'] : 'speaker';
    $d['meeting_custom'] = [
      'logo_mode'  => $mode,
      'logo_url'   => trim((string)($v['logo_url'] ?? '')),
      'logo_link'  => trim((string)($v['logo_link'] ?? '')),
      'mute_audio' => !empty($v['mute_audio']),
      'mute_video' => !empty($v['mute_video']),
      'resolution' => in_array($res, [720, 1080], true) ? $res : 1080,
      'default_view' => $view,
      'toolbar'    => $toolbar,
    ];
    self::save($d);
  }

  /** 解析實際要套用到會議的 UI 設定（JaaS 用既有預設；自建用 meeting_custom）。 */
  public static function resolveMeetingUi(): array {
    $site = (defined('SITE_URL') && SITE_URL !== '') ? SITE_URL : '';
    $allToggles = array_keys(self::MEETING_TOGGLE_BUTTONS);
    if (self::getJaas()['mode'] !== 'selfhosted') {
      return [
        'logo_url'   => $site !== '' ? $site . '/logo' : '',
        'logo_link'  => $site,
        'mute_audio' => true, 'mute_video' => true, 'resolution' => 1080,
        'default_view' => 'speaker',
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
      'toolbar'    => array_values($toolbar),
    ];
  }

  /** 會議室預設 UI 語言（Jitsi 語言碼，現行用連字號式 zh-TW），預設繁體中文。 */
  const MEETING_LANGS = [
    'zh-TW' => '繁體中文',
    'zh-CN' => '简体中文',
    'en'    => 'English',
    'ja'    => '日本語',
    'ko'    => '한국어',
  ];
  public static function getMeetingLang(): string {
    $l = self::load()['meeting_lang'] ?? 'zh-TW';
    return array_key_exists($l, self::MEETING_LANGS) ? $l : 'zh-TW';
  }
  public static function setMeetingLang(string $lang): void {
    if (!array_key_exists($lang, self::MEETING_LANGS)) $lang = 'zh-TW';
    $d = self::load();
    $d['meeting_lang'] = $lang;
    self::save($d);
  }

  /** 整段覆寫某個設定區塊（如 smtp、logship）。 */
  public static function setSection(string $key, array $value): void {
    $d = self::load();
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
    'meeting_retention_days', 'meeting_custom', 'meeting_lang',
    'smtp', 'logship', 'site', 'jaas',
  ];
  /** 結構鍵：值必須是物件 / 陣列，否則略過（避免匯入錯型把設定弄壞）。 */
  const EXPORT_ARRAY_KEYS = ['meeting_custom', 'smtp', 'logship', 'site', 'jaas'];

  /** 匯出用：回傳目前 settings.json 的原始內容。 */
  public static function exportData(): array {
    return self::load();
  }

  /** 匯入：只併入白名單內的鍵（present 才覆寫），並驗證型別，其餘保留原值。 */
  public static function importData(array $incoming): void {
    $d = self::load();
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
    return [
      'brand_name' => $name !== '' ? $name : 'JT 視訊會議',
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
    $d = self::load();
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
      'plain'     => ['name' => '純白',        'desc' => '最簡潔，零視覺干擾'],
      'soft'      => ['name' => '柔灰',        'desc' => '淺灰底凸顯卡片層次'],
      'paper'     => ['name' => '米紙',        'desc' => '溫暖米色，文件感'],
      'mint'      => ['name' => '薄荷',        'desc' => '清新淡綠'],
      'sky'       => ['name' => '天藍',        'desc' => '清爽淡藍'],
      'rose'      => ['name' => '玫瑰',        'desc' => '柔和淡粉'],
      // 紋理
      'grid'      => ['name' => '點陣',        'desc' => '工程師風淡點陣'],
      'watermark' => ['name' => '浮水印',      'desc' => '空曠頁淡淡 JT logo'],
      // 光暈漸層
      'glow'      => ['name' => '光暈',        'desc' => '白底加品牌色漸層'],
      'aurora'    => ['name' => '極光',        'desc' => '紫藍粉多色光暈'],
      'sunset'    => ['name' => '夕陽',        'desc' => '暖橘紅漸層'],
      'mesh'      => ['name' => '彩雲（預設）','desc' => '流行多色 mesh gradient'],
      'layered'   => ['name' => '混搭',        'desc' => '柔灰 + 光暈 + 浮水印'],
      'hologram'  => ['name' => '全像',        'desc' => '彩虹流光科幻感'],
      // 工程感
      'blueprint' => ['name' => '藍圖',        'desc' => '工程藍方格底'],
      // 深色 / 科技
      'dark'      => ['name' => '深邃',        'desc' => '純黑底白卡飄浮'],
      'midnight'  => ['name' => '夜空',        'desc' => '深藍底點點繁星'],
      'matrix'    => ['name' => 'Matrix',     'desc' => '黑底螢光綠數位雨'],
      'terminal'  => ['name' => '終端',        'desc' => '純黑終端機掃描線'],
      'synthwave' => ['name' => 'Synthwave',  'desc' => '80s 紫粉透視格線'],
      'cyber'     => ['name' => '電馭',        'desc' => '青粉霓虹龐克'],
      'neon'      => ['name' => '霓虹',        'desc' => '深紫底粉藍光球'],
    ];
  }
}
