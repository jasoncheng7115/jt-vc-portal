<?php
/**
 * 逐字稿動作（POST + CSRF）：
 *   request        產生逐字稿與摘要（主持人：自己的場次且有權限；管理員：任何場次）
 *   cancel         取消進行中的作業（同上）
 *   retry_summary  只重做摘要（摘要失敗、逐字稿已在；同上）
 *   regenerate     重新產生（失敗 / 取消 / 已完成；同上，已完成的會先刪除舊結果）
 *   delete         刪除逐字稿與摘要（僅管理員）
 *   speakers       發言者改名（JSON，X-CSRF-Token；可檢視者）
 * 表單動作完成後導回 $_POST['back']（只收站內路徑），speakers 回 JSON。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/transcripts.php';

$me = Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { Auth::notFound(); }
Auth::csrfCheck();

$isJson = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
$in = $isJson ? (json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true) ?: []) : $_POST;
$action = (string)($in['action'] ?? '');
$id = (string)($in['id'] ?? '');
$back = (string)($in['back'] ?? '/recordings');
if (!preg_match('#^/[A-Za-z0-9/_?=&%.-]*$#', $back) || str_starts_with($back, '//')) $back = '/recordings';

$done = function (string $msg, bool $ok = true) use ($isJson, $back) {
  if ($isJson) { header('Content-Type: application/json'); http_response_code($ok ? 200 : 400); echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE); exit; }
  $_SESSION[$ok ? 'rec_msg' : 'rec_err'] = $msg;
  header('Location: ' . $back);
  exit;
};

if (!Settings::transcribeReady()) $done(t('逐字稿功能尚未啟用。'), false);
if (!Transcripts::validId($id)) $done(t('參數錯誤。'), false);
$rec = null;
foreach (Recordings::listRecordings() as $r) { if ((string)($r['id'] ?? '') === $id) { $rec = $r; break; } }
if (!$rec) $done(t('找不到這筆錄影（可能已被清除）。'), false);
$room = (string)($rec['room'] ?? '');
$isAdmin = ($me['role'] ?? '') === 'admin';

switch ($action) {
  case 'request':
  case 'regenerate':
    if (!Transcripts::canRequest($rec, $me)) Auth::notFound();
    if (($rec['status'] ?? '') !== 'ok') $done(t('錄影還沒完成，完成後才能產生逐字稿。'), false);
    $cur = Transcripts::get($id);
    $prevLang = (string)($cur['language'] ?? '');   // 重新產生沿用上次的語言（清除前先記下）
    if ($action === 'regenerate' && $cur && in_array($cur['status'] ?? '', ['done', 'partial'], true)) Transcripts::purge($id, 'regenerate');
    // 失敗 / 取消後重新產生：先刪掉 JTLW 端舊作業（可重試的失敗會保留上傳的錄影）
    if ($action === 'regenerate' && $cur && in_array($cur['status'] ?? '', ['failed', 'cancelled'], true) && empty($cur['job_dropped'])) Transcripts::dropJob($id);
    $lang = (string)($in['language'] ?? '');
    if ($lang === '' && $action === 'regenerate') $lang = $prevLang;
    if (!Transcripts::enqueue($rec, 'manual', $me, $lang !== '' ? $lang : null)) $done(t('這筆錄影已經在處理或已有結果。'), false);
    Audit::log('transcript_request', tk('{what}逐字稿與摘要：會議室「{room}」錄影 {id}', ['what' => $action === 'regenerate' ? t('重新產生') : t('手動產生'), 'room' => $room, 'id' => $id]));
    $done(t('已排入產生逐字稿與摘要，完成時間依錄影長度與排隊狀況而定（通常數分鐘）。'));

  case 'cancel':
    if (!Transcripts::canRequest($rec, $me)) Auth::notFound();
    if (!Transcripts::cancel($id)) $done(t('這筆作業目前無法取消。'), false);
    Audit::log('transcript_cancel', tk('取消逐字稿作業：會議室「{room}」錄影 {id}', ['room' => $room, 'id' => $id]));
    $done(t('已送出取消。'));

  case 'retry_summary':
    if (!Transcripts::canRequest($rec, $me)) Auth::notFound();
    if (!Transcripts::retrySummary($id)) $done(t('無法重做摘要，請改用「重新產生」。'), false);
    Audit::log('transcript_request', tk('重做會議摘要：會議室「{room}」錄影 {id}', ['room' => $room, 'id' => $id]));
    $done(t('已重新送出會議摘要。'));

  case 'delete':
    if (!$isAdmin) Auth::notFound();
    Transcripts::purge($id, 'admin');
    $done(t('已刪除這筆錄影的逐字稿與摘要。'));

  case 'speakers':
    if (!Transcripts::canView($rec, $me) || !Transcripts::result($id)) Auth::notFound();
    Transcripts::saveSpeakers($id, (array)($in['map'] ?? []), (array)($in['overrides'] ?? []));
    Audit::log('transcript_speakers', tk('修改逐字稿發言者名稱：會議室「{room}」錄影 {id}', ['room' => $room, 'id' => $id]));
    $done('ok');
}
$done(t('參數錯誤。'), false);
