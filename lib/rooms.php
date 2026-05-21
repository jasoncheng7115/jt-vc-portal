<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/store.php';

class Rooms {
  /** 會議時長 session 記錄檔（主持人離開時 append 一筆）。 */
  const MEETINGS_FILE = DATA_DIR . '/meetings.jsonl';

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
          'host_joined_at' => isset($v['host_joined_at']) ? (int)$v['host_joined_at'] : null, // 本次主持 session 起始
          'owner'        => $v['owner'] ?? null,         // 建立者 user id
          'owner_name'   => $v['owner_name'] ?? null,    // 顯示用
          'attendees'    => is_array($v['attendees'] ?? null) ? $v['attendees'] : [],
          'lobby'        => !empty($v['lobby']),          // 大廳模式：主持人進場自動開啟
        ];
        if (isset($v['roster']) && is_array($v['roster'])) $out[$name]['roster'] = $v['roster']; // 本次 session 與會者名冊快照
      }
    }
    return $out;
  }

  public static function save(array $rooms): void {
    @file_put_contents(AUTO_ALLOW_FILE, json_encode($rooms, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * 清理過期並回傳有效房間。保留規則（讓未來排程的會議室也留得住）：
   *   - 主持人在線（進行中）→ 一律保留
   *   - 有結束時間 → 保留到 ends_at + 1 小時
   *   - 只有開始時間 → 保留到 starts_at + 24 小時
   *   - 無排程 → 依建立時間 created_at + TTL(24h)
   */
  public static function pruneAndGet(): array {
    $now = time();
    $rooms = self::load();
    $valid = [];
    foreach ($rooms as $name => $r) {
      $keep = false;
      if (self::isHostPresent($r, $now)) {
        $keep = true;                                              // 進行中
      } elseif (($r['ends_at'] ?? null) !== null) {
        $keep = ($now - (int)$r['ends_at']) <= 3600;              // 結束後 1h 內
      } elseif (($r['starts_at'] ?? null) !== null) {
        $keep = ($now - (int)$r['starts_at']) <= 86400;          // 開始後 24h 內（無結束時間）
      } else {
        $keep = ($now - ($r['created_at'] ?? 0)) <= ROOM_TTL_SECONDS; // 無排程：建立後 24h
      }
      if ($keep) $valid[$name] = $r;
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

  /** 主持人離開 → 結算本次 session 時長寫入 meetings.jsonl，並把 host_joined 標回 false */
  public static function setHostLeft(string $room): void {
    $rooms = self::load();
    if (isset($rooms[$room]) && is_array($rooms[$room])) {
      $r = $rooms[$room];
      if (!empty($r['host_joined_at'])) {
        $start = (int)$r['host_joined_at'];
        $end   = time();
        if ($end > $start) {
          [$participants, $peak] = self::finalizeRoster($r['roster'] ?? null, $start, $end);
          Store::appendLine(self::MEETINGS_FILE, [
            'ts'         => $end,
            'time'       => date('c', $end),
            'room'       => $room,
            'start'      => $start,
            'end'        => $end,
            'dur'        => $end - $start,           // 秒
            'owner'      => $r['owner'] ?? '',
            'owner_name' => $r['owner_name'] ?? '',
            'attendees'  => count($participants),    // 不重複參與者數
            'peak'       => $peak,                    // 尖峰同時人數
            'participants' => $participants,          // [{name,in,out}]，參與者時間軸
          ]);
        }
      unset($rooms[$room]['roster']);
      }
      $rooms[$room]['host_joined'] = false;
      unset($rooms[$room]['host_seen_at'], $rooms[$room]['host_joined_at']);
      self::save($rooms);
    }
  }

  /** 主持人心跳：寫入 host_seen_at（並確保 host_joined=true、記下 session 起始）；可附帶與會者名冊快照 */
  public static function recordHostHeartbeat(string $room, ?array $roster = null): void {
    $rooms = self::load();
    if (!isset($rooms[$room]) || !is_array($rooms[$room])) return;
    $rooms[$room]['host_joined']  = true;
    $rooms[$room]['host_seen_at'] = time();
    if (empty($rooms[$room]['host_joined_at'])) $rooms[$room]['host_joined_at'] = time();
    if (is_array($roster)) {
      $clean = [];
      foreach (array_slice($roster, 0, 200) as $p) {   // 上限 200，避免 rooms.json 膨脹
        if (!is_array($p)) continue;
        $in = (int)($p['in'] ?? 0);
        if ($in <= 0) continue;
        $clean[] = [
          'name' => mb_substr(trim((string)($p['name'] ?? '')), 0, 64),
          'in'   => $in,
          'out'  => (isset($p['out']) && $p['out'] !== null) ? (int)$p['out'] : null,
        ];
      }
      $rooms[$room]['roster'] = $clean;
    }
    self::save($rooms);
  }

  /** 把名冊快照結算成 [participants[{name,in,out}], 尖峰同時人數]；時間 clamp 進 [start,end]。 */
  private static function finalizeRoster($roster, int $start, int $end): array {
    if (!is_array($roster) || !$roster) return [[], 0];
    $participants = [];
    $evts = [];
    foreach ($roster as $p) {
      if (!is_array($p)) continue;
      $in  = (int)($p['in'] ?? 0);
      if ($in <= 0) continue;
      $out = (isset($p['out']) && $p['out'] !== null) ? (int)$p['out'] : $end;  // 未離場 → 算到散會
      $in  = max($in, $start);
      $out = min(max($out, $in), $end);
      $participants[] = ['name' => (string)($p['name'] ?? ''), 'in' => $in, 'out' => $out];
      $evts[] = [$in, 1];
      $evts[] = [$out, -1];
    }
    // 尖峰：同一時刻先 +1 再 -1（讓瞬間重疊也計入）
    usort($evts, fn($a, $b) => ($a[0] <=> $b[0]) ?: ($b[1] <=> $a[1]));
    $cur = 0; $peak = 0;
    foreach ($evts as $e) { $cur += $e[1]; if ($cur > $peak) $peak = $cur; }
    return [$participants, $peak];
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
      if (empty($existing['host_joined_at'])) $existing['host_joined_at'] = time();
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

  /** 清理 meetings.jsonl 中結束時間早於保留天數的記錄（保留最近 N 天）。 */
  public static function pruneMeetings(int $days): void {
    if (!file_exists(self::MEETINGS_FILE)) return;
    $cutoff = time() - max(7, $days) * 86400;
    $lines = @file(self::MEETINGS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $kept = [];
    $changed = false;
    foreach ($lines as $ln) {
      $e = json_decode($ln, true);
      $end = is_array($e) ? (int)($e['end'] ?? $e['ts'] ?? 0) : 0;
      if ($end >= $cutoff) $kept[] = $ln;
      else $changed = true;
    }
    if ($changed) {
      @file_put_contents(self::MEETINGS_FILE, $kept ? implode("\n", $kept) . "\n" : '', LOCK_EX);
    }
  }

  /** 讀取「結束時間」落在 [$fromTs,$toTs] 內的會議 session（舊→新）。 */
  public static function meetingSessions(int $fromTs, int $toTs): array {
    if (!file_exists(self::MEETINGS_FILE)) return [];
    $lines = @file(self::MEETINGS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach ($lines as $ln) {
      $e = json_decode($ln, true);
      if (!is_array($e)) continue;
      $end = (int)($e['end'] ?? $e['ts'] ?? 0);
      if ($end >= $fromTs && $end <= $toTs) $out[] = $e;
    }
    return $out;
  }

  /** 時長最長的前 N 場會議（所有保留中的記錄，依 dur 由長到短）。 */
  public static function topMeetingsByDuration(int $limit = 25): array {
    if (!file_exists(self::MEETINGS_FILE)) return [];
    $lines = @file(self::MEETINGS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach ($lines as $ln) {
      $e = json_decode($ln, true);
      if (is_array($e) && (int)($e['dur'] ?? 0) > 0) $out[] = $e;
    }
    usort($out, fn($a, $b) => ((int)($b['dur'] ?? 0)) <=> ((int)($a['dur'] ?? 0)));
    return array_slice($out, 0, max(1, $limit));
  }

  /** 會議室名稱正規化：僅留 ASCII 英數與 - _（Jitsi 會議室不支援非 ASCII 名稱，中文等會被移除）。 */
  public static function sanitize(string $room): string {
    $room = trim($room);
    $room = preg_replace('/\s+/u', '-', $room);          // 空白 → -
    $room = preg_replace('/[^A-Za-z0-9_-]/', '', $room);  // 僅留英數 - _（移除中文等非 ASCII）
    $room = preg_replace('/-+/', '-', $room);             // 收斂連續 -
    $room = trim($room, '-_');
    return substr($room, 0, 64);
  }

  /** datetime-local 字串轉 unix（依 PHP 預設時區，已在 config.php 設成 Asia/Taipei） */
  public static function parseDateTimeLocal(?string $s): ?int {
    if (!$s) return null;
    $t = strtotime($s);
    return $t === false ? null : $t;
  }
}
