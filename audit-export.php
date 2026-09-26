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
$roleMap = ['admin' => t('管理者'), 'host' => t('主持人'), 'guest' => t('來賓')];
$resMap  = ['ok' => t('成功'), 'fail' => t('失敗'), 'warn' => t('警示')];

Audit::log('audit_export', t('匯出稽核記錄 CSV（{n} 筆）', ['n' => count($rows)]));

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
fputcsv($out, [t('時間'), t('行為'), t('帳號'), t('角色'), t('顯示名稱'), t('詳情'), t('結果'), t('來源 IP'), 'User-Agent']);
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
