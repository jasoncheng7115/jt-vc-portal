<?php
/**
 * 錄影逐字稿與會議摘要（v1.12.0；後端 JTLW）。
 *
 * 流程（照 JTLW 交付的串接說明與 jtdt meeting_transcribe 的做法）：
 *   待處理（pending）→ 從 Jibri 取錄影、串流上傳 JTLW（uploading）→ 送件（queued / running）
 *   → webhook 通知或排程輪詢到終態 → 取回逐字稿（final + raw + speakers 以 seq 對齊）與摘要（JSON + Markdown）
 *   → 寫檔（原子寫入 + fsync）→ 才 ACK 讓 JTLW 刪掉它那份 → done / partial（摘要失敗，逐字稿在）/ failed。
 *
 * 權限：帳號「逐字稿權限」none / manual / auto；建立會議室時可單場開關（覆蓋帳號預設）；管理員可對任何場次手動產生。
 * 資料：transcripts.json（索引，Store::update 加鎖）＋ transcripts/<錄影 id>/{transcript,summary}.json、summary.md、speakers.json。
 * 保留：錄影不在了（保留政策清除 / 刪除）→ 一併刪除本地結果並呼叫 JTLW DELETE。稽核不記逐字稿內容。
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/recordings.php';
require_once __DIR__ . '/jtlw.php';
require_once __DIR__ . '/audit.php';

class Transcripts {
  const INDEX_FILE  = DATA_DIR . '/transcripts.json';
  const EVENTS_FILE = DATA_DIR . '/transcripts-events.json';
  const DIR         = DATA_DIR . '/transcripts';
  const PERMS       = ['none', 'manual', 'auto'];
  const ACTIVE      = ['pending', 'uploading', 'queued', 'running', 'cancelling'];
  const TAIL_GAP_HINT_MS = 60000;           // 錄影尾端超過 60 秒沒有文字：只提示、不判失敗（jtdt 同）
  const MAX_ATTEMPTS = 6;
  const BACKOFF = [60, 120, 300, 600, 1800, 3600];
  const UNREACHABLE_LIMIT = 86400;           // 已送件後連續這麼久查不到 JTLW → 標失敗（不永遠卡在處理中）
  const SUMMARY_RETRY = [600, 1800, 3600];    // 摘要因 LLM 暫時故障失敗 → 10 / 30 / 60 分鐘後自動重做
  const JOB_RETRY = [600, 1800, 3600];        // 辨識失敗但可重試（例如 GPU 伺服器暫時不能用）→ 10 / 30 / 60 分鐘後直接 retry（JTLW v2.25.3 起錄影會保留，不必重傳）

  // ── 權限 ──
  public static function perm(?array $u): string {
    $p = (string)($u['transcribe'] ?? 'none');
    return in_array($p, self::PERMS, true) ? $p : 'none';
  }
  /** 可以使用逐字稿功能（看自己場次的逐字稿、手動產生）：管理員或權限 manual / auto。 */
  public static function canUse(array $me): bool {
    return ($me['role'] ?? '') === 'admin' || self::perm($me) !== 'none';
  }
  public static function canView(array $rec, array $me): bool {
    return self::canUse($me) && Recordings::canAccess($rec, $me);
  }
  public static function canRequest(array $rec, array $me): bool {
    if (($me['role'] ?? '') === 'admin') return true;
    return self::perm($me) !== 'none' && Recordings::canAccess($rec, $me);
  }

  public static function validId(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{6,80}$/', $id); }
  private static function dir(string $id): string { return self::DIR . '/' . $id; }

  // ── 索引 ──
  public static function all(): array {
    $d = Store::read(self::INDEX_FILE, []);
    return is_array($d) ? $d : [];
  }
  public static function get(string $id): ?array { return self::all()[$id] ?? null; }
  /** 加鎖讀改寫：$fn(array &$d) 回 true 才寫回。 */
  private static function mutate(callable $fn): void {
    Store::update(self::INDEX_FILE, function ($cur) use ($fn) {
      $d = is_array($cur) ? $cur : [];
      return $fn($d) ? $d : null;
    }, []);
  }
  private static function patch(string $id, array $fields): void {
    self::mutate(function (array &$d) use ($id, $fields) {
      if (!isset($d[$id])) return false;
      $d[$id] = array_merge($d[$id], $fields, ['updated_at' => time()]);
      return true;
    });
  }

  /** 對應錄影的會議 session（meetings.jsonl），用來帶 hints.meeting 與單場開關。 */
  public static function sessionOf(array $rec): ?array {
    $room = (string)($rec['room'] ?? ''); $end = (int)($rec['mtime'] ?? 0);
    $best = null; $bd = PHP_INT_MAX;
    foreach (Rooms::meetingSessions(0, time() + 86400) as $s) {
      if ((string)($s['room'] ?? '') !== $room) continue;
      $ss = (int)($s['start'] ?? 0); $se = (int)($s['end'] ?? 0);
      if ($end >= $ss - 120 && $end <= $se + 300) { $dd = abs($se - $end); if ($dd < $bd) { $bd = $dd; $best = $s; } }
    }
    return $best;
  }

  /**
   * 錄影完成後要不要自動產生：單場開關優先（true / false），沒設定才看主持人帳號是否為「自動」。
   * 單場開關只對「可使用逐字稿」的主持人（或管理員）有效——不能用房間設定繞過帳號權限。
   */
  public static function autoEligible(array $rec): bool {
    $ownerId = Recordings::ownerOf($rec);
    if ($ownerId === '') return false;
    $owner = Users::find($ownerId);
    if (!$owner || !empty($owner['disabled'])) return false;
    if (!self::canUse($owner)) return false;
    $sess = self::sessionOf($rec);
    $flag = $sess['transcribe'] ?? null;
    if ($flag === null) { $r = Rooms::get((string)($rec['room'] ?? '')); $flag = $r['transcribe'] ?? null; }
    if ($flag !== null) return (bool)$flag;
    return self::perm($owner) === 'auto';
  }

  /** 建立一筆待處理（手動或自動）。已有進行中 / 完成的回 false。 */
  /** 會議主要語言（建立會議室、手動產生時選；值就是送給語音服務的 language）。「自動判斷」只留在系統預設。 */
  public const MEETING_LANGS = ['zh-Hant', 'en', 'ja', 'ko', 'nan-Hant'];
  /** 語音服務的台語專用辨識模式（MediaTek Breeze-ASR-26）；不支援發言者分離。 */
  public const TAIWANESE_PROFILE = 'transcribe.taiwanese';

  /**
   * 選單文字：以「主要使用的語言」描述，不用帶政治意涵的稱呼——
   * 中文不寫國語 / 普通話，台語並列「閩南語」兩種常見稱呼。
   */
  public static function meetingLanguages(): array {
    return ['zh-Hant' => t('中文為主'), 'en' => t('英文為主'), 'ja' => t('日文為主'), 'ko' => t('韓文為主'), 'nan-Hant' => t('台語（閩南語）為主')];
  }

  /** 語言代碼 → 顯示文字（含系統預設才有的「自動判斷」）；空值回「—」。 */
  public static function languageLabel(?string $code): string {
    if ($code === null || $code === '') return '—';
    if ($code === 'auto') return t('自動判斷');
    return self::meetingLanguages()[$code] ?? $code;
  }

  /** 錄影要顯示的主要語言：已送件的用送件時的語言，否則用會議建立時選的。 */
  public static function displayLanguage(array $rec, ?array $e): ?string {
    $l = (string)($e['language'] ?? '');
    return $l !== '' ? $l : self::languageOf($rec);
  }

  /** 這筆錄影的會議當時選的主要語言（沒有就 null）。 */
  public static function languageOf(array $rec): ?string {
    $l = (string)(self::sessionOf($rec)['tx_lang'] ?? '');
    return in_array($l, self::MEETING_LANGS, true) ? $l : null;
  }

  /**
   * 依語言決定送給語音服務的辨識模式與工作項目。台語要用台語專用模式，而它不做發言者分離。
   * （語音服務正在改版台語 API；規格若變，只要改這裡。）
   */
  public static function jobPlan(string $lang, array $cfg): array {
    if ($lang === 'nan-Hant') return ['profile_id' => self::TAIWANESE_PROFILE, 'tasks' => ['transcribe', 'correct'], 'hints' => []];
    // 發言者辨識方法（JTLW api_revision 2.5）：auto＝Nemotron（最多 8 人，超過時 JTLW 自動退回現行方法、不會失敗）；
    // legacy＝不送欄位（與 2.4 以前相同）。hints.num_speakers 刻意不送：與會者人數≠錄影裡發言的人數（JTLW 建議）
    $hints = ($cfg['diarize_engine'] ?? 'auto') === 'auto' ? ['diarize_engine' => 'auto'] : [];
    return ['profile_id' => $cfg['profile_id'], 'tasks' => ['transcribe', 'diarize', 'correct'], 'hints' => $hints];
  }

  /**
   * 依語音服務版本調整 hints：diarize_engine 要 api_revision 2.5 以上；舊版的 hints 不收不認得的欄位（整件 400），
   * 所以版本不夠或查不到版本就不送（等同 legacy）。
   */
  public static function hintsFor(array $hints, string $apiRevision): array {
    if (isset($hints['diarize_engine']) && ($apiRevision === '' || version_compare($apiRevision, '2.5', '<'))) unset($hints['diarize_engine']);
    return $hints;
  }

  private static ?string $apiRevision = null;
  private static function apiRevision(): string {
    if (self::$apiRevision === null) {
      try { self::$apiRevision = (string)(Jtlw::capabilities()['api_revision'] ?? ''); } catch (JtlwError $x) { self::$apiRevision = ''; }
    }
    return self::$apiRevision;
  }

  public static function enqueue(array $rec, string $trigger, ?array $by = null, ?string $language = null): bool {
    $id = (string)($rec['id'] ?? '');
    if (!self::validId($id)) return false;
    $cfg = Settings::getTranscribe();
    // 語言：明確指定 > 會議建立時選的主要語言 > 系統預設
    $lang = in_array($language, Settings::TRANSCRIBE_LANGS, true) ? $language : (self::languageOf($rec) ?? $cfg['language']);
    $ok = false;
    self::mutate(function (array &$d) use ($id, $rec, $trigger, $by, $lang, &$ok) {
      $cur = $d[$id] ?? null;
      if ($cur && in_array($cur['status'] ?? '', array_merge(self::ACTIVE, ['done', 'partial']), true)) return false;
      $d[$id] = [
        'rec_id' => $id, 'room' => (string)($rec['room'] ?? ''), 'file' => (string)($rec['file'] ?? ''),
        'rec_mtime' => (int)($rec['mtime'] ?? 0), 'duration' => (int)($rec['duration'] ?? 0), 'size' => (int)($rec['size'] ?? 0),
        'owner' => Recordings::ownerOf($rec),
        'trigger' => $trigger, 'requested_by' => $by['id'] ?? '', 'requested_by_name' => $by['username'] ?? '',
        'requested_at' => time(), 'language' => $lang,
        'gen' => (int)($cur['gen'] ?? 0) + 1,       // 重新產生時換 Idempotency-Key（upload_id 只能用一次）
        'status' => 'pending', 'attempts' => 0, 'next_try_at' => 0,
        'job_id' => '', 'upload_id' => '', 'error_code' => '', 'error_reason' => '',
        'progress' => null, 'summary_status' => '', 'notify_due' => false, 'updated_at' => time(),
      ];
      $ok = true;
      return true;
    });
    return $ok;
  }

  // ── 背景工作（transcribe-worker.php 每分鐘呼叫；只有一個執行個體）──
  public static function runWorker(?callable $log = null): array {
    $log = $log ?? function ($m) {};
    $stats = ['auto_enqueued' => 0, 'submitted' => 0, 'polled' => 0, 'finished' => 0, 'purged' => 0];
    if (!Settings::transcribeReady()) { $log('transcribe not configured'); return $stats; }
    $recs = [];
    foreach (Recordings::listRecordings() as $r) { if (!empty($r['id'])) $recs[(string)$r['id']] = $r; }
    $listed = Recordings::configured() && (Recordings::ping());

    // 1. 錄影不在了 → 刪除本地結果與 JTLW 端紀錄（保留政策跟著錄影走）
    if ($listed) {
      foreach (self::all() as $id => $e) {
        if (isset($recs[$id])) continue;
        if (in_array($e['status'] ?? '', ['uploading'], true)) continue;
        self::purge((string)$id, 'recording_gone');
        $stats['purged']++;
      }
    }

    // 2. 自動產生：錄影完成（status ok）、在啟用自動之後錄的、符合權限
    $since = (int)(Settings::getSection('transcribe')['auto_since'] ?? 0);
    if ($since > 0) {
      $index = self::all();
      foreach ($recs as $id => $r) {
        if (($r['status'] ?? '') !== 'ok' || isset($index[$id])) continue;
        if ((int)($r['mtime'] ?? 0) < $since) continue;
        if (self::autoEligible($r) && self::enqueue($r, 'auto')) {
          $stats['auto_enqueued']++;
          Audit::log('transcript_request', tk('自動產生逐字稿：會議室「{room}」錄影 {id}', ['room' => $r['room'] ?? '', 'id' => $id]), ['actor' => 'system']);
        }
      }
    }

    // 3. 送件：一次只上傳一件（JTLW 要求一件一件來）
    foreach (self::all() as $id => $e) {
      if (($e['status'] ?? '') !== 'pending' || (int)($e['next_try_at'] ?? 0) > time()) continue;
      if (!isset($recs[$id])) { self::fail($id, 'recording_missing', false); continue; }
      self::submit((string)$id, $recs[$id], $log);
      $stats['submitted']++;
      break;
    }

    // 4. 摘要自動重做（排定時間到了、還沒 ACK）
    foreach (self::all() as $id => $e) {
      if (($e['status'] ?? '') !== 'partial' || empty($e['summary_retry_at']) || (int)$e['summary_retry_at'] > time()) continue;
      $n = (int)($e['summary_retries'] ?? 0) + 1;
      self::patch((string)$id, ['summary_retries' => $n, 'summary_retry_at' => 0]);
      if (self::retrySummary((string)$id)) Audit::log('transcript_request', tk('自動重做會議摘要（第 {n} 次）：會議室「{room}」錄影 {id}', ['n' => $n, 'room' => $e['room'] ?? '', 'id' => $id]), ['actor' => 'system']);
    }

    // 4b. 辨識失敗但可重試：時間到了直接 retry（錄影還在 JTLW，不重傳）；用完次數就刪掉 JTLW 端作業
    foreach (self::all() as $id => $e) {
      if (($e['status'] ?? '') !== 'failed' || empty($e['job_retry_at']) || (int)$e['job_retry_at'] > time() || empty($e['job_id'])) continue;
      $n = (int)($e['job_retries'] ?? 0) + 1;
      self::patch((string)$id, ['job_retries' => $n, 'job_retry_at' => 0]);
      try {
        Jtlw::retry((string)$e['job_id']);
        self::patch((string)$id, ['status' => 'queued', 'error_code' => '', 'error_reason' => '', 'progress' => null, 'last_ok_poll_at' => time()]);
        Audit::log('transcript_request', tk('自動重試辨識（第 {n} 次）：會議室「{room}」錄影 {id}', ['n' => $n, 'room' => $e['room'] ?? '', 'id' => $id]), ['actor' => 'system']);
      } catch (JtlwError $x) {
        if ($x->retryable() && $n < count(self::JOB_RETRY)) self::patch((string)$id, ['job_retry_at' => time() + self::JOB_RETRY[$n]]);
        else self::dropJob((string)$id);
      }
    }

    // 5. 查詢進行中的作業；到終態就取回
    foreach (self::all() as $id => $e) {
      if (!in_array($e['status'] ?? '', ['queued', 'running', 'cancelling'], true) || empty($e['job_id'])) continue;
      $stats['polled']++;
      if (self::poll((string)$id)) $stats['finished']++;
    }
    return $stats;
  }

  private static function fail(string $id, string $code, bool $retryable, string $reason = ''): void {
    $e = self::get($id); if (!$e) return;
    $attempts = (int)($e['attempts'] ?? 0) + 1;
    if ($retryable && $attempts < self::MAX_ATTEMPTS) {
      self::patch($id, ['status' => 'pending', 'attempts' => $attempts, 'error_code' => $code, 'error_reason' => $reason,
                        'next_try_at' => time() + self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)]]);
      return;
    }
    self::patch($id, ['status' => 'failed', 'attempts' => $attempts, 'error_code' => $code, 'error_reason' => $reason]);
    Audit::log('transcript_failed', tk('逐字稿產生失敗：會議室「{room}」錄影 {id}（{code}）', ['room' => $e['room'] ?? '', 'id' => $id, 'code' => $code]), ['actor' => 'system', 'result' => 'fail']);
  }

  /** 取錄影 → 串流上傳 → 送件。 */
  public static function submit(string $id, array $rec, ?callable $log = null): void {
    $e = self::get($id); if (!$e) return;
    $cfg = Settings::getTranscribe();
    self::patch($id, ['status' => 'uploading', 'error_code' => '', 'error_reason' => '']);
    @mkdir(DATA_DIR . '/tmp', 0750, true);
    $tmp = DATA_DIR . '/tmp/rec-' . $id . '.bin';
    try {
      if (!Recordings::downloadTo($id, $tmp)) throw new JtlwError(0, ['code' => 'recording_missing', 'retryable' => true]);
      $up = Jtlw::upload($tmp, (string)($rec['file'] ?? ($id . '.mp4')));
      @unlink($tmp);
      $lang = (string)($e['language'] ?? $cfg['language']);
      $plan = self::jobPlan($lang, $cfg);
      $tasks = $plan['tasks'];
      $summary = $cfg['summarize'] && in_array($lang, ['zh-Hant', 'en', 'nan-Hant', 'auto'], true);   // 語音服務的摘要不支援日文、韓文
      if ($summary) $tasks[] = 'summarize';
      $body = [
        'profile_id' => $plan['profile_id'],
        'tasks' => $tasks,
        'source' => ['type' => 'upload', 'upload_id' => (string)$up['upload_id']],
        'language' => $lang,
        'external_ref' => ['system' => 'jtvc', 'job_id' => $id],
      ];
      // 測試用：JTLW mock 以 external_ref.mock_scenario 切換情境（只有測試容器會設這個環境變數）
      $sc = (string)getenv('JTVC_JTLW_MOCK_SCENARIO');
      if ($sc !== '' && preg_match('/^[a-z_]{1,32}$/', $sc)) $body['external_ref']['mock_scenario'] = $sc;
      $hints = $plan['hints'] ? self::hintsFor($plan['hints'], self::apiRevision()) : [];
      $meet = self::meetingHints($rec);
      if ($meet) $hints['meeting'] = $meet;
      if ($hints) $body['hints'] = $hints;
      if ($cfg['webhook_endpoint_id'] !== '') $body['webhook'] = ['endpoint_id' => $cfg['webhook_endpoint_id']];
      $job = Jtlw::createJob($body, 'jtvc-rec-' . $id . '-' . (int)($e['gen'] ?? 1));
      self::patch($id, ['status' => (string)($job['status'] ?? 'queued') === 'running' ? 'running' : 'queued',
                        'job_id' => (string)$job['job_id'], 'upload_id' => (string)$up['upload_id'],
                        'summary_requested' => $summary, 'submitted_at' => time(), 'progress' => self::progressOf($job)]);
      if ($log) $log("submitted $id -> " . $job['job_id']);
    } catch (JtlwError $ex) {
      @unlink($tmp);
      self::fail($id, $ex->errCode(), $ex->retryable(), $ex->reason());
      if ($log) $log("submit $id failed: " . $ex->errCode());
    }
  }

  /** hints.meeting：只幫助讀懂逐字稿（人名、稱呼），不會變成摘要項目；沒有標題就不放 title。 */
  public static function meetingHints(array $rec): array {
    $s = self::sessionOf($rec);
    $h = ['room' => (string)($rec['room'] ?? '')];
    if ($s) {
      $owner = Users::find((string)($s['owner'] ?? ''));
      $host = trim((string)($owner['display_name'] ?? '')) ?: (string)($s['owner_name'] ?? '');
      if ($host !== '') $h['host'] = mb_substr($host, 0, 100);
      if (!empty($s['start'])) $h['started_at'] = gmdate('Y-m-d\TH:i:s\Z', (int)$s['start']);
      if (!empty($s['end']))   $h['ended_at']   = gmdate('Y-m-d\TH:i:s\Z', (int)$s['end']);
      $ps = [];
      foreach ((array)($s['participants'] ?? []) as $p) {
        $name = trim((string)($p['name'] ?? '')); if ($name === '') continue;
        $x = ['name' => mb_substr($name, 0, 100)];
        if (!empty($p['in']))  $x['joined_at'] = gmdate('Y-m-d\TH:i:s\Z', (int)$p['in']);
        if (!empty($p['out'])) $x['left_at']   = gmdate('Y-m-d\TH:i:s\Z', (int)$p['out']);
        $ps[] = $x;
        if (count($ps) >= 200) break;
      }
      if ($ps) $h['participants'] = $ps;
    }
    return $h;
  }

  private static function progressOf(array $job): array {
    $p = (array)($job['progress'] ?? []);
    return [
      'status' => (string)($job['status'] ?? ''),
      'stage' => (string)($p['stage'] ?? ''), 'percent' => isset($p['percent']) ? (int)$p['percent'] : null,
      'detail' => mb_substr((string)($p['detail'] ?? ''), 0, 60),
      'processed_ms' => isset($p['processed_audio_ms']) ? (int)$p['processed_audio_ms'] : null,
      'total_ms' => isset($p['total_audio_ms']) ? (int)$p['total_audio_ms'] : null,
      'queue_position' => isset($job['queue_position']) ? (int)$job['queue_position'] : null,
      'waiting' => !empty($p['waiting']) ? (int)($p['waiting']['ahead'] ?? 0) : null,
    ];
  }

  /** 查一次；到終態就取回結果。回 true＝已結束。 */
  public static function poll(string $id): bool {
    $e = self::get($id); if (!$e || empty($e['job_id'])) return false;
    try { $job = Jtlw::getJob((string)$e['job_id']); }
    catch (JtlwError $ex) {
      if ($ex->status === 404) { self::fail($id, 'job_missing', false); return true; }
      // 連不上：下一輪再查（撐過 JTLW 重啟）；但連續 24 小時都查不到就停止等候，讓使用者重新產生
      $since = (int)($e['last_ok_poll_at'] ?? $e['submitted_at'] ?? time());
      if (time() - $since > self::UNREACHABLE_LIMIT) { self::patch($id, ['attempts' => self::MAX_ATTEMPTS]); self::fail($id, 'jtlw_unreachable', false); return true; }
      return false;
    }
    self::patch($id, ['last_ok_poll_at' => time()]);
    $st = (string)($job['status'] ?? '');
    if (in_array($st, ['queued', 'running', 'cancelling'], true)) {
      self::patch($id, ['status' => $st, 'progress' => self::progressOf($job), 'notify_due' => false]);
      return false;
    }
    if ($st === 'cancelled') {
      self::patch($id, ['status' => 'cancelled', 'progress' => null, 'notify_due' => false]);
      Audit::log('transcript_cancel', tk('逐字稿作業已取消：會議室「{room}」錄影 {id}', ['room' => $e['room'] ?? '', 'id' => $id]), ['actor' => 'system']);
      return true;
    }
    if ($st === 'failed') {
      $err = (array)($job['errors'][0] ?? []);
      $code = (string)($err['code'] ?? 'failed');
      self::patch($id, ['attempts' => self::MAX_ATTEMPTS]);          // 不重新上傳；可重試的由下面排定直接 retry
      self::fail($id, $code, false);
      $n = (int)($e['job_retries'] ?? 0);
      if (!empty($err['retryable']) && $n < count(self::JOB_RETRY)) {
        // JTLW v2.25.3：可重試的辨識失敗會保留錄影 → 排定時間到了直接 POST /retry
        self::patch($id, ['job_retry_at' => time() + self::JOB_RETRY[$n]]);
      } else {
        self::dropJob($id);                                          // 不再重試：刪掉 JTLW 端的作業（連同保留的錄影）
      }
      return true;
    }
    if (in_array($st, ['succeeded', 'partially_succeeded'], true)) return self::fetch($id, $job);
    return false;
  }

  /** 取回結果、存檔（fsync）、才 ACK。 */
  public static function fetch(string $id, array $job): bool {
    $e = self::get($id); if (!$e) return false;
    $jid = (string)$e['job_id'];
    try {
      $final = Jtlw::segments($jid, 'final');
      $raw = Jtlw::segments($jid, 'raw');
      $spk = [];
      // 只有做了發言者分離才取 speakers 層：台語模式沒有 diarize，去要會得到 400（不是 task_not_requested），
      // 若當成暫時錯誤就會一直重試、永遠停在「處理中」
      if (array_key_exists('diarize', (array)($job['tasks'] ?? []))) {
        try { $spk = Jtlw::segments($jid, 'speakers'); } catch (JtlwError $x) { if ($x->errCode() !== 'task_not_requested') throw $x; }
      }
    } catch (JtlwError $ex) {
      return false;                                    // 暫時取不到：下一輪再試（內容在 ACK 前不會被刪）
    }
    $tr = self::mergeSegments($raw, $final, $spk);
    if (!$tr['segments']) {                            // 0 段視為失敗（jtdt 教訓：曾有「成功但 0 段」；也可能是錄影裡沒有人說話）
      try { Jtlw::delete($jid); } catch (JtlwError $x) {}   // 沒有內容可取回：JTLW 端紀錄直接刪除，不留 7 天
      self::fail($id, 'empty_transcript', false);
      return true;
    }
    $durMs = max((int)($e['duration'] ?? 0) * 1000, (int)($job['result']['duration_ms'] ?? 0));
    $lastEnd = 0; foreach ($tr['segments'] as $s) $lastEnd = max($lastEnd, (int)($s['end_ms'] ?? 0));
    $tr['duration_ms'] = $durMs;
    $tr['tail_gap_ms'] = $durMs > 0 ? max(0, $durMs - $lastEnd) : 0;
    $tr['tail_hint'] = $tr['tail_gap_ms'] > self::TAIL_GAP_HINT_MS;
    $tr['diarize_skipped'] = !$spk;
    // 實際用了哪個發言者辨識方法（Result.diarization，api 2.5）：只記方法，不影響取回；取不到就算了
    if ($spk) {
      try { $res = Jtlw::getResult($jid); $dz = $res['diarization'] ?? null;
        if (is_array($dz)) $tr['diarization'] = ['requested' => (string)($dz['requested'] ?? ''), 'engine' => (string)($dz['engine'] ?? ''), 'fallback' => ($dz['requested'] ?? '') === 'auto' && ($dz['engine'] ?? '') === 'legacy',
                                                  'reason' => preg_match('/^[a-z_]{1,40}$/', (string)($dz['reason'] ?? '')) ? (string)$dz['reason'] : ''];   // api 2.6：退回原因代碼（note 是中文，不顯示）
      } catch (JtlwError $x) {}
    }
    $tr['job_id'] = $jid; $tr['language'] = (string)($e['language'] ?? '');
    $tr['generated_at'] = time();

    // 摘要：tasks.summarize ＝ succeeded / failed / skipped…；失敗原因在 errors[] 中 task=summarize 那筆
    $summaryStatus = 'not_requested'; $summary = null; $md = null; $sumErr = '';
    if (!empty($e['summary_requested'])) {
      $task = (string)($job['tasks']['summarize'] ?? '');
      foreach ((array)($job['errors'] ?? []) as $er) { if (($er['task'] ?? '') === 'summarize') { $sumErr = (string)($er['code'] ?? ''); break; } }
      if (in_array($task, ['pending', 'running'], true)) return false;           // 摘要還在做（逐字稿可能已好）：下一輪再取
      try { $summary = Jtlw::getSummary($jid); $md = Jtlw::getSummaryMarkdown($jid); $summaryStatus = 'ok'; }
      catch (JtlwError $ex) {
        if ($ex->errCode() === 'summary_not_ready') return false;
        if ($ex->errCode() === 'network_error' || $ex->status >= 500) return false;
        $summaryStatus = $sumErr === 'language_not_supported' ? 'unsupported' : 'failed';
        if ($sumErr === '') $sumErr = $ex->reason() ?: $ex->errCode();
      }
    }
    $dir = self::dir($id); @mkdir($dir, 0750, true);
    self::writeFile("$dir/transcript.json", json_encode($tr, JSON_UNESCAPED_UNICODE));
    if ($summary !== null) self::writeFile("$dir/summary.json", json_encode($summary, JSON_UNESCAPED_UNICODE));
    if ($md !== null) self::writeFile("$dir/summary.md", $md);
    $status = ($summaryStatus === 'failed') ? 'partial' : 'done';
    // LLM 暫時故障（可重試）→ 排定自動重做摘要（10 / 30 / 60 分鐘後），都失敗才停在「摘要失敗」等人處理
    $n = (int)($e['summary_retries'] ?? 0); $retryAt = 0;
    if ($status === 'partial' && in_array($sumErr, ['llm_unavailable', 'llm_failed', 'summarize_failed', ''], true) && $n < count(self::SUMMARY_RETRY)) $retryAt = time() + self::SUMMARY_RETRY[$n];
    self::patch($id, ['status' => $status, 'summary_status' => $summaryStatus, 'summary_error' => $sumErr,
                      'progress' => null, 'done_at' => time(), 'notify_due' => false,
                      'segments' => count($tr['segments']), 'uncorrected' => $tr['uncorrected'], 'summary_retry_at' => $retryAt]);
    // 摘要可重做（retry）時先不 ACK——ACK 之後 JTLW 就沒有逐字稿可以重做摘要
    if ($status === 'done') { try { Jtlw::ack($jid); self::patch($id, ['acked' => true]); } catch (JtlwError $x) {} }
    Audit::log('transcript_done', tk('逐字稿已產生：會議室「{room}」錄影 {id}（{n} 段；摘要：{s}）', ['room' => $e['room'] ?? '', 'id' => $id, 'n' => count($tr['segments']), 's' => $summaryStatus]), ['actor' => 'system']);
    return true;
  }

  /** 原子寫入 + fsync（ACK 前必須確定落地）。 */
  private static function writeFile(string $path, string $data): void {
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $fh = fopen($tmp, 'wb'); fwrite($fh, $data); fflush($fh); if (function_exists('fsync')) fsync($fh); fclose($fh);
    @chmod($tmp, 0640);
    rename($tmp, $path);
  }

  /** 三層以 seq 對齊：時間取 raw、文字優先 final（缺的用 raw）、發言者取 speakers。 */
  public static function mergeSegments(array $raw, array $final, array $spk): array {
    $f = []; foreach ($final as $s) $f[(int)$s['seq']] = (string)($s['text'] ?? '');
    $sp = []; foreach ($spk as $s) $sp[(int)$s['seq']] = (string)($s['speaker_id'] ?? '');
    $out = []; $usedRaw = 0;
    foreach ($raw as $s) {
      $seq = (int)$s['seq'];
      $text = $f[$seq] ?? null;
      if ($text === null || $text === '') { $text = (string)($s['text'] ?? ''); $usedRaw++; }
      if (trim($text) === '') continue;
      $out[] = ['seq' => $seq, 'start_ms' => isset($s['start_ms']) ? (int)$s['start_ms'] : null,
                'end_ms' => isset($s['end_ms']) ? (int)$s['end_ms'] : null, 'speaker' => $sp[$seq] ?? '', 'text' => $text];
    }
    return ['segments' => $out, 'uncorrected' => $out && !$final];
  }

  // ── 發言者建議（v1.13.0）：Jitsi 主要發言者時間軸 × 逐字稿發言者代號 ──
  /**
   * 回傳 ['offset_ms' => 採用的時間偏移, 'speakers' => ['S1' => ['coverage' => 0–100, 'names' => [['name','pct']...]], ...]]。
   * 錄影開始時間 = 錄影檔時間 − 長度（誤差數秒），所以在 ±10 秒內找「每個代號最主要的人」重疊總和最大的偏移。
   * 只給建議：覆蓋不足（<20% 或 <3 秒）不建議；每個代號最多列 3 人、比例 ≥10%。
   */
  public static function suggestSpeakers(array $segments, array $talk, int $recStartMs): array {
    $segs = [];
    foreach ($segments as $x) {
      if (($x['speaker'] ?? '') === '' || !isset($x['start_ms'], $x['end_ms']) || $x['end_ms'] <= $x['start_ms']) continue;
      $segs[] = [(string)$x['speaker'], (int)$x['start_ms'], (int)$x['end_ms']];
    }
    $iv = [];
    foreach ($talk as $t) { if (isset($t['n'], $t['s'], $t['e']) && $t['e'] > $t['s']) $iv[] = [(string)$t['n'], (int)$t['s'], (int)$t['e']]; }
    if (!$segs || !$iv) return ['offset_ms' => 0, 'speakers' => (object)[]];
    usort($segs, fn($a, $b) => $a[1] <=> $b[1]);
    usort($iv, fn($a, $b) => $a[1] <=> $b[1]);
    $speech = []; foreach ($segs as [$sp, $a, $b]) $speech[$sp] = ($speech[$sp] ?? 0) + ($b - $a);
    $overlap = function (int $off) use ($segs, $iv, $recStartMs): array {
      $acc = []; $j = 0; $m = count($iv);
      foreach ($segs as [$sp, $a, $b]) {
        $a += $recStartMs + $off; $b += $recStartMs + $off;
        while ($j < $m && $iv[$j][2] <= $a) $j++;
        for ($k = $j; $k < $m && $iv[$k][1] < $b; $k++) {
          $o = min($b, $iv[$k][2]) - max($a, $iv[$k][1]);
          if ($o > 0) $acc[$sp][$iv[$k][0]] = ($acc[$sp][$iv[$k][0]] ?? 0) + $o;
        }
      }
      return $acc;
    };
    $best = 0; $bestScore = -1; $bestAcc = [];
    for ($off = -10000; $off <= 10000; $off += 1000) {
      $acc = $overlap($off); $score = 0;
      foreach ($acc as $names) $score += max($names);
      if ($score > $bestScore || ($score === $bestScore && abs($off) < abs($best))) { $bestScore = $score; $best = $off; $bestAcc = $acc; }
    }
    $out = [];
    foreach ($bestAcc as $sp => $names) {
      $tot = array_sum($names);
      $cov = $speech[$sp] > 0 ? $tot / $speech[$sp] : 0;
      if ($tot < 3000 || $cov < 0.2) continue;
      arsort($names);
      $list = [];
      foreach ($names as $n => $ms) { $pct = (int)round(100 * $ms / $tot); if ($pct < 10) break; $list[] = ['name' => $n, 'pct' => $pct]; if (count($list) >= 3) break; }
      if ($list) $out[$sp] = ['coverage' => (int)round(100 * min(1, $cov)), 'names' => $list];
    }
    ksort($out, SORT_NATURAL);
    return ['offset_ms' => $best, 'speakers' => (object)$out];
  }

  /** 這場會議的參與者名稱（去重、保持出現順序），改名下拉選單用。 */
  public static function participantNames(?array $sess): array {
    $names = [];
    foreach ((array)($sess['participants'] ?? []) as $p) { $n = trim((string)($p['name'] ?? '')); if ($n !== '' && !in_array($n, $names, true)) $names[] = $n; }
    foreach ((array)($sess['talk'] ?? []) as $t) { $n = trim((string)($t['n'] ?? '')); if ($n !== '' && !in_array($n, $names, true)) $names[] = $n; }
    return array_slice($names, 0, 200);
  }

  // ── 讀取結果（頁面用）──
  public static function result(string $id): ?array {
    $p = self::dir($id) . '/transcript.json';
    if (!is_file($p)) return null;
    $t = json_decode((string)file_get_contents($p), true);
    if (!is_array($t)) return null;
    $sp = json_decode((string)@file_get_contents(self::dir($id) . '/speakers.json'), true);
    $t['speaker_names'] = (array)($sp['map'] ?? []);
    $t['speaker_overrides'] = (array)($sp['overrides'] ?? []);
    return $t;
  }
  public static function summary(string $id): ?array {
    $p = self::dir($id) . '/summary.json';
    $s = is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
    return is_array($s) ? $s : null;
  }
  public static function summaryMarkdown(string $id): ?string {
    $p = self::dir($id) . '/summary.md';
    return is_file($p) ? (string)file_get_contents($p) : null;
  }

  /** 發言者改名：map（整個代號）與 overrides（單段）。名稱去控制字元、上限 40 字（jtdt 同）。 */
  public static function saveSpeakers(string $id, array $map, array $overrides): void {
    $clean = function ($v) { return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$v)), 0, 40); };
    $m = []; foreach ($map as $k => $v) { if (preg_match('/^S\d{1,3}$/', (string)$k) && ($n = $clean($v)) !== '') $m[(string)$k] = $n; }
    $o = []; foreach ($overrides as $k => $v) { if (ctype_digit((string)$k) && ($n = $clean($v)) !== '') $o[(string)$k] = $n; }
    if (count($m) > 50) $m = array_slice($m, 0, 50, true);
    if (count($o) > 2000) $o = array_slice($o, 0, 2000, true);
    $dir = self::dir($id); if (!is_dir($dir)) return;
    self::writeFile("$dir/speakers.json", json_encode(['map' => (object)$m, 'overrides' => (object)$o], JSON_UNESCAPED_UNICODE));
  }

  // ── 動作 ──
  public static function cancel(string $id): bool {
    $e = self::get($id); if (!$e) return false;
    if (($e['status'] ?? '') === 'pending') { self::patch($id, ['status' => 'cancelled']); return true; }
    if (!in_array($e['status'] ?? '', ['queued', 'running'], true) || empty($e['job_id'])) return false;
    try { Jtlw::cancel((string)$e['job_id']); } catch (JtlwError $x) { return false; }
    self::patch($id, ['status' => 'cancelling']);
    return true;
  }

  /** 只重做摘要（partial，JTLW 尚未 ACK 時）。 */
  public static function retrySummary(string $id): bool {
    $e = self::get($id); if (!$e || ($e['status'] ?? '') !== 'partial' || empty($e['job_id']) || !empty($e['acked'])) return false;
    try { Jtlw::retry((string)$e['job_id']); } catch (JtlwError $x) { return false; }
    self::patch($id, ['status' => 'running', 'summary_status' => '', 'progress' => null]);
    return true;
  }

  /** 不再重試的失敗作業：刪掉 JTLW 端紀錄（它可能還保留著上傳的錄影），本地狀態留著給使用者看。 */
  public static function dropJob(string $id): void {
    $e = self::get($id); if (!$e || empty($e['job_id'])) return;
    try { Jtlw::delete((string)$e['job_id']); } catch (JtlwError $x) {}
    self::patch($id, ['job_retry_at' => 0, 'job_dropped' => true]);
  }

  /** 刪除本地結果＋JTLW 端紀錄（管理員刪除、或錄影不在了）。 */
  public static function purge(string $id, string $why = ''): void {
    $e = self::get($id);
    if ($e && !empty($e['job_id'])) {
      try {
        if (in_array($e['status'] ?? '', ['queued', 'running'], true)) Jtlw::cancel((string)$e['job_id']);
        Jtlw::delete((string)$e['job_id']);
      } catch (JtlwError $x) {}
    }
    $dir = self::dir($id);
    if (is_dir($dir)) { foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
    self::mutate(function (array &$d) use ($id) { if (!isset($d[$id])) return false; unset($d[$id]); return true; });
    if ($e) Audit::log('transcript_delete', tk('刪除逐字稿與摘要：會議室「{room}」錄影 {id}（{why}）', ['room' => $e['room'] ?? '', 'id' => $id, 'why' => $why]), ['actor' => $why === 'recording_gone' ? 'system' : null]);
  }

  // ── webhook ──
  /** 驗簽後呼叫：去重（event_id），終態事件標記「待取回」。回 true＝已處理（或重複）。 */
  public static function handleEvent(array $ev): bool {
    $eid = (string)($ev['event_id'] ?? '');
    if ($eid === '') return false;
    $dup = false;
    Store::update(self::EVENTS_FILE, function ($cur) use ($eid, &$dup) {
      $d = is_array($cur) ? array_values($cur) : [];
      if (in_array($eid, $d, true)) { $dup = true; return null; }
      $d[] = $eid; if (count($d) > 2000) $d = array_slice($d, -2000);
      return $d;
    }, []);
    if ($dup) return true;
    $type = (string)($ev['type'] ?? '');
    if (!in_array($type, ['job.succeeded', 'job.partially_succeeded', 'job.failed', 'job.cancelled'], true)) return true;
    $ref = (array)($ev['external_ref'] ?? []);
    $id = (string)($ref['job_id'] ?? '');
    if (($ref['system'] ?? '') !== 'jtvc' || !self::validId($id)) return true;
    $e = self::get($id);
    if (!$e || (string)$e['job_id'] !== (string)($ev['job_id'] ?? '')) return true;   // 只接受自己送出的那件
    self::patch($id, ['notify_due' => true]);
    return true;
  }
}
