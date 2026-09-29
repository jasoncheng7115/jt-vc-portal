<?php
/**
 * 逐字稿 / 摘要下載（GET /transcript-download?id=&f=pdf|docx|odt|html|json|txt|srt|md|summary）：可檢視者才可下載。
 * pdf / docx / odt / html 為整份會議記錄（會議資訊＋摘要＋逐字稿，lib/txexport.php）。
 * 純文字與 SRT 帶時間與發言者名稱（套用改名）；Markdown 為 JTLW 產生的會議摘要。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/transcripts.php';
require_once __DIR__ . '/lib/txexport.php';

$me = Auth::requireLogin();
$id = (string)($_GET['id'] ?? '');
$f = (string)($_GET['f'] ?? 'json');
if (!Transcripts::validId($id) || !in_array($f, ['pdf', 'docx', 'odt', 'html', 'json', 'txt', 'srt', 'md', 'summary'], true)) Auth::notFound();
$rec = null;
foreach (Recordings::listRecordings() as $r) { if ((string)($r['id'] ?? '') === $id) { $rec = $r; break; } }
if (!$rec || !Transcripts::canView($rec, $me)) Auth::notFound();
$tr = Transcripts::result($id);
if (!$tr) Auth::notFound();

$base = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($rec['room'] ?? 'meeting')) . '-' . date('Ymd-Hi', (int)($rec['mtime'] ?? time()));
$name = function (array $s) use ($tr) {
  $ov = $tr['speaker_overrides'][(string)$s['seq']] ?? '';
  if ($ov !== '') return $ov;
  $sp = (string)($s['speaker'] ?? '');
  return $sp === '' ? '' : ($tr['speaker_names'][$sp] ?? $sp);
};
$mmss = function (?int $ms): string { if ($ms === null) return ''; $t = intdiv($ms, 1000); return sprintf('%02d:%02d', intdiv($t, 60), $t % 60); };
$srtTime = function (int $ms): string { return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000); };
$send = function (string $body, string $type, string $fname) {
  header('Content-Type: ' . $type);
  header('Content-Disposition: attachment; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: private, no-store');
  echo $body;
  exit;
};
Audit::log('transcript_download', t('下載逐字稿 / 摘要（{f}）：會議室「{room}」錄影 {id}', ['f' => $f, 'room' => $rec['room'] ?? '', 'id' => $id]));

if (isset(TxExport::FORMATS[$f])) {
  if ($f !== 'html' && ($miss = TxExport::missing())) {
    // 直接安裝的舊站升級後可能少了 zlib 擴充；給看得懂的訊息，不要吐 500
    http_response_code(503); header('Content-Type: text/plain; charset=utf-8');
    echo t('這台主機缺少匯出需要的元件：{0}。請管理員依 README「系統需求」安裝後再試。', [implode(', ', $miss)]);
    exit;
  }
  $sum = Transcripts::summary($id);
  $title = t('會議記錄：{room}', ['room' => $rec['room'] ?? '']) . ' ' . date('Y-m-d H:i', (int)($rec['mtime'] ?? 0) - (int)($rec['duration'] ?? 0));
  $body = TxExport::render($f, TxExport::model($rec, $tr, $sum, Transcripts::sessionOf($rec)), $title, (string)($tr['language'] ?? 'zh-Hant'));
  $send($body, TxExport::FORMATS[$f], "$base-meeting.$f");
}

switch ($f) {
  case 'json':
    $out = ['room' => $rec['room'] ?? '', 'recorded_at' => date('c', (int)($rec['mtime'] ?? 0)), 'language' => $tr['language'] ?? '',
            'segments' => array_map(fn($s) => ['seq' => $s['seq'], 'start_ms' => $s['start_ms'], 'end_ms' => $s['end_ms'], 'speaker' => $s['speaker'], 'speaker_name' => $name($s), 'text' => $s['text']], $tr['segments'])];
    $send(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'application/json; charset=utf-8', "$base-transcript.json");
  case 'txt':
    // 純文字也要帶時間（jtdt：行首 [mm:ss] 讓別的工具讀得回時間軸）
    $lines = array_map(fn($s) => ($s['start_ms'] === null ? '' : '[' . $mmss($s['start_ms']) . '] ') . (($n = $name($s)) !== '' ? $n . "\u{FF1A}" : '') . $s['text'], $tr['segments']);
    $send("\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n", 'text/plain; charset=utf-8', "$base-transcript.txt");
  case 'srt':
    $i = 0; $out = '';
    foreach ($tr['segments'] as $s) {
      if ($s['start_ms'] === null) continue;
      $end = $s['end_ms'] ?? ($s['start_ms'] + 2000);
      $out .= (++$i) . "\r\n" . $srtTime((int)$s['start_ms']) . ' --> ' . $srtTime((int)$end) . "\r\n" . (($n = $name($s)) !== '' ? $n . "\u{FF1A}" : '') . $s['text'] . "\r\n\r\n";
    }
    $send($out, 'application/x-subrip; charset=utf-8', "$base.srt");
  case 'md':
    $md = Transcripts::summaryMarkdown($id);
    if ($md === null) Auth::notFound();
    $send($md, 'text/markdown; charset=utf-8', "$base-summary.md");
  case 'summary':
    $sum = Transcripts::summary($id);
    if ($sum === null) Auth::notFound();
    $send(json_encode($sum, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'application/json; charset=utf-8', "$base-summary.json");
}
