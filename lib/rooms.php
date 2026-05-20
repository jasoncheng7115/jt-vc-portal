<?php
require_once __DIR__ . '/../config.php';

class Rooms {
  /** 讀取整份資料（自動把舊版 int 結構升級成新版結構） */
  public static function load(): array {
    if (!file_exists(AUTO_ALLOW_FILE)) return [];
    $data = json_decode(@file_get_contents(AUTO_ALLOW_FILE), true);
    if (!is_array($data)) return [];

    $out = [];
    foreach ($data as $name => $v) {
      if (is_int($v)) {
        // 舊版：純 timestamp，視為「主持人已開啟、無排程」
        $out[$name] = [
          'created_at'  => $v,
          'starts_at'   => null,
          'ends_at'     => null,
          'host_joined' => true,
          'lobby'       => false,
        ];
      } elseif (is_array($v)) {
        $out[$name] = [
          'created_at'   => $v['created_at']  ?? time(),
          'starts_at'    => isset($v['starts_at']) && $v['starts_at'] !== null ? (int)$v['starts_at'] : null,
          'ends_at'      => isset($v['ends_at'])   && $v['ends_at']   !== null ? (int)$v['ends_at']   : null,
          'host_joined'  => !empty($v['host_joined']),
          'host_seen_at' => isset($v['host_seen_at']) ? (int)$v['host_seen_at'] : null,
          'owner'        => $v['owner'] ?? null,         // 建立者 user id
          'owner_name'   => $v['owner_name'] ?? null,    // 顯示用
          'attendees'    => is_array($v['attendees'] ?? null) ? $v['attendees'] : [],
          'lobby'        => !empty($v['lobby']),          // 大廳模式：主持人進場自動開啟
        ];
      }
    }
    return $out;
  }

  public static function save(array $rooms): void {
    @file_put_contents(AUTO_ALLOW_FILE, json_encode($rooms, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /** 清理過期（依 created_at + TTL）；同時清掉 ends_at 已過超過 1 小時的房間 */
  public static function pruneAndGet(): array {
    $now = time();
    $rooms = self::load();
    $valid = [];
    foreach ($rooms as $name => $r) {
      $tooOldByCreate = ($now - ($r['created_at'] ?? 0)) > ROOM_TTL_SECONDS;
      $tooOldByEnd    = $r['ends_at'] !== null && ($now - $r['ends_at']) > 3600;
      if ($tooOldByCreate || $tooOldByEnd) continue;
      $valid[$name] = $r;
    }
    if (count($valid) !== count($rooms)) self::save($valid);
    return $valid;
  }

  public static function get(string $room): ?array {
    $rooms = self::pruneAndGet();
    return $rooms[$room] ?? null;
  }

  /**
   * 新增或更新房間。$opts 可包含：
   *   - starts_at (int|null)
   *   - ends_at   (int|null)
   *   - host_joined (bool) - 為 true 時會將 host_joined 設成 true（不會回沖成 false）
   *   - update_schedule (bool) - true 時用 $opts 內的 starts_at/ends_at 覆寫；false 時保留現有值
   */
  /** 心跳超時門檻（秒）。> 此值未收到主持人心跳，視為已離開。 */
  const HOST_STALE_SECONDS = 45;

  /** 主持人離開 → 把該房間的 host_joined 標回 false 並清掉 host_seen_at */
  public static function setHostLeft(string $room): void {
    $rooms = self::load();
    if (isset($rooms[$room]) && is_array($rooms[$room])) {
      $rooms[$room]['host_joined'] = false;
      unset($rooms[$room]['host_seen_at']);
      self::save($rooms);
    }
  }

  /** 主持人心跳：寫入 host_seen_at（並確保 host_joined=true） */
  public static function recordHostHeartbeat(string $room): void {
    $rooms = self::load();
    if (!isset($rooms[$room]) || !is_array($rooms[$room])) return;
    $rooms[$room]['host_joined']  = true;
    $rooms[$room]['host_seen_at'] = time();
    self::save($rooms);
  }

  /** 判斷主持人是否真的還在（心跳新鮮）。沒有 host_seen_at 欄位（舊資料）→ 視為新鮮以維持相容。 */
  public static function isHostPresent(array $r, ?int $now = null): bool {
    if (empty($r['host_joined'])) return false;
    $now = $now ?? time();
    if (!isset($r['host_seen_at'])) return true; // legacy
    return ($now - (int)$r['host_seen_at']) <= self::HOST_STALE_SECONDS;
  }

  public static function upsert(string $room, array $opts = []): array {
    $rooms = self::pruneAndGet();
    $existing = $rooms[$room] ?? [
      'created_at'  => time(),
      'starts_at'   => null,
      'ends_at'     => null,
      'host_joined' => false,
      'lobby'       => false,
    ];

    if (!empty($opts['update_schedule'])) {
      $existing['starts_at'] = $opts['starts_at'] ?? null;
      $existing['ends_at']   = $opts['ends_at']   ?? null;
    }
    if (!empty($opts['host_joined'])) {
      $existing['host_joined']  = true;
      $existing['host_seen_at'] = time();
    }
    // owner 只在尚未有 owner 時設定（第一次建立者），避免事後被改寫
    if (!empty($opts['owner']) && empty($existing['owner'])) {
      $existing['owner']      = $opts['owner'];
      $existing['owner_name'] = $opts['owner_name'] ?? null;
    }
    if (array_key_exists('attendees', $opts)) {
      $existing['attendees'] = $opts['attendees'];
    }
    if (array_key_exists('lobby', $opts)) {
      $existing['lobby'] = !empty($opts['lobby']);
    }

    $rooms[$room] = $existing;
    self::save($rooms);
    return $existing;
  }

  /** 判斷來賓現在能不能進場（會考慮心跳時效） */
  public static function evaluate(array $r, ?int $now = null): array {
    $now = $now ?? time();
    $starts = $r['starts_at'] ?? null;
    $ends   = $r['ends_at']   ?? null;
    $host   = self::isHostPresent($r, $now);

    // 主持人在線 → 一律放行
    if ($host) {
      return ['allow' => true, 'status' => 'open',
              'starts_at' => $starts, 'ends_at' => $ends, 'host_joined' => true];
    }
    // 沒設排程 → 等候主持人
    if ($starts === null) {
      return ['allow' => false, 'status' => 'wait_host',
              'starts_at' => null, 'ends_at' => $ends, 'host_joined' => false];
    }
    // 時間還沒到 → 倒數
    if ($now < $starts) {
      return ['allow' => false, 'status' => 'countdown',
              'starts_at' => $starts, 'ends_at' => $ends, 'host_joined' => false];
    }
    // 已結束 → expired
    if ($ends !== null && $now > $ends) {
      return ['allow' => false, 'status' => 'expired',
              'starts_at' => $starts, 'ends_at' => $ends, 'host_joined' => false];
    }
    // 在開放時段內 → 直接放行
    return ['allow' => true, 'status' => 'open',
            'starts_at' => $starts, 'ends_at' => $ends, 'host_joined' => false];
  }

  public static function sanitize(string $room): string {
    $room = trim($room);
    $room = preg_replace('/\s+/u', '-', $room);
    $room = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $room);
    return mb_substr($room, 0, 64);
  }

  /** datetime-local 字串轉 unix（依 PHP 預設時區，已在 config.php 設成 Asia/Taipei） */
  public static function parseDateTimeLocal(?string $s): ?int {
    if (!$s) return null;
    $t = strtotime($s);
    return $t === false ? null : $t;
  }
}
