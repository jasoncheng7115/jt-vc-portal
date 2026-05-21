<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/audit.php';

$me = Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /recordings'); exit; }
Auth::csrfCheck();

$back = function (string $key, string $msg) {
  $_SESSION[$key] = $msg;
  header('Location: /recordings');
  exit;
};

$action = $_POST['action'] ?? '';

if ($action === 'delete') {
  $id = (string)($_POST['id'] ?? '');
  if ($id !== '' && Recordings::delete($id)) {
    Audit::log('recording_delete', '刪除錄影 ' . $id);
    $back('rec_msg', '已刪除該筆錄影。');
  }
  $back('rec_err', '刪除失敗（可能正在錄製或服務無法連線）。');
}

if ($action === 'cleanup') {
  $r = Recordings::runCleanup();
  if ($r === null) $back('rec_err', '清理失敗（服務無法連線）。');
  $n = count($r['removed'] ?? []);
  Audit::log('recording_cleanup', '手動執行保留政策清理，刪除 ' . $n . ' 筆');
  $back('rec_msg', '清理完成，刪除 ' . $n . ' 筆。');
}

$back('rec_err', '未知的操作。');
