<?php
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/ical.php';
require_once '/app/lib/rooms.php';
require_once '/app/lib/audit.php';
Auth::start();   // 先開 session（CLI 測試：避免之後輸出後才開 session 的警告）

reset_data();

test('.ics 折行：每行 ≤ 75 bytes 且不切斷 UTF-8 字元', function () {
  $line = 'SUMMARY:' . str_repeat('會議邀請中文標題測試', 12);
  $folded = ICal::fold($line);
  foreach (explode("\r\n", $folded) as $i => $l) {
    ok(strlen($l) <= 75, "第 {$i} 行 " . strlen($l) . ' bytes');
    ok(mb_check_encoding($l, 'UTF-8'), "第 {$i} 行 UTF-8 無效");
  }
  // 展開後與原文相同
  eq(str_replace("\r\n ", '', $folded), $line);
});

test('.ics REQUEST / CANCEL 與 SEQUENCE', function () {
  $r = ICal::build(['uid' => 'u1@x', 'sequence' => 3, 'start' => 1700000000, 'end' => 1700003600, 'attendees' => ['a@example.com']]);
  ok(strpos($r, "METHOD:REQUEST") !== false && strpos($r, "STATUS:CONFIRMED") !== false && strpos($r, "SEQUENCE:3") !== false);
  $c = ICal::buildCancel(['uid' => 'u1@x', 'sequence' => 4, 'start' => 1700000000, 'end' => 1700003600]);
  ok(strpos($c, "METHOD:CANCEL") !== false && strpos($c, "STATUS:CANCELLED") !== false && strpos($c, "SEQUENCE:4") !== false);
  ok(strpos($c, "UID:u1@x") !== false);
});

test('Rooms::bumpIcsSeq 遞增、不存在房間回 0', function () {
  Rooms::upsert('seqroom', ['owner' => 'u']);
  eq(Rooms::bumpIcsSeq('seqroom'), 1);
  eq(Rooms::bumpIcsSeq('seqroom'), 2);
  eq(Rooms::get('seqroom')['ics_seq'], 2);
  eq(Rooms::bumpIcsSeq('nope'), 0);
});

test('Rooms::delete：移除房間，進行中 session 先結算', function () {
  Rooms::upsert('delroom', ['owner' => 'u', 'host_joined' => true]);
  Store::update(AUTO_ALLOW_FILE, function ($d) { $d['delroom']['host_joined_at'] = time() - 300; return $d; });
  ok(Rooms::delete('delroom'));
  ok(Rooms::get('delroom') === null);
  $m = array_values(array_filter(Rooms::meetingSessions(0, time() + 10), fn($e) => $e['room'] === 'delroom'));
  eq(count($m), 1);
  ok(!Rooms::delete('delroom'), '重複刪除回 false');
});

test('Audit：欄位長度上限、依保留天數清理', function () {
  Audit::log('login_fail', str_repeat('x', 5000), ['actor' => str_repeat('a', 500)]);
  $last = Audit::query(1)[0];
  ok(mb_strlen($last['detail']) <= 1000 && mb_strlen($last['actor']) <= 128);
  $old = ['ts' => time() - 400 * 86400, 'time' => date('c', time() - 400 * 86400), 'action' => 'login', 'detail' => 'old'];
  Store::appendLine(Audit::FILE, $old);
  ok(Audit::prune(365));
  $all = Audit::query(1000);
  ok(!array_filter($all, fn($e) => ($e['detail'] ?? '') === 'old'), '舊記錄已清除');
  ok(count($all) >= 1, '新記錄保留');
});

