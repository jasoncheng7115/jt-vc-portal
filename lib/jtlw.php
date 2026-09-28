<?php
/**
 * jt-live-whisper（JTLW）REST API 用戶端（api_revision 2.4）。
 * 行為照 JTLW 交付的參考用戶端（docs-share/jtlw/examples/jtlw_client.php）：
 *   串流上傳錄影 → 送件（辨識＋講者＋校正＋會議摘要）→ 查詢 / webhook → 取摘要與逐字稿 → ACK。
 * 金鑰只放 settings.json（頁面不回填）；TLS 一律驗證，自簽憑證以設定頁貼上的 PEM 信任（CURLOPT_CAINFO_BLOB）。
 */
require_once __DIR__ . '/settings.php';

class JtlwError extends RuntimeException {
  public function __construct(public readonly int $status, public readonly array $error) {
    parent::__construct((string)($error['code'] ?? ('http_' . $status)), $status);
  }
  public function errCode(): string { return (string)($this->error['code'] ?? ''); }
  public function retryable(): bool { return (bool)($this->error['retryable'] ?? false); }
  public function retryAfterMs(): ?int { return isset($this->error['retry_after_ms']) ? (int)$this->error['retry_after_ms'] : null; }
  public function reason(): string { return (string)($this->error['details']['reason'] ?? ''); }
}

class Jtlw {
  /** 設定頁 / 測試可覆寫（null＝讀 settings）。 */
  public static ?array $cfgOverride = null;

  private static function cfg(): array { return self::$cfgOverride ?? Settings::getTranscribe(); }

  public static function base(): string { return self::cfg()['jtlw_url'] . '/api/v1'; }

