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

  /** 是否經由 HTTPS（反向代理會帶 X-Forwarded-Proto 或至少 X-Real-IP）。 */
  public static function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    // 反向代理（production）會帶 X-Real-IP 且一律 HTTPS；本機直連（curl 測試）則沒有
    return !empty($_SERVER['HTTP_X_REAL_IP']);
  }

  /** 真實來源 IP：信任反向代理 X-Real-IP，其次 X-Forwarded-For 首段，最後 REMOTE_ADDR。 */
  public static function clientIp(): string {
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
      return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
      return trim($parts[0]);
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
    return !empty($_SESSION['uid']);
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
