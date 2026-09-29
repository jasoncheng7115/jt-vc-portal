<?php
/**
 * 視訊服務健康檢查（v1.16.0）：Jitsi Meet 與 Jibri 錄影。
 * - 管理員儀表板顯示「系統狀態」（/health，JSON）。
 * - 主持人 / 來賓進會議室前先檢查 Jitsi：有問題就顯示友善的故障頁，不載入一個打不開的會議畫面。
 * 結果快取 30 秒（health-cache.json），避免每次開頁都去連；連線逾時很短，服務掛掉時頁面也不會卡住。
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/jaas.php';
require_once __DIR__ . '/recordings.php';

final class Health {
  public const CACHE_FILE = DATA_DIR . '/health-cache.json';
  public const TTL = 30;
  public const DISK_WARN_PCT = 10;        // 錄影主機可用空間低於這個百分比就警示

  /** 狀態：ok / warn / error / off（沒設定）。$force＝略過快取。 */
  public static function all(bool $force = false): array {
    return ['jitsi' => self::jitsi($force), 'jibri' => self::jibri($force), 'checked_at' => time()];
  }

  public static function jitsi(bool $force = false): array {
    return self::cached('jitsi', $force, [self::class, 'checkJitsi']);
  }

  public static function jibri(bool $force = false): array {
    if (!Settings::hasJibri()) return ['level' => 'off', 'msg' => t('沒有設定 Jibri 錄影服務')];
    return self::cached('jibri', $force, [self::class, 'checkJibri']);
  }

  /** Jitsi 有沒有嚴重到「進不了會議」：只有 error 才擋。 */
  public static function jitsiDown(): bool { return self::jitsi()['level'] === 'error'; }

  private static function cached(string $key, bool $force, callable $fn): array {
    $f = self::CACHE_FILE;
    $all = json_decode((string)@file_get_contents($f), true);
    $hit = is_array($all) ? ($all[$key] ?? null) : null;
    if (!$force && is_array($hit) && time() - (int)($hit['at'] ?? 0) < self::TTL) return $hit;
    $r = $fn();
    $r['at'] = time();
    Store::update(self::CACHE_FILE, function ($d) use ($key, $r) { $d = is_array($d) ? $d : []; $d[$key] = $r; return $d; }, []);
    return $r;
  }

  /** GET，回 [http 狀態, 內容前 2 KB]；連不上回 [0, 錯誤訊息]。 */
  private static function get(string $url, int $timeout = 4): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout,
      CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_RANGE => '0-2047', CURLOPT_USERAGENT => 'jt-vc-portal-health']);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $code ? (string)$body : $err];
  }

  public static function checkJitsi(): array {
    $c = Settings::getJaas();
    $domain = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)$c['domain']);
    if ($domain === '') return ['level' => 'error', 'msg' => t('還沒設定 Jitsi Meet 網域')];
    $base = 'https://' . $domain;
    $web = $c['mode'] === 'jaas' ? $base . '/' . rawurlencode((string)$c['app_id']) . '/external_api.js' : $base . '/external_api.js';
    [$code, $body] = self::get($web);
    if ($code === 0) return ['level' => 'error', 'msg' => t('連不到 Jitsi Meet（{d}）', ['d' => $domain]), 'detail' => mb_substr($body, 0, 200)];
    if ($code >= 400) return ['level' => 'error', 'msg' => t('Jitsi Meet 網頁回應錯誤（HTTP {c}）', ['c' => $code])];
    if ($c['mode'] !== 'jaas') {
      // 自建：再看 XMPP 的 BOSH 端點（prosody 掛掉時網頁還在，但會議連不上）
      [$bc] = self::get($base . '/http-bind');
      if ($bc === 0 || $bc >= 500) return ['level' => 'error', 'msg' => t('Jitsi Meet 的會議連線服務（XMPP）沒有回應'), 'detail' => 'http-bind ' . $bc];
    }
    return ['level' => 'ok', 'msg' => t('正常')];
  }

  public static function checkJibri(): array {
    $s = Recordings::stats();
    if ($s === null) return ['level' => 'error', 'msg' => t('連不到 Jibri 錄影服務')];
    $j = (array)($s['jibri'] ?? []);
    $n = (int)($j['recorders'] ?? 0);
    $detail = array_values(array_filter((array)($j['detail'] ?? []), 'is_array'));
    $disk = (array)($s['disk'] ?? []);
    $freePct = !empty($disk['total']) ? (int)floor(100 * (float)$disk['free'] / (float)$disk['total']) : null;
    $out = ['recorders' => $n, 'free_pct' => $freePct, 'busy' => 0, 'healthy' => null];
    if ($detail) {
      $healthy = 0;
      foreach ($detail as $d) {
        if (strtoupper((string)($d['health'] ?? '')) === 'HEALTHY') $healthy++;
        if (strtoupper((string)($d['busy'] ?? '')) === 'BUSY') $out['busy']++;
      }
      $out['healthy'] = $healthy;
      if ($healthy === 0) return $out + ['level' => 'error', 'msg' => t('所有錄製器都不健康，現在無法錄影')];
      if ($healthy < count($detail)) return $out + ['level' => 'warn', 'msg' => t('{bad} 台錄製器不健康（可用 {ok} 台）', ['bad' => count($detail) - $healthy, 'ok' => $healthy])];
    } elseif ($n === 0) {
      return $out + ['level' => 'error', 'msg' => t('沒有執行中的錄製器，現在無法錄影')];
    }
    if ($freePct !== null && $freePct < self::DISK_WARN_PCT) return $out + ['level' => 'warn', 'msg' => t('錄影主機空間不足（剩 {p}%）', ['p' => $freePct])];
    return $out + ['level' => 'ok', 'msg' => t('正常')];
  }
}
