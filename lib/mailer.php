<?php
/**
 * 極簡 SMTP client（無外部相依）。支援：
 *   - 明文 / STARTTLS / SMTPS(implicit TLS)
 *   - AUTH LOGIN
 *   - multipart：text/plain + text/calendar(.ics) 附件
 * 防 header injection（A05）：寄件參數內 CR/LF 一律剝除。
 *
 * SMTP 設定存於 settings.json 的 smtp 區塊：
 *   { "smtp": { "enabled":true,"host":"","port":587,"security":"starttls",
 *               "username":"","password":"","from_email":"","from_name":"" } }
 */
require_once __DIR__ . '/settings.php';

class Mailer {
  public static function config(): array {
    $c = Settings::load()['smtp'] ?? [];
    return [
      'enabled'    => !empty($c['enabled']),
      'host'       => $c['host'] ?? '',
      'port'       => (int)($c['port'] ?? 587),
      'security'   => in_array($c['security'] ?? 'starttls', ['none','starttls','tls'], true) ? $c['security'] : 'starttls',
      'username'   => $c['username'] ?? '',
      'password'   => $c['password'] ?? '',
      'from_email' => $c['from_email'] ?? '',
      'from_name'  => $c['from_name'] ?? 'JT 視訊會議',
      'subject_tpl'=> $c['subject_tpl'] ?? self::DEFAULT_SUBJECT_TPL,
      'body_tpl'   => $c['body_tpl'] ?? self::DEFAULT_BODY_TPL,
    ];
  }

  const DEFAULT_SUBJECT_TPL = '會議邀請：{room}';
  const DEFAULT_BODY_TPL = "您好，\n\n您受邀參加線上視訊會議「{room}」。\n{time}\n加入連結：{invite_url}\n\n（附件為行事曆邀請，開啟後可自動加入您的行事曆。）\n\n— {site_name}";

