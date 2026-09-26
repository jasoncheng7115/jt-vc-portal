<?php
/**
 * 極簡 iCalendar (.ics) 產生器：METHOD:REQUEST（邀請 / 更新）與 METHOD:CANCEL（取消），
 * 可被 Google/Outlook/Apple 行事曆自動加入 / 更新 / 移除。
 * 同一場會議的每位受邀者 UID 固定；每次變更 SEQUENCE 遞增，行事曆才會以新版取代舊版。
 */
class ICal {
  /**
   * @param array $opts uid, summary, description, location, organizerEmail,
   *                    organizerName, attendees(array of email), start(unix), end(unix)
   */
  public static function buildRequest(array $opts): string {
    return self::build($opts + ['method' => 'REQUEST']);
  }

  /** 取消通知（METHOD:CANCEL、STATUS:CANCELLED）；UID 須與原邀請相同、SEQUENCE 須更大。 */
  public static function buildCancel(array $opts): string {
    return self::build(['method' => 'CANCEL'] + $opts);
  }

  /** $opts 另可含 method（REQUEST|CANCEL）、sequence（int）。 */
  public static function build(array $opts): string {
    $method  = ($opts['method'] ?? 'REQUEST') === 'CANCEL' ? 'CANCEL' : 'REQUEST';
    $seq     = max(0, (int)($opts['sequence'] ?? 0));
    $uid     = $opts['uid'] ?? (bin2hex(random_bytes(8)) . '@jt-vc-portal');
    $now     = self::fmt(time());
    $start   = self::fmt($opts['start'] ?? time());
    $end     = self::fmt($opts['end'] ?? (($opts['start'] ?? time()) + 3600));
    $summary = self::esc($opts['summary'] ?? t('會議邀請'));
    $desc    = self::esc($opts['description'] ?? '');
    $loc     = self::esc($opts['location'] ?? '');
    $orgEmail = $opts['organizerEmail'] ?? 'no-reply@localhost';
    $orgName  = self::esc($opts['organizerName'] ?? t('JT 視訊會議'));

    $lines = [
      'BEGIN:VCALENDAR',
      'PRODID:-//JT//jaas-auth//ZH-TW',
      'VERSION:2.0',
      'CALSCALE:GREGORIAN',
      'METHOD:' . $method,
      'BEGIN:VEVENT',
      'UID:' . $uid,
      'DTSTAMP:' . $now,
      'DTSTART:' . $start,
      'DTEND:' . $end,
      'SUMMARY:' . $summary,
      'DESCRIPTION:' . $desc,
    ];
    if ($loc !== '') $lines[] = 'LOCATION:' . $loc;
    $lines[] = 'ORGANIZER;CN=' . $orgName . ':mailto:' . $orgEmail;
    foreach (($opts['attendees'] ?? []) as $att) {
      $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $att;
    }
    $lines[] = 'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED');
    $lines[] = 'SEQUENCE:' . $seq;
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    // RFC5545 用 CRLF
    return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
  }

  private static function fmt(int $ts): string {
    return gmdate('Ymd\THis\Z', $ts);
  }

  private static function esc(string $s): string {
    $s = str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $s);
    return $s;
  }

  /**
   * RFC5545 行折疊：每行不超過 75 octets（續行開頭的空白也算 1 octet），
   * 且不可切斷 UTF-8 多位元組字元（mb_strcut 以位元組長度切，但只會切在字元邊界）。
   */
  public static function fold(string $line): string {
    if (strlen($line) <= 75) return $line;
    $out = [];
    $first = true;
    while ($line !== '') {
      $max = $first ? 75 : 74;
      $chunk = mb_strcut($line, 0, $max, 'UTF-8');
      if ($chunk === '') $chunk = substr($line, 0, $max);   // 防呆（非 UTF-8 內容）
      $out[] = ($first ? '' : ' ') . $chunk;
      $line = substr($line, strlen($chunk));
      $first = false;
    }
    return implode("\r\n", $out);
  }
}
