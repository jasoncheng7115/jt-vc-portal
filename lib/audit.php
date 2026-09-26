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
  public static function log(string $action, string $detail = '', array $opts = []): void {
    Auth::start();
    $actor = $opts['actor']      ?? ($_SESSION['username'] ?? '');
    $name  = $opts['actor_name'] ?? ($_SESSION['display_name'] ?? '');
    $role  = $opts['role']       ?? ($_SESSION['role'] ?? '');
    $result = $opts['result']    ?? 'ok';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    // 長度上限：避免攻擊者以超長帳號字串 / 詳情灌爆記錄檔
    $actor  = mb_substr((string)$actor, 0, 128);
    $name   = mb_substr((string)$name, 0, 128);
    $detail = mb_substr($detail, 0, 1000);
    $ip = Auth::clientIp();

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
          $hay = mb_strtolower(($e['actor'] ?? '') . ' ' . ($e['actor_name'] ?? '') . ' ' . ($e['detail'] ?? '') . ' ' . ($e['ip'] ?? ''));
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
        $hay = mb_strtolower(($e['actor'] ?? '') . ' ' . ($e['actor_name'] ?? '') . ' ' . ($e['detail'] ?? '') . ' ' . ($e['ip'] ?? ''));
        if (mb_strpos($hay, $q) === false) continue;
      }
      $rows[] = $e;
    }
    return $rows;
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
        $hay = mb_strtolower(($e['actor'] ?? '') . ' ' . ($e['actor_name'] ?? '') . ' ' . ($e['detail'] ?? '') . ' ' . ($e['ip'] ?? ''));
        if (mb_strpos($hay, $q) === false) continue;
      }
      $out[] = $e;
    }
    return $out;
  }
}
