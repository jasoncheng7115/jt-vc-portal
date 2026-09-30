<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/transcripts.php';

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
  foreach (Recordings::listRecordings() as $r) {           // 錄製中不可刪除（畫面反灰，這裡再擋一次）
    if ((string)($r['id'] ?? '') === $id && ($r['status'] ?? '') === 'recording') $back('rec_err', t('錄製中不可刪除，請等會議結束、錄影完成後再刪除。'));
  }
  if ($id !== '' && Recordings::delete($id)) {
    Audit::log('recording_delete', tk('刪除錄影 {id}', ['id' => $id]));
    if (Transcripts::validId($id) && Transcripts::get($id)) Transcripts::purge($id, 'recording_deleted');   // 逐字稿跟著錄影走
    $back('rec_msg', t('已刪除該筆錄影。'));
  }
  $back('rec_err', t('刪除失敗（可能正在錄製或服務無法連線）。'));
}

if ($action === 'cleanup') {
  $r = Recordings::runCleanup();
  if ($r === null) $back('rec_err', t('清理失敗（服務無法連線）。'));
  $n = count($r['removed'] ?? []);
  Audit::log('recording_cleanup', tk('手動執行保留政策清理，刪除 {n} 筆', ['n' => $n]));
  $back('rec_msg', t('清理完成，刪除 {n} 筆。', ['n' => $n]));
}

$back('rec_err', t('未知的操作。'));
