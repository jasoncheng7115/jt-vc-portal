<?php
/**
 * 極簡 iCalendar (.ics) 產生器，METHOD:REQUEST，可被 Google/Outlook/Apple 行事曆自動加入。
 */
class ICal {
  /**
   * @param array $opts uid, summary, description, location, organizerEmail,
   *                    organizerName, attendees(array of email), start(unix), end(unix)
   */
  public static function buildRequest(array $opts): string {
    $uid     = $opts['uid'] ?? (bin2hex(random_bytes(8)) . '@jt-vc-portal');
    $now     = self::fmt(time());
    $start   = self::fmt($opts['start'] ?? time());
    $end     = self::fmt($opts['end'] ?? (($opts['start'] ?? time()) + 3600));
    $summary = self::esc($opts['summary'] ?? '會議邀請');
    $desc    = self::esc($opts['description'] ?? '');
    $loc     = self::esc($opts['location'] ?? '');
    $orgEmail = $opts['organizerEmail'] ?? 'no-reply@localhost';
    $orgName  = self::esc($opts['organizerName'] ?? 'JT 視訊會議');

    $lines = [
      'BEGIN:VCALENDAR',
      'PRODID:-//JT//jaas-auth//ZH-TW',
      'VERSION:2.0',
      'CALSCALE:GREGORIAN',
      'METHOD:REQUEST',
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
    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'SEQUENCE:0';
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

  /** RFC5545 行折疊：超過 75 octets 折行（前置一個空白）。 */
  private static function fold(string $line): string {
    if (strlen($line) <= 75) return $line;
    $out = '';
    while (strlen($line) > 75) {
      $out .= substr($line, 0, 75) . "\r\n ";
      $line = substr($line, 75);
    }
    return $out . $line;
  }
}
