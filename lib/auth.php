<?php
/**
 * Session 與存取控制（OWASP A01 / A07）。
 *   - 安全 session cookie（HttpOnly、SameSite、Secure：在 HTTPS / 反向代理後啟用）
 *   - clientIp()：信任反向代理的 X-Real-IP
 *   - requireLogin / requireAdmin：未授權回 404（不洩漏 /jt-login，對應待辦 #1）
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/users.php';

class Auth {
  private static bool $started = false;

  public static function start(): void {
    if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
      self::$started = true;
      return;
    }
    $secure = self::isHttps();
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_name('JTSESS');
    session_start();
    self::$started = true;
  }

  /**
   * 是否應採信反向代理標頭（X-Real-IP / X-Forwarded-*）。
   * 僅當 REMOTE_ADDR 落在 TRUSTED_PROXIES 時為 true；TRUSTED_PROXIES 留空＝沿用舊行為（一律採信）。
   */
  private static function trustProxyHeaders(): bool {
    $list = trim((string)(defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : ''));
    if ($list === '') return true; // 未設定信任清單 → 向後相容（需搭配網路隔離）
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach (preg_split('/\s*,\s*/', $list, -1, PREG_SPLIT_NO_EMPTY) as $cidr) {
      if (self::ipInCidr($remote, $cidr)) return true;
    }
    return false;
  }

  /** 判斷 IP 是否落在 CIDR（或單一 IP）內，支援 IPv4 / IPv6。 */
  private static function ipInCidr(string $ip, string $cidr): bool {
    if ($ip === '') return false;
    if (strpos($cidr, '/') === false) return @inet_pton($ip) !== false && inet_pton($ip) === inet_pton($cidr);
    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipBin = @inet_pton($ip); $subBin = @inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8); $rem = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
    if ($rem === 0) return true;
    $mask = chr(0xFF << (8 - $rem) & 0xFF);
    return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subBin[$bytes]) & ord($mask));
  }

  /** 是否經由 HTTPS（反向代理會帶 X-Forwarded-Proto）。 */
  public static function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (self::trustProxyHeaders()) {
      if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
      // 反向代理（production）會帶 X-Real-IP 且一律 HTTPS；本機直連（curl 測試）則沒有
      if (!empty($_SERVER['HTTP_X_REAL_IP'])) return true;
    }
    return false;
  }

  /** 真實來源 IP：可信反向代理才採信 X-Real-IP / X-Forwarded-For 首段，否則用 REMOTE_ADDR。 */
  public static function clientIp(): string {
    if (self::trustProxyHeaders()) {
      if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return trim($_SERVER['HTTP_X_REAL_IP']);
      }
      if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
      }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  }

  public static function login(array $user): void {
    self::start();
    session_regenerate_id(true);
    $_SESSION['uid']          = $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['display_name'] = $user['display_name'] ?? $user['username'];
    $_SESSION['email']        = $user['email'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['login_ip'] = self::clientIp();
    $_SESSION['login_at'] = time();
    $_SESSION['last_seen'] = time();
    unset($_SESSION['2fa_uid'], $_SESSION['login_error']);
  }

  public static function logout(): void {
    self::start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $p = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
  }

  public static function check(): bool {
    self::start();
    if (empty($_SESSION['uid'])) return false;
    // Session 逾時（A07）：閒置或絕對逾時即登出。
    $now = time();
    $absStart = (int)($_SESSION['login_at'] ?? $now);
    $idleRef  = (int)($_SESSION['last_seen'] ?? $now);
    if (($now - $absStart) > SESSION_ABSOLUTE_SECONDS || ($now - $idleRef) > SESSION_IDLE_SECONDS) {
      self::logout();
      return false;
    }
    $_SESSION['last_seen'] = $now;   // 有活動 → 續期閒置計時
    return true;
  }

  /** 回傳目前登入使用者（即時讀 users.json，確保 role / disabled 最新）。 */
  public static function user(): ?array {
    if (!self::check()) return null;
    $u = Users::find($_SESSION['uid']);
    if (!$u || !empty($u['disabled'])) {
      self::logout();
      return null;
    }
    return $u;
  }

  public static function isAdmin(): bool {
    $u = self::user();
    return $u && ($u['role'] ?? '') === 'admin';
  }

  /** 未登入 → 回 404 假裝不存在（不導去 /jt-login，避免暴露登入入口）。 */
  public static function requireLogin(): array {
    $u = self::user();
    if (!$u) self::notFound();
    return $u;
  }

  public static function requireAdmin(): array {
    $u = self::requireLogin();
    if (($u['role'] ?? '') !== 'admin') self::notFound();
    return $u;
  }

  // === CSRF（OWASP A01）===

  /** 取得（必要時產生）本 session 的 CSRF token。 */
  public static function csrfToken(): string {
    self::start();
    if (empty($_SESSION['csrf'])) {
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
  }

  /** 輸出隱藏欄位，放進每個會改變狀態的 POST 表單。 */
  public static function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken()) . '">';
  }

  /** 驗證 POST 的 CSRF token；不符即擋下。放在每個 POST 處理端最前面。 */
  public static function csrfCheck(): void {
    self::start();
    $sent = $_POST['_csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
      http_response_code(403);
      header('Content-Type: text/html; charset=utf-8');
      echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8"><title>403</title></head>'
         . '<body style="font-family:sans-serif;text-align:center;margin-top:80px;color:#555;">'
         . '<h1>403</h1><p>請求無效或已過期，請重新整理頁面後再試一次。</p></body></html>';
      exit;
    }
  }

  /** 統一 404 回應（套用站台主題與品牌；中性、不洩漏內部結構）。 */
  public static function notFound(): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/settings.php';
    require_once __DIR__ . '/layout.php';
    if (function_exists('render_head')) {
      render_head('404');
      render_topbar(false);
      echo '<main class="container narrow"><div class="card" style="text-align:center;padding:48px 24px;">'
         . '<div style="font-size:64px;font-weight:700;line-height:1;">404</div>'
         . '<p class="subtitle" style="margin-top:12px;">找不到頁面。</p>'
         . '<a class="btn btn-secondary btn-sm" href="/" style="margin-top:18px;display:inline-flex;">' . icon('home', 14) . '回首頁</a>'
         . '</div></main>';
      render_foot();
    } else {
      echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8">'
         . '<title>404</title></head><body style="font-family:sans-serif;text-align:center;'
         . 'margin-top:80px;color:#555;"><h1>404</h1><p>找不到頁面。</p></body></html>';
    }
    exit;
  }
}
