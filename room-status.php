<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/rooms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$room = Rooms::sanitize($_GET['room'] ?? '');
$now = time();

if ($room === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'allow' => false, 'status' => 'invalid', 'server_time' => $now]);
  exit;
}

$data = Rooms::get($room);
if ($data === null) {
  echo json_encode([
    'ok' => true,
    'allow' => false,
    'status' => 'unknown',
    'starts_at' => null,
    'ends_at' => null,
    'host_joined' => false,
    'server_time' => $now,
  ]);
  exit;
}

$res = Rooms::evaluate($data, $now);
$res['ok'] = true;
$res['server_time'] = $now;
echo json_encode($res);
