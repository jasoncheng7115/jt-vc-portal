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
    $d = self::load();
    $r = $d[$ip] ?? ['fails' => 0, 'first_at' => $now, 'locked_until' => null, 'lock_count' => 0];

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
    self::save($d);
    return self::status($ip);
  }

  /** 成功登入 → 清除該 IP 記錄。 */
  public static function reset(string $ip): void {
    $d = self::load();
    if (isset($d[$ip])) {
      unset($d[$ip]);
      self::save($d);
    }
  }
}
