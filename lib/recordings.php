<?php
/**
 * jibri-recordings-api 用戶端：portal 以 Bearer token 連到自建 Jibri 主機的錄影服務。
 * 服務端僅接受本 portal 來源 IP + token；本類別再在 portal 端強制管理者登入。
 */
require_once __DIR__ . '/settings.php';

class Recordings {
  public static function configured(): bool { return Settings::hasJibri(); }

  /** 發 JSON 請求；回傳 ['ok'=>bool,'code'=>int,'data'=>array|null]。 */
  private static function req(string $method, string $path, ?array $body = null, int $timeout = 8): array {
    $j = Settings::getJibri();
    if ($j['url'] === '' || $j['token'] === '') return ['ok' => false, 'code' => 0, 'data' => null];
    $ch = curl_init($j['url'] . $path);
    $headers = ['Authorization: Bearer ' . $j['token']];
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST  => $method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($body !== null) {
      $headers[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => is_array($data) ? $data : null];
  }

  public static function ping(): bool {
    return self::req('GET', '/api/ping')['ok'];
  }

  /** 容量與統計；失敗回傳 null。 */
  public static function stats(): ?array {
    $r = self::req('GET', '/api/stats');
    return $r['ok'] ? ($r['data'] ?? null) : null;
  }

  public static function listRecordings(): array {
    $r = self::req('GET', '/api/recordings');
    return $r['ok'] ? (array)($r['data']['recordings'] ?? []) : [];
  }

  public static function getConfig(): ?array {
    $r = self::req('GET', '/api/config');
    return $r['ok'] ? ($r['data']['config'] ?? null) : null;
  }

  public static function setConfig(array $conf): ?array {
    $r = self::req('POST', '/api/config', $conf);
    return $r['ok'] ? ($r['data']['config'] ?? null) : null;
  }

  public static function cleanupLog(): array {
    $r = self::req('GET', '/api/cleanup-log');
    return $r['ok'] ? (array)($r['data']['log'] ?? []) : [];
  }

  public static function runCleanup(): ?array {
    $r = self::req('POST', '/api/cleanup-run', []);
    return $r['ok'] ? ($r['data'] ?? null) : null;
  }

  public static function delete(string $id): bool {
    return self::req('DELETE', '/api/recordings/' . rawurlencode($id))['ok'];
  }

  /**
   * 串流代理：把錄影檔（支援 Range）原樣轉給瀏覽器；$dl=true 為下載。
   * 直接輸出 header 與內容，呼叫端不應再輸出任何東西。
   */
  public static function streamFile(string $id, bool $dl = false): void {
    $j = Settings::getJibri();
    if ($j['url'] === '' || $j['token'] === '') { http_response_code(502); echo 'service not configured'; return; }
    $url = $j['url'] . '/api/recordings/' . rawurlencode($id) . '/file' . ($dl ? '?dl=1' : '');
    $headers = ['Authorization: Bearer ' . $j['token']];
    if (!empty($_SERVER['HTTP_RANGE'])) $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT        => 0,
      CURLOPT_HEADERFUNCTION => function ($c, $h) {
        $l = trim($h);
        if (stripos($l, 'HTTP/') === 0) {
          if (preg_match('#\s(\d{3})\s#', $l . ' ', $mm)) http_response_code((int)$mm[1]);
          return strlen($h);
        }
        foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges', 'Content-Disposition'] as $name) {
          if (stripos($l, $name . ':') === 0) { header($l); break; }
        }
        return strlen($h);
      },
      CURLOPT_WRITEFUNCTION  => function ($c, $chunk) {
        echo $chunk;
        return strlen($chunk);
      },
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 0) http_response_code(502);
  }
}