test('Audit（T67）：排程 / 指令列的記錄是 system、無來源 IP；說明依看的人語言顯示', function () {
  I18n::set('en');
  Audit::log('transcript_done', tk('逐字稿已產生：會議室「{room}」錄影 {id}（{n} 段；摘要：{s}）', ['room' => 'r1', 'id' => 'x1', 'n' => 3, 's' => 'ok']));
  $e = Audit::query(1)[0];
  eq($e['actor'], 'system'); eq($e['ip'], ''); eq($e['dk'] ?? '', '逐字稿已產生：會議室「{room}」錄影 {id}（{n} 段；摘要：{s}）');
  ok(str_starts_with($e['detail'], 'Transcript generated'), 'stored text in writer language: ' . $e['detail']);
  I18n::set('zh-TW');
  eq(Audit::detailText($e), '逐字稿已產生：會議室「r1」錄影（代號 x1）（3 段；摘要：ok）', 'v1.22.0：舊鍵顯示時改成新說法；錄影已不在 → 短碼');
  eq(count(Audit::query(5, ['q' => '會議室「r1」'])) >= 1, true, 'keyword search matches viewer-language text');
  I18n::set('ja');
  ok(Audit::detailText($e) !== $e['detail'] && !preg_match('/Transcript generated/', Audit::detailText($e)), 'ja viewer');
  I18n::set('en');
  eq(Audit::detailText(['detail' => 'legacy text']), 'legacy text');
});

test('Audit（T83）：錄影類訊息不顯示 UUID，改成會議室＋錄影時間與長度；SIEM 文字與搜尋仍有完整 id；舊記錄沿用', function () {
  I18n::set('zh-TW');
  $id = 'c97c8526-e76f-4ff3-b966-1ba8bc2a878f';
  $rec = ['id' => $id, 'room' => 'tung-graylog', 'mtime' => mktime(22, 27, 0, 10, 15, 2026), 'duration' => 2809];
  eq(Audit::rec($rec, $id), ['id' => $id, 'when' => '10/15 21:40', 'min' => '47'], '開始時間＝結束 − 長度；分鐘四捨五入');
  eq(Audit::rec(['rec_mtime' => $rec['mtime'], 'duration' => 2809], $id)['when'], '10/15 21:40', '逐字稿索引用 rec_mtime');
  eq(Audit::rec(null, $id), ['id' => $id], '沒有錄影資料只留 id');
  Audit::log('transcript_request', tk('自動產生逐字稿：會議室「{room}」{rec}', ['room' => 'tung-graylog'] + Audit::rec($rec, $id)));
  $e = Audit::query(1)[0];
  eq(Audit::detailText($e), '自動產生逐字稿：會議室「tung-graylog」10/15 21:40 的錄影（47 分）');
  ok(!str_contains(Audit::detailText($e), $id), '畫面上不出現 UUID');
  ok(str_contains($e['detail'], $id), 'SIEM / 記錄檔的文字仍帶完整 id：' . $e['detail']);
  eq(count(Audit::query(5, ['q' => $id])) >= 1, true, '用完整 id 仍搜得到');
  eq(count(Audit::query(5, ['q' => '21:40 的錄影'])) >= 1, true, '用畫面上的文字也搜得到');
  I18n::set('en');
  eq(Audit::detailText($e), 'Auto-generating transcript: room "tung-graylog", the 10/15 21:40 recording (47 min)');
  I18n::set('ja');
  ok(str_contains(Audit::detailText($e), '10/15 21:40 の録画（47 分）'), Audit::detailText($e));
  I18n::set('zh-TW');
  // 舊記錄（v1.16.2–v1.21.0）：鍵是「錄影 {id}」、沒有時間；錄影不在了 → 短碼，下載 / 刪除沒有會議室 → —
  eq(Audit::detailText(['dk' => '下載錄影 {id}', 'dv' => ['id' => $id]]), '下載錄影：會議室「—」錄影（代號 c97c8526）');
  eq(Audit::detailText(['dk' => '刪除錄影：會議室「{room}」{rec}', 'dv' => ['room' => 'r9', 'id' => $id, 'when' => '10/16 09:00', 'min' => '']]), '刪除錄影：會議室「r9」10/16 09:00 開始的錄影', '長度不明（錄製中）');
  eq(Audit::detailText(['detail' => '下載錄影 abc（舊版純文字）']), '下載錄影 abc（舊版純文字）', '更早的純文字記錄照原樣');
});

summary();
