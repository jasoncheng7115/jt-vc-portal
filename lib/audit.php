<?php
/**
 * 稽核記錄（OWASP A09）。記錄所有重要行為，append-only JSONL + 即時外拋 SIEM。
 * /var/jaas-data/audit-log.jsonl
 *   { ts, time, action, detail, actor, actor_name, role, result, ip, user_agent }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logship.php';

class Audit {
  const FILE = DATA_DIR . '/audit-log.jsonl';

  /** 行為代碼 → 中文標籤（顯示與篩選用）。 */
  public static function labels(): array {
    return [
      'login'          => t('登入成功'),
      'login_fail'     => t('登入失敗'),
      'login_2fa_fail' => t('2FA 驗證失敗'),
      'sso_login'      => t('單一登入成功'),
      'sso_fail'       => t('單一登入失敗'),
      'transcript_request'  => t('產生逐字稿'),
      'transcript_done'     => t('逐字稿完成'),
      'transcript_failed'   => t('逐字稿失敗'),
      'transcript_cancel'   => t('取消逐字稿'),
      'transcript_delete'   => t('刪除逐字稿'),
      'transcript_view'     => t('檢視逐字稿'),
      'transcript_download' => t('下載逐字稿'),
      'transcript_speakers' => t('逐字稿發言者改名'),
      'login_locked'   => t('登入遭鎖定'),
      'logout'         => t('登出'),
      'room_create'    => t('建立會議室連結'),
      'room_delete'    => t('刪除會議室'),
      'room_enter'     => t('進入會議室（主持）'),
      'guest_join'     => t('來賓進入會議'),
      'invite_sent'    => t('寄送邀請郵件'),
      'account_create' => t('建立帳號'),
      'account_update' => t('修改帳號'),
      'account_delete' => t('刪除帳號'),
      'settings_update'=> t('修改系統設定'),
      'site_update'    => t('修改站台設定'),
      'theme_update'   => t('切換外觀主題'),
      'password_change'=> t('變更密碼'),
      'profile_update' => t('更新個人資料'),
      '2fa_enable'     => t('啟用 2FA'),
      '2fa_disable'    => t('停用 2FA'),
      'usage_baseline' => t('校正用量基準'),
      'audit_export'       => t('匯出稽核記錄'),
      'recording_download' => t('下載錄影'),
      'recording_delete'   => t('刪除錄影'),
      'recording_cleanup'  => t('清理錄影'),
    ];
  }

  /**
   * 記錄一筆行為。預設行為人取自目前 session；可用 $opts 覆寫（如登入前、或來賓）。
   * $opts: actor, actor_name, role, result(ok|fail|warn), severity(info|warning|error)
   */
  public static function log(string $action, string|array $detail = '', array $opts = []): void {
    Auth::start();
    // tk() 延後翻譯：記下鍵與變數，顯示時依看的人的語言翻譯；detail 仍存一份目前語言的文字（SIEM / 舊版相容）
    $dk = null; $dv = [];
    if (is_array($detail)) {
      $dk = (string)($detail['k'] ?? ''); $dv = array_map('strval', (array)($detail['v'] ?? []));
      $detail = self::render($dk, $dv);
      if (str_contains($dk, '{rec}') && ($dv['id'] ?? '') !== '') $detail .= ' [' . $dv['id'] . ']';   // SIEM 仍帶完整錄影 id 方便追查
    }
    $cli = PHP_SAPI === 'cli';
    $actor = $opts['actor']      ?? ($_SESSION['username'] ?? ($cli ? 'system' : ''));
    $name  = $opts['actor_name'] ?? ($_SESSION['display_name'] ?? '');
    $role  = $opts['role']       ?? ($_SESSION['role'] ?? '');
    $result = $opts['result']    ?? 'ok';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    // 長度上限：避免攻擊者以超長帳號字串 / 詳情灌爆記錄檔
    $actor  = mb_substr((string)$actor, 0, 128);
    $name   = mb_substr((string)$name, 0, 128);
    $detail = mb_substr($detail, 0, 1000);
    $ip = $cli ? '' : Auth::clientIp();                  // 排程 / 指令列沒有來源 IP

    $entry = [
      'ts'         => time(),
      'time'       => date('c'),
      'action'     => $action,
      'detail'     => $detail,
      'actor'      => $actor,
      'actor_name' => $name,
      'role'       => $role,
      'result'     => $result,
      'ip'         => $ip,
      'user_agent' => $ua,
    ];
    if ($dk !== null && $dk !== '') { $entry['dk'] = $dk; $entry['dv'] = $dv; }
    Store::appendLine(self::FILE, $entry);

    $sev = $opts['severity'] ?? (in_array($result, ['fail', 'warn'], true) ? 'warning' : 'info');
    @LogShip::send([
      'type'       => $action,
      'result'     => $result,
      'user'       => $actor !== '' ? $actor : '-',
      'ip'         => $ip,
      'user_agent' => $ua,
      'severity'   => $sev,
      'message'    => trim("audit {$action} actor={$actor} {$detail}"),
    ]);
  }

  /** 清除早於保留天數的記錄（與 append 同一把檔案鎖，不遺失同時寫入的新記錄）。回傳是否有刪除。 */
  public static function prune(int $days): bool {
    $cutoff = time() - max(30, $days) * 86400;
    return Store::pruneLines(self::FILE, function (string $ln) use ($cutoff) {
      $e = json_decode($ln, true);
      if (!is_array($e)) return false;
      $ts = (int)($e['ts'] ?? strtotime($e['time'] ?? 'now'));
      return $ts >= $cutoff;
    });
  }

  /**
   * 進階查詢：支援 action / 關鍵字 / 日期範圍 + 分頁（新→舊）。
   * $f: action, q, from(YYYY-MM-DD), to(YYYY-MM-DD)
   * 回傳：['rows'=>[], 'total'=>int, 'page'=>int, 'pages'=>int, 'per'=>int]
   */
  public static function search(array $f = [], int $page = 1, int $per = 50): array {
    $rows = [];
    if (file_exists(self::FILE)) {
      $lines = @file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
      $fromTs = !empty($f['from']) ? strtotime($f['from'] . ' 00:00:00') : null;
      $toTs   = !empty($f['to'])   ? strtotime($f['to'] . ' 23:59:59') : null;
      $q = !empty($f['q']) ? mb_strtolower($f['q']) : '';
      for ($i = count($lines) - 1; $i >= 0; $i--) {
        $e = json_decode($lines[$i], true);
        if (!is_array($e)) continue;
        $ts = $e['ts'] ?? strtotime($e['time'] ?? 'now');
        if (!empty($f['action']) && ($e['action'] ?? '') !== $f['action']) continue;
        if ($fromTs !== null && $ts < $fromTs) continue;
        if ($toTs !== null && $ts > $toTs) continue;
        if ($q !== '') {
          $hay = mb_strtolower(($e['actor'] ?? '') . ' ' . ($e['actor_name'] ?? '') . ' ' . self::detailText($e) . ' ' . ($e['ip'] ?? '') . ' ' . implode(' ', array_map('strval', (array)($e['dv'] ?? []))));   // 錄影 id 不再顯示但仍可搜尋
          if (mb_strpos($hay, $q) === false) continue;
        }
        $rows[] = $e;
      }
    }
    $total = count($rows);
    $per = max(10, min(200, $per));
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($page, $pages));
    return [
      'rows'  => array_slice($rows, ($page - 1) * $per, $per),
      'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per,
    ];
  }

  /** 與 search() 相同的篩選，但回傳「全部符合」的列（不分頁），供匯出用。 */
  public static function searchAll(array $f = []): array {
    $rows = [];
    if (!file_exists(self::FILE)) return $rows;
    $lines = @file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $fromTs = !empty($f['from']) ? strtotime($f['from'] . ' 00:00:00') : null;
    $toTs   = !empty($f['to'])   ? strtotime($f['to'] . ' 23:59:59') : null;
    $q = !empty($f['q']) ? mb_strtolower($f['q']) : '';
    for ($i = count($lines) - 1; $i >= 0; $i--) {
      $e = json_decode($lines[$i], true);
      if (!is_array($e)) continue;
      $ts = $e['ts'] ?? strtotime($e['time'] ?? 'now');
      if (!empty($f['action']) && ($e['action'] ?? '') !== $f['action']) continue;
      if ($fromTs !== null && $ts < $fromTs) continue;
      if ($toTs !== null && $ts > $toTs) continue;
      if ($q !== '') {
        $hay = mb_strtolower(($e['actor'] ?? '') . ' ' . ($e['actor_name'] ?? '') . ' ' . self::detailText($e) . ' ' . ($e['ip'] ?? '') . ' ' . implode(' ', array_map('strval', (array)($e['dv'] ?? []))));   // 錄影 id 不再顯示但仍可搜尋
        if (mb_strpos($hay, $q) === false) continue;
      }
      $rows[] = $e;
    }
    return $rows;
  }

  /** 顯示用詳細內容：有延後翻譯的鍵就依目前（看的人）語言翻譯，否則用寫入時的文字。 */
  public static function detailText(array $e): string {
    if (!empty($e['dk']) && is_string($e['dk'])) return self::render($e['dk'], array_map('strval', (array)($e['dv'] ?? [])));
    return (string)($e['detail'] ?? '');
  }

  /**
   * 錄影類訊息（v1.22.0）：不再把錄影 UUID 寫進說明，改成「{rec}」＝錄影開始時間與長度（例：10/15 21:40 的錄影（46 分））。
   * 舊記錄（v1.16.2–v1.21.0 的鍵是「錄影 {id}」）顯示時對應到新鍵；記錄本身不改。完整 id 仍在 dv，可搜尋、SIEM 也帶。
   */
  private const REC_KEYS = [
    '{what}逐字稿與摘要：會議室「{room}」錄影 {id}' => '{what}逐字稿與摘要：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '取消逐字稿作業：會議室「{room}」錄影 {id}' => '取消逐字稿作業：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '重做會議摘要：會議室「{room}」錄影 {id}' => '重做會議摘要：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '修改逐字稿發言者名稱：會議室「{room}」錄影 {id}' => '修改逐字稿發言者名稱：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '下載逐字稿 / 摘要（{f}）：會議室「{room}」錄影 {id}' => '下載逐字稿 / 摘要（{f}）：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '檢視逐字稿與摘要：會議室「{room}」錄影 {id}' => '檢視逐字稿與摘要：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '下載錄影 {id}' => '下載錄影：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '刪除錄影 {id}' => '刪除錄影：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '自動產生逐字稿：會議室「{room}」錄影 {id}' => '自動產生逐字稿：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '自動重做會議摘要（第 {n} 次）：會議室「{room}」錄影 {id}' => '自動重做會議摘要（第 {n} 次）：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '自動重試辨識（第 {n} 次）：會議室「{room}」錄影 {id}' => '自動重試辨識（第 {n} 次）：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '逐字稿產生失敗：會議室「{room}」錄影 {id}（{code}）' => '逐字稿產生失敗：會議室「{room}」{rec}（{code}）',   // i18n-ignore（比對舊記錄用的鍵）
    '逐字稿作業已取消：會議室「{room}」錄影 {id}' => '逐字稿作業已取消：會議室「{room}」{rec}',   // i18n-ignore（比對舊記錄用的鍵）
    '逐字稿已產生：會議室「{room}」錄影 {id}（{n} 段；摘要：{s}）' => '逐字稿已產生：會議室「{room}」{rec}（{n} 段；摘要：{s}）',   // i18n-ignore（比對舊記錄用的鍵）
    '刪除逐字稿與摘要：會議室「{room}」錄影 {id}（{why}）' => '刪除逐字稿與摘要：會議室「{room}」{rec}（{why}）',   // i18n-ignore（比對舊記錄用的鍵）
  ];

  private static function render(string $dk, array $dv): string {
    $dk = self::REC_KEYS[$dk] ?? $dk;
    if (str_contains($dk, '{rec}')) {
      if (($dv['when'] ?? '') === '' || ($dv['room'] ?? null) === null) {   // 舊記錄：到錄影清單補時間與會議室
        $r = self::recFind((string)($dv['id'] ?? ''));
        if ($r) { $dv += self::rec($r, (string)$dv['id']); $dv['room'] ??= (string)($r['room'] ?? ''); }
      }
      $dv['room'] ??= '—';
      $dv['rec'] = self::recLabel($dv);
    }
    return I18n::t($dk, $dv);
  }

  /** 稽核用的錄影變數：id 與開始時間 / 長度（$r 可以是錄影清單的一筆或逐字稿索引的一筆）。 */
  public static function rec(?array $r, string $id): array {
    $mt = (int)($r['mtime'] ?? $r['rec_mtime'] ?? 0); $dur = (int)($r['duration'] ?? 0);
    if ($mt <= 0) return ['id' => $id];
    return ['id' => $id, 'when' => date('m/d H:i', $mt - max(0, $dur)), 'min' => $dur > 0 ? (string)max(1, (int)round($dur / 60)) : ''];
  }

  private static ?array $recIndex = null;
  /** 舊記錄用：錄影清單裡找這個 id（每個請求只查一次清單；錄影已刪就找不到）。 */
  private static function recFind(string $id): ?array {
    if ($id === '') return null;
    if (self::$recIndex === null) {
      self::$recIndex = [];
      if (!class_exists('Recordings') && is_file(__DIR__ . '/recordings.php')) require_once __DIR__ . '/recordings.php';
      if (class_exists('Recordings') && Settings::hasJibri()) foreach (Recordings::listRecordings() as $r) self::$recIndex[(string)($r['id'] ?? '')] = $r;
    }
    return self::$recIndex[$id] ?? null;
  }
  /** 「10/15 21:40 的錄影（46 分）」；沒有時間（錄影已刪的舊記錄）就寫「錄影（代號 c97c8526）」。 */
  private static function recLabel(array $dv): string {
    $when = (string)($dv['when'] ?? ''); $min = (string)($dv['min'] ?? '');
    if ($when !== '') return $min !== '' ? t('{when} 的錄影（{min} 分）', ['when' => $when, 'min' => $min]) : t('{when} 開始的錄影', ['when' => $when]);
    return t('錄影（代號 {short}）', ['short' => substr((string)($dv['id'] ?? ''), 0, 8)]);
  }

  /** 查詢（新→舊），可選 action / 關鍵字（actor / detail / ip）過濾。 */
  public static function query(int $limit = 300, array $filters = []): array {
    if (!file_exists(self::FILE)) return [];
    $lines = @file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $out = [];
    for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
      $e = json_decode($lines[$i], true);
      if (!is_array($e)) continue;
      if (!empty($filters['action']) && ($e['action'] ?? '') !== $filters['action']) continue;
      if (!empty($filters['q'])) {
        $q = mb_strtolower($filters['q']);
        $hay = mb_strtolower(($e['actor'] ?? '') . ' ' . ($e['actor_name'] ?? '') . ' ' . self::detailText($e) . ' ' . ($e['ip'] ?? '') . ' ' . implode(' ', array_map('strval', (array)($e['dv'] ?? []))));   // 錄影 id 不再顯示但仍可搜尋
        if (mb_strpos($hay, $q) === false) continue;
      }
      $out[] = $e;
    }
    return $out;
  }
}
