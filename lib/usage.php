<?php
/**
 * 8x8 JaaS USAGE webhook 計量持久化。
 * 以「計費週期」為單位（對齊 8x8 訂閱週期，可設每月起始日），key 為週期起始日 YYYY-MM-DD。
 *   /var/jaas-data/usage.json
 *   { "2026-04-23": { device_ids:[], idempotency_keys:[], baseline:int, last_event_at:int } }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/store.php';

class Usage {
  const FILE = DATA_DIR . '/usage.json';

  public static function load(): array {
    return Store::read(self::FILE, []);
  }

  public static function save(array $data): void {
    Store::write(self::FILE, $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }

  /** 某時間點所屬計費週期的起始日（YYYY-MM-DD），依設定的每月起始日。 */
  public static function periodFor(int $ts): string {
    $day = Settings::getBillingStartDay();        // 1..28
    $Y = (int)date('Y', $ts);
    $M = (int)date('n', $ts);
    $D = (int)date('j', $ts);
    if ($D < $day) {                              // 還沒到本期起始日 → 屬上一期
      $M--;
      if ($M < 1) { $M = 12; $Y--; }
    }
    return sprintf('%04d-%02d-%02d', $Y, $M, $day);
  }

  public static function currentPeriod(): string {
    return self::periodFor(time());
  }

  /** 顯示用：本期區間文字，如「04/23 ～ 05/23」。 */
  public static function currentPeriodLabel(): string {
    $start = strtotime(self::currentPeriod());
    $end = strtotime('+1 month', $start);
    return date('Y/m/d', $start) . ' ～ ' . date('Y/m/d', $end);
  }

  /** 本期顯示用量 = baseline + 追蹤到的 unique 裝置數。 */
  public static function currentMonthCount(): int {
    return self::periodCount(self::currentPeriod());
  }

  public static function periodCount(string $period): int {
    $d = self::load();
    $tracked  = count($d[$period]['device_ids'] ?? []);
    $baseline = (int)($d[$period]['baseline'] ?? 0);
    return max(0, $baseline + $tracked);
  }

  /** 手動把「本期目前用量」校正成 $value（baseline = value - 已追蹤數）。 */
  public static function setCurrentValue(int $value): void {
    $p = self::currentPeriod();
    Store::update(self::FILE, function (array $d) use ($p, $value) {
      if (!isset($d[$p])) $d[$p] = ['device_ids' => [], 'idempotency_keys' => [], 'last_event_at' => 0];
      $tracked = count($d[$p]['device_ids'] ?? []);
      $d[$p]['baseline'] = max(0, $value - $tracked);
      return $d;
    }, []);
  }

  /** 處理一筆 USAGE 事件 payload。 */
  public static function processEvent(array $payload): array {
    $key = $payload['idempotencyKey'] ?? '';
    $ts_ms = $payload['timestamp'] ?? null;
    if (!is_numeric($ts_ms)) return ['ok' => false, 'reason' => 'no timestamp'];
    $period = self::periodFor((int)intdiv((int)$ts_ms, 1000));

    $dup = false; $added = 0;
    Store::update(self::FILE, function (array $d) use ($period, $key, $payload, &$dup, &$added) {
      if (!isset($d[$period])) $d[$period] = ['device_ids' => [], 'idempotency_keys' => [], 'last_event_at' => 0];
      if ($key !== '' && in_array($key, $d[$period]['idempotency_keys'] ?? [], true)) {
        $dup = true;
        return null;
      }
      foreach (($payload['data'] ?? []) as $item) {
        $id = is_array($item) ? ($item['deviceId'] ?? null) : null;
        if (!$id || !is_scalar($id)) continue;
        if (!in_array($id, $d[$period]['device_ids'] ?? [], true)) {
          $d[$period]['device_ids'][] = $id;
          $added++;
        }
      }
      if ($key !== '') $d[$period]['idempotency_keys'][] = $key;
      $d[$period]['last_event_at'] = time();
      return $d;
    }, []);
    if ($dup) return ['ok' => true, 'duplicate' => true, 'added' => 0, 'period' => $period];

    return ['ok' => true, 'duplicate' => false, 'added' => $added, 'period' => $period];
  }
}
