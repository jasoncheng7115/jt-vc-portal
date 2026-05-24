<?php
/**
 * 安全事件外拋（OWASP A09）。支援 syslog(RFC5424) / CEF / GELF，UDP/TCP。
 * 設定存於 settings.json 的 logship 區塊：
 *   {
 *     "logship": {
 *       "enabled": true,
 *       "host": "10.0.0.5",
 *       "port": 514,
 *       "protocol": "udp",      // udp | tcp
 *       "format": "syslog",     // syslog | cef | gelf
 *       "facility": 16          // local0
 *     }
 *   }
 * fire-and-forget：短 timeout、失敗不影響主流程（A10）。
 */
require_once __DIR__ . '/settings.php';

class LogShip {
  public static function config(): array {
    $c = Settings::load()['logship'] ?? [];
    return [
      'enabled'  => !empty($c['enabled']),
      'host'     => $c['host'] ?? '',
      'port'     => (int)($c['port'] ?? 514),
      'protocol' => in_array($c['protocol'] ?? 'udp', ['udp', 'tcp'], true) ? ($c['protocol'] ?? 'udp') : 'udp',
      'format'   => in_array($c['format'] ?? 'syslog', ['syslog', 'cef', 'gelf'], true) ? ($c['format'] ?? 'syslog') : 'syslog',
      'facility' => (int)($c['facility'] ?? 16),
    ];
  }

  /**
   * 送一筆事件。$event：['type','result','user','ip','user_agent','message','severity']
   * 回傳 [ok(bool), error(string)]，供「測試送出」用。
   */
  public static function send(array $event, ?array $cfgOverride = null): array {
    $cfg = $cfgOverride ?? self::config();
    if (empty($cfg['enabled']) && $cfgOverride === null) return [false, 'disabled'];
    if (empty($cfg['host'])) return [false, 'no host'];

    // 防日誌注入（A09）：剝除所有字串欄位的 CR/LF/NUL，避免攻擊者藉換行偽造或插入記錄。
    foreach ($event as $k => $v) {
      if (is_string($v)) $event[$k] = str_replace(["\r", "\n", "\0"], ' ', $v);
    }

    switch ($cfg['format']) {
      case 'cef':  $payload = self::formatCef($event, $cfg);  break;
      case 'gelf': $payload = self::formatGelf($event, $cfg); break;
      default:     $payload = self::formatSyslog($event, $cfg); break;
    }
    // GELF over TCP 需 null byte 結尾
    if ($cfg['format'] === 'gelf' && $cfg['protocol'] === 'tcp') {
      $payload .= "\0";
    }
    return self::transmit($cfg['protocol'], $cfg['host'], $cfg['port'], $payload);
  }

  private static function transmit(string $proto, string $host, int $port, string $payload): array {
    $errno = 0; $errstr = '';
    $remote = sprintf('%s://%s:%d', $proto, $host, $port);
    $fp = @stream_socket_client($remote, $errno, $errstr, 2.0);
    if (!$fp) return [false, "connect failed: $errstr ($errno)"];
    stream_set_timeout($fp, 2);
    $ok = @fwrite($fp, $payload) !== false;
    @fclose($fp);
    return [$ok, $ok ? '' : 'write failed'];
  }

  private static function severityNum(string $sev): int {
    // syslog severity: 6 info, 4 warning, 3 error
    return ['info' => 6, 'warning' => 4, 'error' => 3][$sev] ?? 6;
  }

  private static function formatSyslog(array $e, array $cfg): string {
    $sev = self::severityNum($e['severity'] ?? 'info');
    $pri = $cfg['facility'] * 8 + $sev;
    $ts  = date('c');
    $host = gethostname() ?: 'jaas-auth';
    $app = 'jaas-auth';
    $msg = self::humanMessage($e);
    // RFC5424: <PRI>1 TIMESTAMP HOST APP PROCID MSGID SD MSG
    return sprintf('<%d>1 %s %s %s - - - %s', $pri, $ts, $host, $app, $msg);
  }

  private static function formatCef(array $e, array $cfg): string {
    $sev = ['info' => 3, 'warning' => 6, 'error' => 9][$e['severity'] ?? 'info'] ?? 3;
    $sig = $e['type'] ?? 'event';
    $name = $e['message'] ?? ($e['type'] ?? 'event');
    $ext = [
      'src'   => $e['ip'] ?? '',
      'suser' => $e['user'] ?? '',
      'outcome' => $e['result'] ?? '',
      'requestClientApplication' => $e['user_agent'] ?? '',
    ];
    $extStr = '';
    foreach ($ext as $k => $v) {
      if ($v === '' || $v === null) continue;
      $v = str_replace(['\\', '=', "\n"], ['\\\\', '\\=', ' '], (string)$v);
      $extStr .= " $k=$v";
    }
    // CEF:Version|Vendor|Product|Version|SignatureID|Name|Severity|Extension
    $header = sprintf('CEF:0|JT|jaas-auth|1.0|%s|%s|%d|',
      self::cefEscapeHeader($sig), self::cefEscapeHeader($name), $sev);
    return $header . ltrim($extStr);
  }

  private static function cefEscapeHeader(string $s): string {
    return str_replace(['\\', '|'], ['\\\\', '\\|'], $s);
  }

  private static function formatGelf(array $e, array $cfg): string {
    $obj = [
      'version'       => '1.1',
      'host'          => gethostname() ?: 'jaas-auth',
      'short_message' => self::humanMessage($e),
      'timestamp'     => microtime(true),
      'level'         => self::severityNum($e['severity'] ?? 'info'),
      '_event_type'   => $e['type'] ?? '',
      '_result'       => $e['result'] ?? '',
      '_user'         => $e['user'] ?? '',
      '_src_ip'       => $e['ip'] ?? '',
      '_user_agent'   => $e['user_agent'] ?? '',
    ];
    return json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  private static function humanMessage(array $e): string {
    if (!empty($e['message'])) return $e['message'];
    return sprintf('%s result=%s user=%s ip=%s',
      $e['type'] ?? 'event', $e['result'] ?? '', $e['user'] ?? '-', $e['ip'] ?? '-');
  }
}