  /** 套用範本：以 {key} 取代 $vars 對應值。 */
  public static function renderTemplate(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) {
      $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    }
    return $tpl;
  }

  private static function clean(string $s): string {
    return str_replace(["\r", "\n", "\0"], '', $s);
  }

  public static function isValidEmail(string $e): bool {
    return (bool) filter_var(trim($e), FILTER_VALIDATE_EMAIL);
  }

  /**
   * 寄一封含 .ics 的會議邀請。回傳 [ok(bool), error(string)]。
   * @param array $opts to(string), subject, bodyText, icsContent, icsFilename
   */
  public static function sendInvite(array $opts, ?array $cfgOverride = null): array {
    $cfg = $cfgOverride ?? self::config();
    if (empty($cfg['enabled']) && $cfgOverride === null) return [false, 'smtp disabled'];
    foreach (['host', 'from_email'] as $k) {
      if (empty($cfg[$k])) return [false, "missing smtp $k"];
    }
    $to = self::clean(trim($opts['to'] ?? ''));
    if (!self::isValidEmail($to)) return [false, 'invalid recipient'];

    $subject = self::clean($opts['subject'] ?? '會議邀請');
    $fromEmail = self::clean($cfg['from_email']);
    $fromName  = self::clean($cfg['from_name']);
    $bodyText  = $opts['bodyText'] ?? '';
    $ics       = $opts['icsContent'] ?? '';
    $icsName   = self::clean($opts['icsFilename'] ?? 'invite.ics');

    // 郵件用主機名：取 from_email 的網域，否則 localhost
    $mailHost = (strpos($fromEmail, '@') !== false) ? substr(strrchr($fromEmail, '@'), 1) : 'localhost';
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $headers = [];
    $headers[] = 'From: ' . self::encodeName($fromName) . " <$fromEmail>";
    $headers[] = "To: <$to>";
    $headers[] = 'Subject: ' . self::encodeHeader($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $mailHost . '>';
    $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";

    $crlf = "\r\n";
    $body  = "--$boundary$crlf";
    $body .= "Content-Type: text/plain; charset=UTF-8$crlf";
    $body .= "Content-Transfer-Encoding: base64$crlf$crlf";
    $body .= chunk_split(base64_encode($bodyText)) . $crlf;

    if ($ics !== '') {
      $body .= "--$boundary$crlf";
      $body .= "Content-Type: text/calendar; method=REQUEST; charset=UTF-8$crlf";
      $body .= "Content-Transfer-Encoding: base64$crlf";
      $body .= "Content-Disposition: attachment; filename=\"$icsName\"$crlf$crlf";
      $body .= chunk_split(base64_encode($ics)) . $crlf;
    }
    $body .= "--$boundary--$crlf";

    $message = implode($crlf, $headers) . $crlf . $crlf . $body;

    return self::smtpSend($cfg, $fromEmail, $to, $message);
  }

  private static function encodeHeader(string $s): string {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
  }
  private static function encodeName(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? self::encodeHeader($s) : $s;
  }

  private static function smtpSend(array $cfg, string $from, string $to, string $message): array {
    $host = $cfg['host'];
    $port = $cfg['port'];
    $security = $cfg['security'];
    $errno = 0; $errstr = '';

    $transport = ($security === 'tls') ? "ssl://$host:$port" : "tcp://$host:$port";
    $fp = @stream_socket_client($transport, $errno, $errstr, 8.0);
    if (!$fp) return [false, "connect: $errstr ($errno)"];
    stream_set_timeout($fp, 8);

    $read = function () use ($fp) {
      $data = '';
      while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
      }
      return $data;
    };
    $cmd = function (string $c) use ($fp, $read) {
      fwrite($fp, $c . "\r\n");
      return $read();
    };
    $expect = function (string $resp, string $code) {
      return strncmp($resp, $code, 3) === 0;
    };

    $greet = $read();
    if (!$expect($greet, '220')) { fclose($fp); return [false, 'greeting: ' . trim($greet)]; }

    $ehloName = (strpos($from, '@') !== false) ? substr(strrchr($from, '@'), 1) : 'localhost';
    $resp = $cmd("EHLO $ehloName");
    if (!$expect($resp, '250')) { fclose($fp); return [false, 'ehlo: ' . trim($resp)]; }

    if ($security === 'starttls') {
      $resp = $cmd('STARTTLS');
      if (!$expect($resp, '220')) { fclose($fp); return [false, 'starttls: ' . trim($resp)]; }
      if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($fp); return [false, 'tls handshake failed'];
      }
      $resp = $cmd("EHLO $ehloName");
      if (!$expect($resp, '250')) { fclose($fp); return [false, 'ehlo2: ' . trim($resp)]; }
    }

    if (!empty($cfg['username'])) {
      $resp = $cmd('AUTH LOGIN');
      if (!$expect($resp, '334')) { fclose($fp); return [false, 'auth: ' . trim($resp)]; }
      $resp = $cmd(base64_encode($cfg['username']));
      if (!$expect($resp, '334')) { fclose($fp); return [false, 'auth user: ' . trim($resp)]; }
      $resp = $cmd(base64_encode($cfg['password']));
      if (!$expect($resp, '235')) { fclose($fp); return [false, 'auth pass rejected']; }
    }

    $resp = $cmd("MAIL FROM:<$from>");
    if (!$expect($resp, '250')) { fclose($fp); return [false, 'mail from: ' . trim($resp)]; }
    $resp = $cmd("RCPT TO:<$to>");
    if (!$expect($resp, '250') && !$expect($resp, '251')) { fclose($fp); return [false, 'rcpt: ' . trim($resp)]; }
    $resp = $cmd('DATA');
    if (!$expect($resp, '354')) { fclose($fp); return [false, 'data: ' . trim($resp)]; }

    // dot-stuffing
    $dataLines = preg_split('/\r\n|\n/', $message);
    foreach ($dataLines as $line) {
      if (isset($line[0]) && $line[0] === '.') $line = '.' . $line;
      fwrite($fp, $line . "\r\n");
    }
    $resp = $cmd('.');
    if (!$expect($resp, '250')) { fclose($fp); return [false, 'send: ' . trim($resp)]; }

    $cmd('QUIT');
    fclose($fp);
    return [true, ''];
  }
}
