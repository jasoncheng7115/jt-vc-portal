<?php
/**
 * OIDC 單一登入（Authorization Code + PKCE），供 Keycloak / Entra ID 等 IdP 使用。零外部相依。
 * 本系統不直接接 AD / LDAP：企業帳號一律經 OIDC IdP（地端 AD 由 Keycloak 聯合）。
 *
 * 安全重點（A01/A04/A07/A08）：
 *   - state（防 CSRF / 登入偽造，存 server 端 session、一次性、10 分鐘）、nonce（防 id_token 重放）、PKCE S256（防授權碼攔截）
 *   - id_token 以 IdP 的 JWKS 驗簽：演算法白名單 RS256/384/512（拒絕 none / HS* 等），並檢查 iss / aud / azp / exp / iat / nonce / sub
 *   - redirect_uri 固定為 SITE_URL/sso-callback；IdP 端點預設須為 https（require_https，可為內網 IdP 關閉）；擋雲端 metadata 主機；不跟隨轉址
 *   - 群組 → 角色：不在允許群組者一律拒絕；以 (iss, sub) 綁定帳號，絕不以 email / 帳號名稱自動併入既有帳號（防帳號接管）
 *   - 失敗只回通用訊息，詳細原因（已去控制字元、限長）只進稽核
 * 設計參考 jt-doc-tools（app/core/oidc.py、sso_provision.py、sso_routes.py）並補強：PKCE、azp、
 * 群組路徑正規化、id_token_hint 登出、僅限 SSO 模式、最後一位管理員保護。
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/auth.php';

class OidcError extends RuntimeException {}

class Oidc {
  const CACHE_FILE = DATA_DIR . '/oidc-cache.json';
  const CACHE_TTL  = 3600;
  const STATE_TTL  = 600;
  const SKEW       = 60;
  const ALGS       = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
  /** 雲端 metadata 服務（防 SSRF 取雲端憑證）；內網私有 IP 允許（地端 Keycloak 常見）。 */
  const BLOCKED_HOSTS = ['169.254.169.254', '100.100.100.200', 'metadata', 'metadata.google.internal', 'fd00:ec2::254'];

  public static function cfg(): array { return Settings::getOidc(); }

  public static function enabled(): bool {
    $c = self::cfg();
    return $c['enabled'] && $c['issuer'] !== '' && $c['client_id'] !== '';
  }

  public static function redirectUri(): string { return rtrim(SITE_URL, '/') . '/sso-callback'; }

  // ---------- HTTP ----------

  private static function allowHttp(): bool { return !self::cfg()['require_https']; }

  public static function checkUrl(string $url): void {
    $p = parse_url($url);
    $s = $p['scheme'] ?? '';
    if ($s !== 'https' && !($s === 'http' && self::allowHttp())) throw new OidcError('IdP endpoint must use https');
    $h = strtolower(trim((string)($p['host'] ?? ''), '[]'));
    if ($h === '' || in_array($h, self::BLOCKED_HOSTS, true)) throw new OidcError('IdP host not allowed');
    if (isset($p['user']) || isset($p['pass'])) throw new OidcError('IdP URL must not contain credentials');
  }

  /** @return array [http code, body] */
  private static function http(string $method, string $url, array $headers = [], ?string $body = null): array {
    self::checkUrl($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST  => $method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT        => 10,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | (self::allowHttp() ? CURLPROTO_HTTP : 0),
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($raw === false) throw new OidcError('IdP connection failed (curl ' . $errno . ')');
    return [$code, (string)$raw];
  }

  private static function getJson(string $url, array $headers = []): array {
    [$code, $raw] = self::http('GET', $url, $headers);
    $d = json_decode($raw, true);
    if ($code !== 200 || !is_array($d)) throw new OidcError("IdP returned HTTP {$code}");
    return $d;
  }

  // ---------- Discovery / JWKS（快取於 oidc-cache.json，每 issuer 1 小時）----------

  private static function cache(): array { return Store::read(self::CACHE_FILE, []); }

  public static function discovery(bool $refresh = false): array {
    $iss = rtrim(self::cfg()['issuer'], '/');
    $cache = self::cache();
    if (!$refresh && ($cache['issuer'] ?? '') === $iss && (time() - (int)($cache['disc_at'] ?? 0)) < self::CACHE_TTL && !empty($cache['disc'])) {
      return $cache['disc'];
    }
    self::checkUrl($iss);
    $d = self::getJson($iss . '/.well-known/openid-configuration');
    if (rtrim((string)($d['issuer'] ?? ''), '/') !== $iss) throw new OidcError('Discovery issuer mismatch');
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
      if (empty($d[$k]) || !is_string($d[$k])) throw new OidcError("Discovery missing {$k}");
      self::checkUrl($d[$k]);
    }
    foreach (['userinfo_endpoint', 'end_session_endpoint'] as $k) {
      if (isset($d[$k])) { if (!is_string($d[$k])) unset($d[$k]); else self::checkUrl($d[$k]); }
    }
    Store::update(self::CACHE_FILE, function ($x) use ($iss, $d) {
      if (($x['issuer'] ?? '') !== $iss) $x = [];
      $x['issuer'] = $iss; $x['disc'] = $d; $x['disc_at'] = time();
      return $x;
    }, []);
    return $d;
  }

  public static function jwks(bool $refresh = false): array {
    $cache = self::cache();
    $iss = rtrim(self::cfg()['issuer'], '/');
    $fresh = ($cache['issuer'] ?? '') === $iss && !empty($cache['jwks']);
    if (!$refresh && $fresh && (time() - (int)($cache['jwks_at'] ?? 0)) < self::CACHE_TTL) return $cache['jwks'];
    // 未知 kid 觸發的強制更新最多每 60 秒一次（防被拿來打 IdP）
    if ($refresh && $fresh && (time() - (int)($cache['jwks_at'] ?? 0)) < 60) return $cache['jwks'];
    $keys = self::getJson(self::discovery()['jwks_uri'])['keys'] ?? null;
    if (!is_array($keys)) throw new OidcError('Invalid JWKS');
    Store::update(self::CACHE_FILE, function ($x) use ($iss, $keys) {
      if (($x['issuer'] ?? '') !== $iss) $x = ['issuer' => $iss];
      $x['jwks'] = $keys; $x['jwks_at'] = time();
      return $x;
    }, []);
    return $keys;
  }

  // ---------- JWT / JWK ----------

  public static function b64urlDecode(string $s): string {
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($r === false) throw new OidcError('Invalid base64url');
    return $r;
  }

  private static function derLen(int $n): string {
    if ($n < 128) return chr($n);
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
  }

  private static function derInt(string $bytes): string {
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || ord($bytes[0]) > 0x7f) $bytes = "\0" . $bytes;
    return "\x02" . self::derLen(strlen($bytes)) . $bytes;
  }

  /** RSA JWK（n, e）→ PEM 公鑰（SubjectPublicKeyInfo）。 */
  public static function jwkToPem(array $jwk): string {
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) throw new OidcError('Unsupported JWK');
    $rsa = self::derInt(self::b64urlDecode($jwk['n'])) . self::derInt(self::b64urlDecode($jwk['e']));
    $rsa = "\x30" . self::derLen(strlen($rsa)) . $rsa;
    $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";   // rsaEncryption, NULL
    $bit = "\x03" . self::derLen(strlen($rsa) + 1) . "\x00" . $rsa;
    $spki = "\x30" . self::derLen(strlen($algId . $bit)) . $algId . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
  }

  /** 驗證 JWT 簽章並回傳 payload。$refresh = 找不到 kid 時重新抓 JWKS 的 callable。 */
  public static function verifyJwt(string $jwt, array $keys, ?callable $refresh = null): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new OidcError('Malformed JWT');
    $h = json_decode(self::b64urlDecode($parts[0]), true);
    $p = json_decode(self::b64urlDecode($parts[1]), true);
    if (!is_array($h) || !is_array($p)) throw new OidcError('Malformed JWT');
    $alg = (string)($h['alg'] ?? '');
    if (!isset(self::ALGS[$alg])) throw new OidcError('Unsupported JWT alg');   // 固定白名單：拒絕 none / HS*（alg 混淆攻擊）
    $kid = $h['kid'] ?? null;
    $pick = function (array $keys) use ($kid, $alg) {
      foreach ($keys as $k) {
        if (!is_array($k) || ($k['kty'] ?? '') !== 'RSA') continue;
        if (isset($k['use']) && $k['use'] !== 'sig') continue;
        if (isset($k['alg']) && $k['alg'] !== $alg) continue;
        if ($kid !== null && ($k['kid'] ?? null) !== $kid) continue;
        return $k;
      }
      return null;
    };
    $jwk = $pick($keys);
    if ($jwk === null && $refresh) $jwk = $pick($refresh());
    if ($jwk === null) throw new OidcError('No matching signing key');
    $ok = openssl_verify($parts[0] . '.' . $parts[1], self::b64urlDecode($parts[2]), self::jwkToPem($jwk), self::ALGS[$alg]);
    if ($ok !== 1) throw new OidcError('Invalid JWT signature');
    return $p;
  }

  /** 驗證 id_token 聲明。 */
  public static function validateClaims(array $p, string $issuer, string $clientId, string $nonce, ?int $now = null): void {
    $now = $now ?? time();
    if (rtrim((string)($p['iss'] ?? ''), '/') !== rtrim($issuer, '/')) throw new OidcError('iss mismatch');
    $aud = $p['aud'] ?? [];
    $aud = is_array($aud) ? $aud : [$aud];
    if (!in_array($clientId, $aud, true)) throw new OidcError('aud mismatch');
    if ((count($aud) > 1 || isset($p['azp'])) && ($p['azp'] ?? '') !== $clientId) throw new OidcError('azp mismatch');
    if (!isset($p['exp']) || (int)$p['exp'] < $now - self::SKEW) throw new OidcError('id_token expired');
    if (isset($p['iat']) && (int)$p['iat'] > $now + self::SKEW) throw new OidcError('id_token issued in the future');
    if (!isset($p['nonce']) || !is_string($p['nonce']) || !hash_equals($nonce, $p['nonce'])) throw new OidcError('nonce mismatch');
    if (!is_string($p['sub'] ?? null) || trim($p['sub']) === '') throw new OidcError('missing sub');
  }

  // ---------- 流程 ----------

  private static function rand(int $bytes): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }

  public static function pkceChallenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
  }

  /** 產生授權網址並把 state / nonce / code_verifier 存進 server 端 session（cookie 只有 session id）。 */
  public static function authorizeUrl(): string {
    $c = self::cfg();
    $d = self::discovery();
    $state = self::rand(24); $nonce = self::rand(24); $verifier = self::rand(48);
    $_SESSION['oidc_pending'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'ts' => time()];
    $q = http_build_query([
      'response_type'         => 'code',
      'client_id'             => $c['client_id'],
      'redirect_uri'          => self::redirectUri(),
      'scope'                 => $c['scopes'],
      'state'                 => $state,
      'nonce'                 => $nonce,
      'code_challenge'        => self::pkceChallenge($verifier),
      'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
    $ep = $d['authorization_endpoint'];
    return $ep . (strpos($ep, '?') === false ? '?' : '&') . $q;
  }

  /**
   * 處理回呼：驗 state → 以 code + PKCE 換 token → 驗 id_token → 取群組。
   * 回傳 ['claims' => [...], 'groups' => [...], 'id_token' => string]。
   */
  public static function handleCallback(array $query): array {
    $pending = $_SESSION['oidc_pending'] ?? null;
    unset($_SESSION['oidc_pending']);                                  // 一次性（無論成功失敗）
    if (!empty($query['error'])) throw new OidcError('IdP error: ' . self::safeText((string)$query['error']));
    if (!is_array($pending) || (time() - (int)($pending['ts'] ?? 0)) > self::STATE_TTL) throw new OidcError('login transaction missing or expired');
    if (!is_string($query['state'] ?? null) || !hash_equals($pending['state'], $query['state'])) throw new OidcError('state mismatch');
    $code = $query['code'] ?? '';
    if (!is_string($code) || $code === '' || strlen($code) > 4096) throw new OidcError('missing code');

    $c = self::cfg();
    $d = self::discovery();
    [$http, $raw] = self::http('POST', $d['token_endpoint'], [
      'Content-Type: application/x-www-form-urlencoded',
      'Authorization: Basic ' . base64_encode(rawurlencode($c['client_id']) . ':' . rawurlencode($c['client_secret'])),
    ], http_build_query([
      'grant_type'    => 'authorization_code',
      'code'          => $code,
      'redirect_uri'  => self::redirectUri(),
      'code_verifier' => $pending['verifier'],
    ], '', '&', PHP_QUERY_RFC3986));
    $tok = json_decode($raw, true);
    if ($http !== 200 || !is_array($tok) || empty($tok['id_token']) || !is_string($tok['id_token'])) throw new OidcError("token request failed (HTTP {$http})");

    // 一律先驗 id_token（失敗即拒絕，不退回只用 userinfo）
    $claims = self::verifyJwt($tok['id_token'], self::jwks(), fn() => self::jwks(true));
    self::validateClaims($claims, $d['issuer'], $c['client_id'], $pending['nonce']);

    $groups = self::groupsFrom($claims, $c['groups_claim']);
    if ($groups === null && !empty($d['userinfo_endpoint']) && !empty($tok['access_token']) && is_string($tok['access_token'])) {
      $ui = self::getJson($d['userinfo_endpoint'], ['Authorization: Bearer ' . $tok['access_token']]);
      if (($ui['sub'] ?? null) !== $claims['sub']) throw new OidcError('userinfo sub mismatch');
      $groups = self::groupsFrom($ui, $c['groups_claim']);
    }
    return ['claims' => $claims, 'groups' => $groups ?? [], 'id_token' => $tok['id_token']];
  }

  /** 從 claims 取群組清單（陣列或逗號分隔字串）；claim 不存在回 null。 */
  public static function groupsFrom(array $claims, string $claim): ?array {
    if (!array_key_exists($claim, $claims)) return null;
    $g = $claims[$claim];
    if (is_string($g)) $g = explode(',', $g);
    if (!is_array($g)) return [];
    return array_values(array_filter(array_map(fn($x) => is_scalar($x) ? trim((string)$x) : '', $g), 'strlen'));
  }

  /**
   * 群組 → 角色：admin 優先；都不符合回 null（拒絕登入）。
   * 比對規則：不分大小寫；Keycloak「完整群組路徑」/A/B 可用完整路徑（A/B）或最後一段（B）比對。
   */
  public static function roleFor(array $groups, ?array $cfg = null): ?string {
    $c = $cfg ?? self::cfg();
    $norm = fn($s) => trim(strtolower(trim((string)$s)), '/');
    $have = [];
    foreach ($groups as $g) {
      $n = $norm($g);
      if ($n === '') continue;
      $have[$n] = true;
      $parts = explode('/', $n);
      $have[end($parts)] = true;
    }
    foreach (['admin' => $c['admin_groups'], 'host' => $c['host_groups']] as $role => $list) {
      foreach (preg_split('/\s*,\s*/', (string)$list, -1, PREG_SPLIT_NO_EMPTY) as $want) {
        if (isset($have[$norm($want)])) return $role;
      }
    }
    return null;
  }

  /**
   * 依 (iss, sub) 找或建立 SSO 帳號，並同步角色 / 顯示名稱 / email。
   * 帳號名稱或 email 已被其他帳號使用時拒絕（不自動併入、不自動改名）。
   */
  public static function provision(array $claims, string $role): array {
    $c = self::cfg();
    $iss = rtrim((string)$claims['iss'], '/');
    $sub = (string)$claims['sub'];
    $uname = trim((string)($claims[$c['username_claim']] ?? ''));
    if ($uname === '') $uname = trim((string)($claims[$c['email_claim']] ?? ''));
    $uname = substr(preg_replace('/[^A-Za-z0-9._@-]/', '', $uname), 0, 64);
    if ($uname === '') $uname = 'sso-' . substr(hash('sha256', $iss . '|' . $sub), 0, 10);
    $email = (string)($claims[$c['email_claim']] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    $display = trim((string)($claims[$c['name_claim']] ?? '')) ?: $uname;
    $display = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $display), 0, 60);

    foreach (Users::all() as $u) {
      if (($u['oidc_iss'] ?? '') === $iss && ($u['oidc_sub'] ?? '') === $sub) {
        if (!empty($u['disabled'])) throw new OidcError('account disabled');
        // 群組同步不可把「最後一位啟用中的管理員」降級（避免無人可管）
        if (($u['role'] ?? '') === 'admin' && $role !== 'admin' && Users::adminCount() <= 1) $role = 'admin';
        $fields = ['role' => $role, 'display_name' => $display];
        if ($email !== '') {
          $other = Users::findByLogin($email);
          if (!$other || $other['id'] === $u['id']) $fields['email'] = $email;
        }
        return Users::update($u['id'], $fields) ?? $u;
      }
    }
    if (Users::findByLogin($uname) || ($email !== '' && Users::findByLogin($email))) {
      throw new OidcError('username or email conflicts with an existing account');
    }
    return Users::createSso(['username' => $uname, 'display_name' => $display, 'email' => $email, 'role' => $role, 'oidc_iss' => $iss, 'oidc_sub' => $sub]);
  }

  /** 例外 → 可安全寫入稽核的原因（OidcError 用其訊息，其他只記類別名稱）。 */
  public static function safeReason(Throwable $e): string {
    return self::safeText($e instanceof OidcError ? $e->getMessage() : get_class($e));
  }

  /** 去控制字元、限長（防日誌注入 / 灌爆）。 */
  public static function safeText(string $s): string {
    return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s), 0, 120);
  }

  /** IdP 的登出網址（RP-initiated logout，帶 id_token_hint）；沒有就回 null。 */
  public static function logoutUrl(?string $idToken): ?string {
    if (!self::enabled()) return null;
    try { $d = self::discovery(); } catch (Throwable $e) { return null; }
    if (empty($d['end_session_endpoint'])) return null;
    $q = ['client_id' => self::cfg()['client_id'], 'post_logout_redirect_uri' => rtrim(SITE_URL, '/') . '/'];
    if ($idToken) $q['id_token_hint'] = $idToken;
    $ep = $d['end_session_endpoint'];
    return $ep . (strpos($ep, '?') === false ? '?' : '&') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
  }

  /** IdP 來源（CSP form-action 用：登出表單會被導到 IdP）。 */
  public static function origin(): string {
    $p = parse_url(self::cfg()['issuer']);
    if (empty($p['host'])) return '';
    return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
  }

  /** 僅限 SSO 模式下，本地密碼登入是否允許此來源 IP（清單空白＝只允許本機）。 */
  public static function localLoginAllowed(string $ip): bool {
    $c = self::cfg();
    if (!self::enabled() || !$c['sso_only']) return true;
    $list = trim($c['local_login_cidrs']) !== '' ? $c['local_login_cidrs'] : '127.0.0.1,::1';
    foreach (preg_split('/\s*,\s*/', $list, -1, PREG_SPLIT_NO_EMPTY) as $cidr) {
      if (Auth::ipMatches($ip, $cidr)) return true;
    }
    return false;
  }
}
