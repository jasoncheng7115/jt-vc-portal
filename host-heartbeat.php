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
Rooms::recordHostHeartbeat($room);
http_response_code(204);
