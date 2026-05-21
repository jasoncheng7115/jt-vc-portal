<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
$f = [
  'action' => $_GET['action'] ?? '',
  'q'      => trim($_GET['q'] ?? ''),
  'from'   => trim($_GET['from'] ?? ''),
  'to'     => trim($_GET['to'] ?? ''),
];
$rows    = Audit::searchAll($f);
$labels  = Audit::labels();
$roleMap = ['admin' => '管理者', 'host' => '主持人', 'guest' => '來賓'];
$resMap  = ['ok' => '成功', 'fail' => '失敗', 'warn' => '警示'];

Audit::log('audit_export', '匯出稽核記錄 CSV（' . count($rows) . ' 筆）');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd-His') . '.csv"');
header('Cache-Control: no-store');

// 防 CSV 公式注入（Excel/Sheets）：以危險字元開頭的值前置單引號。
$safe = function ($v): string {
  $s = (string)$v;
  if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
  return $s;
};

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM，讓 Excel 正確顯示中文
fputcsv($out, ['時間', '行為', '帳號', '角色', '顯示名稱', '詳情', '結果', '來源 IP', 'User-Agent']);
foreach ($rows as $e) {
  $ts = $e['ts'] ?? strtotime($e['time'] ?? 'now');
  fputcsv($out, [
    date('Y-m-d H:i:s', $ts),
    $safe($labels[$e['action'] ?? ''] ?? ($e['action'] ?? '')),
    $safe($e['actor'] ?? ''),
    $safe($roleMap[$e['role'] ?? ''] ?? ($e['role'] ?? '')),
    $safe($e['actor_name'] ?? ''),
    $safe($e['detail'] ?? ''),
    $safe($resMap[$e['result'] ?? 'ok'] ?? ($e['result'] ?? '')),
    $safe($e['ip'] ?? ''),
    $safe($e['user_agent'] ?? ''),
  ]);
}
fclose($out);
