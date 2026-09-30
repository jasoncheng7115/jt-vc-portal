<?php
/**
 * 多語系（i18n）。
 *
 * 字串鍵 = 繁體中文原文（gettext 風格）：程式裡寫 t('登入')，
 *   - zh-TW：直接回傳原文（再套變數）
 *   - en   ：查 lang/en/*.php 合併後的字典；缺字時退回中文原文（check-i18n 會擋下）
 * 變數用 {name} 佔位：t('已寄至 {to}。', ['to' => $to])（也順便避開「變數後接全形標點」的 PHP 陷阱）。
 *
 * 語言決定優先序：?lang= 參數（寫 cookie）> 登入者個人設定 > cookie jtvc_lang > 瀏覽器 Accept-Language > en。
 * CLI 一律 en（可用環境變數 JTVC_LANG 覆寫）。
 */
class I18n {
  const SUPPORTED = ['zh-TW', 'en', 'ja'];
  const DEFAULT   = 'en';
  const COOKIE    = 'jtvc_lang';
  /** 語言選單顯示名稱（各語言以自身語言書寫，不翻譯）。 */
  const NAMES = ['zh-TW' => '繁體中文', 'en' => 'English', 'ja' => '日本語'];  // i18n-ignore

  private static ?string $lang = null;
  private static array $dict = [];
  private static array $loaded = [];

  /** 正規化語言碼；不支援回 null。任何 zh* 都對到 zh-TW（目前唯一中文語系）。 */
  public static function normalize(?string $v): ?string {
    $v = strtolower(trim((string)$v));
    if ($v === '') return null;
    if ($v === 'en' || strpos($v, 'en-') === 0 || strpos($v, 'en_') === 0) return 'en';
    if ($v === 'zh' || strpos($v, 'zh-') === 0 || strpos($v, 'zh_') === 0) return 'zh-TW';
    if ($v === 'ja' || strpos($v, 'ja-') === 0 || strpos($v, 'ja_') === 0) return 'ja';
    return null;
  }

