<?php
/**
 * 會議邀請 / 取消通知寄送（Email + .ics）。start.php（建立 / 更新）與 room-delete.php（取消）共用。
 *   - UID：房名 + 建立時間 + 收件者（同名房間日後被重建也不會撞到舊事件）
 *   - SEQUENCE：每次寄送遞增（Rooms::bumpIcsSeq），行事曆才會用新版取代舊版
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/ical.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/rooms.php';

class Invites {
  /** 單次最多寄送幾位（防止被拿來大量寄信） */
  public const MAX_PER_SEND = 50;

  /** 解析 email 清單（換行 / 逗號 / 分號 / 空白分隔），回傳 [合法且不重複, 不合法]。 */
  public static function parseEmails(string $raw): array {
    $ok = []; $bad = []; $seen = [];
    foreach (preg_split('/[\s,;]+/', $raw) as $e) {
      $e = trim($e);
      if ($e === '') continue;
      if (!Mailer::isValidEmail($e)) { $bad[] = $e; continue; }
      if (isset($seen[strtolower($e)])) continue;
      $seen[strtolower($e)] = true; $ok[] = $e;
    }
    return [$ok, $bad];
  }

  /**
   * 寄出邀請（$method=REQUEST）或取消（CANCEL）。回傳 "sent_N_fail_M" 或 "smtp_off"。
   * $room 為房間資料（需含 created_at；starts_at / ends_at 可為 null）。
   */
  public static function send(string $roomName, array $room, array $attendees, array $me, string $method = 'REQUEST'): string {
    $cfg = Mailer::config();
    if (empty($cfg['enabled'])) return 'smtp_off';
    if (!$attendees) return 'sent_0_fail_0';

    $cancel     = $method === 'CANCEL';
    $seq        = Rooms::bumpIcsSeq($roomName);
    $starts_at  = $room['starts_at'] ?? null;
    $ends_at    = $room['ends_at'] ?? null;
    $invite_url = SITE_URL . '/room/' . rawurlencode($roomName);
    $start = $starts_at ?? time();
    $end   = $ends_at ?? ($start + 3600);
    $time_str = $starts_at
      ? t('會議時間：{start} ～ {end}', ['start' => date('Y-m-d H:i', $start), 'end' => date('H:i', $end)])
      : '';
    $topic = (string)($room['title'] ?? '');
    $vars = [
      'room'       => $roomName,
      'topic'      => $topic,                                  // 會議主題（可能空白）
      'title'      => $topic !== '' ? $topic : $roomName,      // 主題；沒填用會議室名稱（預設範本用這個）
      'invite_url' => $invite_url,
      'time'       => $time_str,
      'site_name'  => Settings::getSite()['brand_name'],
      'host'       => ($me['display_name'] ?? '') ?: ($me['username'] ?? ''),
    ];
    $subject = $cancel ? t('會議已取消：{title}', $vars) : Mailer::renderTemplate($cfg['subject_tpl'], $vars);
    $body    = $cancel
      ? t("您好，\n\n線上視訊會議「{title}」已取消。\n{time}\n\n（附件為行事曆取消通知，開啟後可自動從行事曆移除。）\n\n— {site_name}", $vars)
      : Mailer::renderTemplate($cfg['body_tpl'], $vars);
    $created = (int)($room['created_at'] ?? 0);
    $sent = 0; $failed = 0;
    foreach ($attendees as $to) {
      $uid = 'jt-' . substr(md5($roomName . '|' . $created . '|' . $to), 0, 20) . '@jt-vc-portal';
      $ics = ICal::build([
        'method'         => $cancel ? 'CANCEL' : 'REQUEST',
        'sequence'       => $seq,
        'uid'            => $uid,
        'summary'        => $subject,
        'description'    => $cancel ? t('此會議已取消。') : t("請於會議時間點此連結加入：\n{url}", ['url' => $invite_url]),
        'location'       => $invite_url,
        'organizerEmail' => $cfg['from_email'],
        'organizerName'  => $cfg['from_name'],
        'attendees'      => [$to],
        'start'          => $start,
        'end'            => $end,
      ]);
      [$ok] = Mailer::sendInvite([
        'to' => $to,
        'subject' => $subject,
        'bodyText' => $body,
        'icsContent' => $ics,
        'icsMethod' => $cancel ? 'CANCEL' : 'REQUEST',
        'icsFilename' => $cancel ? 'cancel.ics' : 'invite.ics',
      ], $cfg);
      $ok ? $sent++ : $failed++;
    }
    return "sent_{$sent}_fail_{$failed}";
  }
}
