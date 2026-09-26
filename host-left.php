<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rooms.php';

header('Cache-Control: no-store');

// 僅接受 POST + CSRF（sendBeacon 以 FormData 帶 _csrf），且只能對「自己可主持」的會議室標記離開（A01）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$me = Auth::user();
if (!$me) { http_response_code(403); exit; }
Auth::csrfCheck();
$room = Rooms::sanitize((string)($_POST['room'] ?? $_GET['room'] ?? ''));
if ($room === '') { http_response_code(400); exit; }
if (!Rooms::canHost(Rooms::get($room), $me)) { http_response_code(403); exit; }
Rooms::setHostLeft($room);
http_response_code(204);
