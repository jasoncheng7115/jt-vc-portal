<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rooms.php';

header('Cache-Control: no-store');

// 僅接受 POST + CSRF（X-CSRF-Token 標頭），且只能回報「自己可主持」的會議室（A01）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$me = Auth::user();
if (!$me) { http_response_code(403); exit; }
Auth::csrfCheck();
$room = Rooms::sanitize((string)($_GET['room'] ?? $_POST['room'] ?? ''));
if ($room === '') { http_response_code(400); exit; }
if (!Rooms::canHost(Rooms::get($room), $me)) { http_response_code(403); exit; }

// 可選：JSON body 帶與會者名冊快照 { roster: [{name,in,out}], talk: [{n,s,e}] }（talk＝主要發言者時間軸，毫秒）
$roster = null; $talk = null;
$raw = file_get_contents('php://input', false, null, 0, 524288);   // 上限 512KB（名冊＋主要發言者時間軸）
if (is_string($raw) && $raw !== '') {
  $j = json_decode($raw, true);
  if (is_array($j) && isset($j['roster']) && is_array($j['roster'])) $roster = $j['roster'];
  if (is_array($j) && isset($j['talk']) && is_array($j['talk'])) $talk = $j['talk'];   // 主要發言者時間軸（v1.13.0）
}
Rooms::recordHostHeartbeat($room, $roster, $talk);
http_response_code(204);