  /** 共用請求。$raw=true 回傳字串；204 回 ''。錯誤丟 JtlwError（連不上＝network_error、retryable）。 */
  private static function send(string $method, string $path, $json = null, array $query = [], array $curlOpts = [],
                               array $headers = [], bool $raw = false, int $timeout = 60) {
    $c = self::cfg();
    if ($c['jtlw_url'] === '' || $c['jtlw_key'] === '') throw new JtlwError(0, ['code' => 'not_configured', 'retryable' => false]);
    $url = self::base() . $path . ($query ? (str_contains($path, '?') ? '&' : '?') . http_build_query($query) : '');
    $ch = curl_init($url);
    $h = array_merge(['Authorization: Bearer ' . $c['jtlw_key'], 'Accept: application/json'], $headers);
    $opts = [
      CURLOPT_CUSTOMREQUEST  => $method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ];
    if ($c['jtlw_ca'] !== '' && defined('CURLOPT_CAINFO_BLOB')) $opts[CURLOPT_CAINFO_BLOB] = $c['jtlw_ca'];
    if ($json !== null) {
      $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $h[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $curlOpts + $opts);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new JtlwError(0, ['code' => 'network_error', 'message' => $err, 'retryable' => true]);
    if ($status >= 400) {
      $e = json_decode((string)$body, true)['error'] ?? null;
      if (!is_array($e)) $e = ['code' => 'http_' . $status, 'retryable' => $status >= 500 || $status === 429];
      throw new JtlwError($status, $e);
    }
    if ($raw || $status === 204) return (string)$body;
    $d = json_decode((string)$body, true);
    if (!is_array($d)) throw new JtlwError($status, ['code' => 'bad_response', 'retryable' => true]);
    return $d;
  }

  // ── 資訊 ──
  public static function capabilities(): array { return self::send('GET', '/capabilities', null, [], [], [], false, 15); }
  public static function profiles(): array { return self::send('GET', '/profiles', null, [], [], [], false, 15); }

  // ── 上傳（串流，大檔不佔記憶體）──
  public static function upload(string $path, string $filename): array {
    $size = @filesize($path); $fh = @fopen($path, 'rb');
    if ($fh === false || $size === false) throw new JtlwError(0, ['code' => 'local_file_missing', 'retryable' => false]);
    try {
      return self::send('POST', '/uploads?filename=' . rawurlencode($filename), null, [], [
        CURLOPT_UPLOAD          => true,
        CURLOPT_CUSTOMREQUEST   => 'POST',
        CURLOPT_INFILE          => $fh,
        CURLOPT_INFILESIZE      => $size,
        CURLOPT_TIMEOUT         => 0,
        CURLOPT_LOW_SPEED_LIMIT => 1024,
        CURLOPT_LOW_SPEED_TIME  => 60,
      ], ['Content-Type: application/octet-stream', 'Expect:']);
    } finally { fclose($fh); }
  }

  // ── 作業 ──
  public static function createJob(array $body, string $idempotencyKey): array {
    return self::send('POST', '/jobs', $body, [], [], ['Idempotency-Key: ' . $idempotencyKey]);
  }
  public static function getJob(string $id): array { return self::send('GET', '/jobs/' . rawurlencode($id), null, [], [], [], false, 20); }
  public static function getSummary(string $id): array { return self::send('GET', '/jobs/' . rawurlencode($id) . '/summary'); }
  public static function getSummaryMarkdown(string $id): string { return self::send('GET', '/jobs/' . rawurlencode($id) . '/summary.md', null, [], [], [], true); }
  /** 逐字稿的一層（raw / final / speakers），自動翻頁拉完。 */
  public static function segments(string $id, string $layer): array {
    $all = []; $after = 0; $guard = 0;
    do {
      $page = self::send('GET', '/jobs/' . rawurlencode($id) . '/segments', null, ['layer' => $layer, 'after_seq' => $after, 'limit' => 1000]);
      foreach ((array)($page['segments'] ?? []) as $s) $all[] = $s;
      $after = (int)($page['next_after_seq'] ?? $after);
    } while (!empty($page['has_more']) && ++$guard < 1000);
    return $all;
  }
  public static function ack(string $id): void { self::send('POST', '/jobs/' . rawurlencode($id) . '/ack'); }
  public static function delete(string $id): void { self::send('DELETE', '/jobs/' . rawurlencode($id), null, [], [], [], true); }
  public static function cancel(string $id): void { self::send('POST', '/jobs/' . rawurlencode($id) . '/cancel'); }
  public static function retry(string $id): array { return self::send('POST', '/jobs/' . rawurlencode($id) . '/retry', new stdClass()); }

  // ── webhook ──
  public static function createWebhook(string $url): array { return self::send('POST', '/webhooks', ['url' => $url]); }

  /**
   * 驗 JTLW webhook：hex(HMAC-SHA256(secret, "{X-JTLW-Timestamp}.{原始 body}"))，X-JTLW-Signature: v1=<hex>[,v1=<hex>]；
   * 時間差超過 $tolerance 秒拒收。⚠ 與 8x8 USAGE webhook（base64、t=…,v1=… 同一標頭）不同，不可混用。
   */
  public static function verifyWebhook(string $rawBody, array $headers, array $secrets, int $tolerance = 300, ?int $now = null): bool {
    $ts = (string)($headers['x-jtlw-timestamp'] ?? '');
    $sig = (string)($headers['x-jtlw-signature'] ?? '');
    if (!ctype_digit($ts) || abs(($now ?? time()) - (int)$ts) > $tolerance) return false;
    foreach ($secrets as $secret) {
      if ($secret === '') continue;
      $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
      foreach (explode(',', $sig) as $part) {
        $part = trim($part);
        if (str_starts_with($part, 'v1=') && hash_equals($expected, substr($part, 3))) return true;
      }
    }
    return false;
  }

  /** 使用者看得到的錯誤說明（不含內部細節；原始代碼記在稽核）。措辭照 jtdt describe_error 的原則：說清楚誰該做什麼。 */
  public static function describe(string $code, string $reason = ''): string {
    return match ($code) {
      'not_configured'         => t('尚未設定語音服務（JTLW）。'),
      'network_error'          => t('連不上語音服務（JTLW），稍後會自動重試。'),
      'unauthorized'           => t('語音服務（JTLW）拒絕了金鑰：金鑰錯誤或已撤銷，請管理員到系統設定更新。'),
      'forbidden'              => t('語音服務（JTLW）的金鑰權限不足（需要 jobs:write / jobs:read），請管理員確認。'),
      'queue_full'             => t('語音服務（JTLW）排隊的作業太多，稍後會自動重送。'),
      'source_too_large'       => t('錄影檔超過語音服務（JTLW）的大小上限。'),
      'audio_too_long'         => t('錄影長度超過語音服務（JTLW）的上限。'),
      'disk_full'              => t('語音服務（JTLW）的磁碟空間不足，稍後會自動重試。'),
      'language_not_supported' => t('會議摘要只支援中文與英文會議；這場只會產生逐字稿。'),
      'llm_unavailable', 'llm_failed' => t('產生會議摘要時語言模型服務忙碌或中斷，可以按「重做摘要」再試一次。'),
      'local_file_missing', 'recording_missing' => t('錄影檔已經不在了（可能已被保留政策清除）。'),
      'asr_failed', 'unsupported_media' => t('辨識失敗：錄影可能沒有聲音或檔案損壞。'),
      'empty_transcript'       => t('錄影中沒有辨識到任何說話內容（可能是沒有人說話，或麥克風沒有收到聲音）。'),
      default                  => t('處理失敗（{code}）。', ['code' => $code !== '' ? $code : 'unknown']),
    };
  }
}
