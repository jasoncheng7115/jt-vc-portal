<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireLogin();
$id = (string)($_GET['id'] ?? '');
$dl = ($_GET['dl'] ?? '') === '1';
if ($id === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $id)) { http_response_code(404); exit; }

// 主持人只能存取自己主持的會議錄影；管理者不限。錄製中的錄影一律不提供播放 / 下載（檔案還沒寫完）。
$rec = null;
foreach (Recordings::listRecordings() as $r) { if ((string)$r['id'] === $id) { $rec = $r; break; } }
if (($me['role'] ?? '') !== 'admin' && (!$rec || !Recordings::canAccess($rec, $me))) Auth::notFound();
if ($rec && ($rec['status'] ?? '') === 'recording') { http_response_code(409); header('Content-Type: text/plain; charset=utf-8'); echo t('錄製中，錄影完成後才能播放或下載。'); exit; }

if ($dl) Audit::log('recording_download', t('下載錄影 {id}', ['id' => $id]));

while (ob_get_level() > 0) ob_end_clean();   // 關閉輸出緩衝，邊收邊送
Recordings::streamFile($id, $dl);