  /** 解析 Accept-Language（依 q 值排序），回第一個支援的語言。 */
  public static function fromAcceptLanguage(string $header): ?string {
    $cands = [];
    foreach (explode(',', $header) as $i => $part) {
      $bits = explode(';', trim($part));
      $tag = trim($bits[0]);
      if ($tag === '' || $tag === '*') continue;
      $q = 1.0;
      foreach (array_slice($bits, 1) as $p) {
        if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $p, $m)) $q = (float)$m[1];
      }
      $cands[] = [$tag, $q, $i];
    }
    usort($cands, fn($a, $b) => ($b[1] <=> $a[1]) ?: ($a[2] <=> $b[2]));
    foreach ($cands as $c) {
      if ($c[1] <= 0) continue;
      $n = self::normalize($c[0]);
      if ($n !== null) return $n;
    }
    return null;
  }

  /** 目前語言（每個請求決定一次）。 */
  public static function lang(): string {
    if (self::$lang !== null) return self::$lang;
    if (PHP_SAPI === 'cli') return self::$lang = (self::normalize(getenv('JTVC_LANG') ?: '') ?? self::DEFAULT);

    // 1) ?lang= 明確指定 → 記住到 cookie
    $q = self::normalize($_GET['lang'] ?? null);
    if ($q !== null) {
      self::remember($q);
      return self::$lang = $q;
    }
    // 2) 登入者個人設定（session 快取，於 Auth::login / 切換時寫入）
    if (session_status() === PHP_SESSION_ACTIVE) {
      $s = self::normalize($_SESSION['lang'] ?? null);
      if ($s !== null) return self::$lang = $s;
    }
    // 3) cookie
    $c = self::normalize($_COOKIE[self::COOKIE] ?? null);
    if ($c !== null) return self::$lang = $c;
    // 4) 瀏覽器
    $a = self::fromAcceptLanguage((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($a !== null) return self::$lang = $a;
    return self::$lang = self::DEFAULT;
  }

  /** 強制設定本請求語言（切換語言、登入套用個人設定時用）。 */
  public static function set(string $lang): void {
    $n = self::normalize($lang);
    if ($n !== null) self::$lang = $n;
  }

  /** 寫語言 cookie（一年）。 */
  public static function remember(string $lang): void {
    if (headers_sent()) return;
    $secure = class_exists('Auth') ? Auth::isHttps() : false;
    setcookie(self::COOKIE, $lang, [
      'expires' => time() + 365 * 86400, 'path' => '/',
      'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[self::COOKIE] = $lang;
  }

  /** HTML lang 屬性值。 */
  public static function htmlLang(): string {
    return ['zh-TW' => 'zh-Hant-TW', 'ja' => 'ja'][self::lang()] ?? 'en';
  }

  /** 對應的 Jitsi 介面語言碼。 */
  public static function jitsiLang(): string {
    return ['zh-TW' => 'zh-TW', 'ja' => 'ja'][self::lang()] ?? 'en';
  }

  /** 載入某語言字典（lang/<code>/*.php 各回傳 array，合併）。 */
  public static function dict(string $lang): array {
    if (isset(self::$loaded[$lang])) return self::$dict[$lang] ?? [];
    self::$loaded[$lang] = true;
    $d = [];
    $dir = __DIR__ . '/../lang/' . $lang;
    if (is_dir($dir)) {
      $files = glob($dir . '/*.php') ?: [];
      sort($files);
      foreach ($files as $f) {
        $part = include $f;
        if (is_array($part)) $d = $part + $d;   // 先載入者優先（檔名排序）
      }
    }
    return self::$dict[$lang] = $d;
  }

  /** 翻譯。$key 為繁中原文；$vars 以 {name} 佔位替換。 */
  public static function t(string $key, array $vars = [], ?string $lang = null): string {
    $lang = $lang ?? self::lang();
    $s = $key;
    if ($lang !== 'zh-TW') {
      $d = self::dict($lang);
      if (isset($d[$key]) && $d[$key] !== '') $s = $d[$key];
    }
    if ($vars) {
      $rep = [];
      foreach ($vars as $k => $v) $rep['{' . $k . '}'] = (string)$v;
      $s = strtr($s, $rep);
    }
    return $s;
  }

  /** 值是否為某個預設文字在任一語言的版本（例：存成 'JT 視訊會議' 的舊站台名稱視同未自訂）。 */
  public static function isAnyTranslation(string $value, string $key): bool {
    foreach (self::SUPPORTED as $l) if ($value === self::t($key, [], $l)) return true;
    return false;
  }

  /** 預設文字判斷（值等於任一語言版本＝未自訂）。鍵字面寫在此處統一管理。 */
  public static function isDefaultBrand(string $v): bool { return self::isAnyTranslation($v, 'JT 視訊會議'); }  // i18n-ignore
  public static function isDefaultRecorder(string $v): bool { return self::isAnyTranslation($v, '會議錄影'); }  // i18n-ignore

  /** GitHub 文件連結：依目前語言指向 NAME.md（英）/ NAME_zh-TW.md / NAME_ja.md，並帶對應錨點（該語言無錨點則不帶）。 */
  public static function docUrl(string $doc, string $anchorZh = '', string $anchorEn = '', string $anchorJa = ''): string {
    $l = self::lang();
    $suffix = ['zh-TW' => '_zh-TW', 'ja' => '_ja'][$l] ?? '';
    $a = ['zh-TW' => $anchorZh, 'ja' => $anchorJa][$l] ?? $anchorEn;
    return (defined('APP_GITHUB_URL') ? APP_GITHUB_URL : '') . '/blob/main/' . $doc . $suffix . '.md' . ($a !== '' ? '#' . $a : '');
  }

  /** 語言切換連結（回到目前頁面）。 */
  public static function switchUrl(string $lang): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    return '/set-lang?l=' . rawurlencode($lang) . '&r=' . rawurlencode($uri);
  }

  /** 測試用：重置狀態。 */
  public static function reset(): void {
    self::$lang = null;
  }
}

/** 翻譯捷徑。 */
function t(string $key, array $vars = []): string {
  return I18n::t($key, $vars);
}

/** 翻譯並做 HTML 跳脫。 */
function th(string $key, array $vars = []): string {
  return htmlspecialchars(I18n::t($key, $vars), ENT_QUOTES, 'UTF-8');
}

/**
 * 延後翻譯：回傳 ['k' => 鍵, 'v' => 變數]，由顯示端依「看的人」的語言翻譯。
 * 用於背景排程 / webhook 等沒有操作者語言的稽核記錄（Audit::log 接受此格式）。
 */
function tk(string $key, array $vars = []): array {
  return ['k' => $key, 'v' => $vars];
}
