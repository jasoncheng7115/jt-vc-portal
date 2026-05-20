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
  const DEFAULT_THEME = 'layered';

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

  /** 連線設定，支援 JaaS 與自建 Jitsi Meet 兩種模式。 */
  public static function getJaas(): array {
    $c = self::load()['jaas'] ?? [];
    $mode = $c['mode'] ?? 'jaas';
    if (!in_array($mode, ['jaas', 'selfhosted'], true)) $mode = 'jaas';
    $shAuth = $c['sh_auth'] ?? 'none';
    if (!in_array($shAuth, ['none', 'jwt'], true)) $shAuth = 'none';
    return [
      'mode'      => $mode,                               // jaas | selfhosted
      'app_id'    => $c['app_id']   ?? '',                // JaaS: tenant id；自建: JWT aud/iss（可空）
      'kid'       => $c['kid']      ?? '',                // JaaS: JWT header kid
      'domain'    => $c['domain']   ?? ($mode === 'jaas' ? '8x8.vc' : ''), // 服務網域
      'site_url'  => rtrim($c['site_url'] ?? '', '/'),    // 本系統對外網址
      'sh_auth'   => $shAuth,                             // 自建是否需 JWT
      'sh_secret' => $c['sh_secret'] ?? '',               // 自建 JWT HS256 共享密鑰
      'sh_sub'    => $c['sh_sub'] ?? '',                  // 自建 JWT sub（預設用 domain）
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
      'mesh'      => ['name' => '彩雲',        'desc' => '流行多色 mesh gradient'],
      'layered'   => ['name' => '混搭（預設）','desc' => '柔灰 + 光暈 + 浮水印'],
      'hologram'  => ['name' => '全像',        'desc' => '彩虹流光科幻感'],
      // 工程感
      'blueprint' => ['name' => '藍圖',        'desc' => '工程藍方格底'],
      // 深色 / 科技
      'dark'      => ['name' => '深邃',        'desc' => '純黑底白卡飄浮'],
      'midnight'  => ['name' => '夜空',        'desc' => '深藍底點點繁星'],
      'matrix'    => ['name' => 'Matrix',     'desc' => '黑底螢光綠數位雨'],
      'terminal'  => ['name' => '終端',        'desc' => '純黑終端機掃描線'],
      'synthwave' => ['name' => 'Synthwave',  'desc' => '80s 紫粉透視格線'],
      'cyber'     => ['name' => '賽博',        'desc' => '青粉霓虹龐克'],
      'neon'      => ['name' => '霓虹',        'desc' => '深紫底粉藍光球'],
    ];
  }
}
