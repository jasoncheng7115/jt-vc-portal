<?php
/**
 * 視訊服務健康狀態（GET /health → JSON，管理員限定；?force=1 略過 30 秒快取）。
 * 儀表板開頁後用 JS 取，這樣服務掛掉、連線逾時也不會拖慢儀表板。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/health.php';

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$h = Health::all(($_GET['force'] ?? '') === '1');
$pick = fn(array $x) => array_intersect_key($x, array_flip(['level', 'msg', 'recorders', 'healthy', 'busy', 'free_pct']));
echo json_encode(['jitsi' => $pick($h['jitsi']), 'jibri' => $pick($h['jibri']), 'checked_at' => $h['checked_at']], JSON_UNESCAPED_UNICODE);
