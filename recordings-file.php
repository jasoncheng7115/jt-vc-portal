<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
$id = (string)($_GET['id'] ?? '');
$dl = ($_GET['dl'] ?? '') === '1';
if ($id === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $id)) { http_response_code(404); exit; }

if ($dl) Audit::log('recording_download', '下載錄影 ' . $id);

while (ob_get_level() > 0) ob_end_clean();   // 關閉輸出緩衝，邊收邊送
Recordings::streamFile($id, $dl);
