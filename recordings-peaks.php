<?php
/**
 * 錄影的波形資料（v1.21.0）：逐字稿頁畫波形用，只回數字（不含聲音內容）。
 * 權限同播放：主持人只能看自己主持的會議錄影，管理員不限；錄製中的錄影不提供。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/recordings.php';

$me = Auth::requireLogin();
$id = (string)($_GET['id'] ?? '');
if ($id === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $id)) Auth::notFound();
$rec = null;
foreach (Recordings::listRecordings() as $r) { if ((string)$r['id'] === $id) { $rec = $r; break; } }
if (!$rec || (($me['role'] ?? '') !== 'admin' && !Recordings::canAccess($rec, $me))) Auth::notFound();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($rec['status'] ?? '') !== 'ok') { header('Cache-Control: no-store'); echo json_encode(['peaks' => null]); exit; }
session_write_close();   // 第一次要等錄影主機算，不要卡住同一個 session 的其他請求
$p = Recordings::peaks($id);
header('Cache-Control: ' . ($p ? 'private, max-age=86400' : 'no-store'));
echo json_encode(['peaks' => $p]);
