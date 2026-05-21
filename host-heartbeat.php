<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rooms.php';

header('Cache-Control: no-store');

Auth::start();
if (!Auth::check()) {
  http_response_code(403);
  exit;
}
$room = Rooms::sanitize($_POST['room'] ?? $_GET['room'] ?? '');
if ($room === '') {
  http_response_code(400);
  exit;
}
// 可選：JSON body 帶與會者名冊快照 { roster: [{name,in,out}] }
$roster = null;
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
  $j = json_decode($raw, true);
  if (is_array($j) && isset($j['roster']) && is_array($j['roster'])) $roster = $j['roster'];
}
Rooms::recordHostHeartbeat($room, $roster);
http_response_code(204);
