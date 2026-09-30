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
  eq(Audit::detailText($e), '逐字稿已產生：會議室「r1」錄影 x1（3 段；摘要：ok）');
  eq(count(Audit::query(5, ['q' => '會議室「r1」'])) >= 1, true, 'keyword search matches viewer-language text');
  I18n::set('ja');
  ok(Audit::detailText($e) !== $e['detail'] && !preg_match('/Transcript generated/', Audit::detailText($e)), 'ja viewer');
  I18n::set('en');
  eq(Audit::detailText(['detail' => 'legacy text']), 'legacy text');
});

summary();
