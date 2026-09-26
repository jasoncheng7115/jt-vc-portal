<?php
/**
 * fail2ban 樣式登入限流（OWASP A07）。依「真實來源 IP」鎖定。
 * /var/jaas-data/login-attempts.json
 *   { "<ip>": { "fails": int, "first_at": ts, "locked_until": ts|null, "lock_count": int } }
 *
 * 規則：FAIL_WINDOW 內失敗達 MAX_FAILS → 鎖 LOCK_SECONDS；
 *       連續鎖 ESCALATE_AFTER 次 → 升級鎖 ESCALATED_SECONDS。
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/store.php';

class RateLimit {
  const FILE = DATA_DIR . '/login-attempts.json';
  const MAX_FAILS         = 5;
  const FAIL_WINDOW       = 600;    // 10 分鐘內累計
  const LOCK_SECONDS      = 1800;   // 鎖 30 分鐘
  const ESCALATE_AFTER    = 3;      // 連續鎖 3 次升級
  const ESCALATED_SECONDS = 86400;  // 升級鎖 24 小時

  // 帳號層鎖定（防分散 IP 暴力破解同一帳號）：15 分鐘內 10 次失敗 → 該帳號鎖 15 分鐘。
  // 不論帳號是否存在都計數（以登入字串雜湊為 key），回應一致，避免藉此列舉帳號。
  const ACCT_MAX_FAILS = 10;
  const ACCT_WINDOW    = 900;
  const ACCT_LOCK      = 900;

  private static function load(): array { return Store::read(self::FILE, []); }
  private static function save(array $d): void { Store::write(self::FILE, $d); }

  /** 回傳 ['locked'=>bool, 'until'=>int|null, 'remaining'=>int]（remaining = 鎖定前剩餘嘗試次數）。 */
  public static function status(string $ip): array {
    $now = time();
    $d = self::load();
    $r = $d[$ip] ?? null;
    if (!$r) return ['locked' => false, 'until' => null, 'remaining' => self::MAX_FAILS];

    if (!empty($r['locked_until']) && $r['locked_until'] > $now) {
      return ['locked' => true, 'until' => $r['locked_until'], 'remaining' => 0];
    }
    // 視窗過期 → 視為清零
    if (($now - ($r['first_at'] ?? 0)) > self::FAIL_WINDOW && empty($r['locked_until'])) {
      return ['locked' => false, 'until' => null, 'remaining' => self::MAX_FAILS];
    }
    $remaining = max(0, self::MAX_FAILS - (int)($r['fails'] ?? 0));
    return ['locked' => false, 'until' => null, 'remaining' => $remaining];
  }

  public static function isLocked(string $ip): bool {
    return self::status($ip)['locked'];
  }

  /** 記一次失敗，必要時鎖定。回傳更新後 status。 */
  public static function fail(string $ip): array {
    $now = time();
    Store::update(self::FILE, function (array $d) use ($ip, $now) {
      $r = $d[$ip] ?? ['fails' => 0, 'first_at' => $now, 'locked_until' => null, 'lock_count' => 0];
      // 鎖定已過期 → 清除鎖定狀態（保留 lock_count 供升級判斷）
      if (!empty($r['locked_until']) && $r['locked_until'] <= $now) {
        $r['locked_until'] = null;
        $r['fails'] = 0;
        $r['first_at'] = $now;
      }
      // 視窗過期 → 重新計算
      if (($now - ($r['first_at'] ?? 0)) > self::FAIL_WINDOW && empty($r['locked_until'])) {
        $r['fails'] = 0;
        $r['first_at'] = $now;
      }
      $r['fails'] = (int)($r['fails'] ?? 0) + 1;
      if ($r['fails'] >= self::MAX_FAILS) {
        $r['lock_count'] = (int)($r['lock_count'] ?? 0) + 1;
        $dur = $r['lock_count'] >= self::ESCALATE_AFTER ? self::ESCALATED_SECONDS : self::LOCK_SECONDS;
        $r['locked_until'] = $now + $dur;
        $r['fails'] = 0;
        $r['first_at'] = $now;
      }
      $d[$ip] = $r;
      return $d;
    }, []);
    return self::status($ip);
  }

  /** 成功登入 → 清除該 IP 記錄。 */
  public static function reset(string $ip): void {
    Store::update(self::FILE, function (array $d) use ($ip) {
      if (!isset($d[$ip])) return null;
      unset($d[$ip]);
      return $d;
    }, []);
  }

  private static function acctKey(string $login): string {
    return 'acct:' . hash('sha256', strtolower(trim($login)));
  }

  /** 該登入帳號（字串）是否被帳號層鎖定。 */
  public static function isAccountLocked(string $login): bool {
    $r = self::load()[self::acctKey($login)] ?? null;
    return $r && !empty($r['locked_until']) && $r['locked_until'] > time();
  }

  /** 記一次帳號層失敗。 */
  public static function failAccount(string $login): void {
    $k = self::acctKey($login);
    $now = time();
    Store::update(self::FILE, function (array $d) use ($k, $now) {
      $r = $d[$k] ?? ['fails' => 0, 'first_at' => $now, 'locked_until' => null];
      if (!empty($r['locked_until']) && $r['locked_until'] <= $now) $r = ['fails' => 0, 'first_at' => $now, 'locked_until' => null];
      if (($now - ($r['first_at'] ?? 0)) > self::ACCT_WINDOW) { $r['fails'] = 0; $r['first_at'] = $now; }
      $r['fails'] = (int)($r['fails'] ?? 0) + 1;
      if ($r['fails'] >= self::ACCT_MAX_FAILS) {
        $r['locked_until'] = $now + self::ACCT_LOCK;
        $r['fails'] = 0;
        $r['first_at'] = $now;
      }
      $d[$k] = $r;
      return $d;
    }, []);
  }

  /** 成功登入 → 清除帳號層計數。 */
  public static function resetAccount(string $login): void {
    self::reset(self::acctKey($login));
  }
}
