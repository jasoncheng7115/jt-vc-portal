<?php
/**
 * 逐字稿與摘要（v1.12.0）單元測試。項目 T01–T12 ↔ TEST_CHECKLIST 3.12 節。
 * 不連任何外部服務：JTLW 呼叫以 mock 整合測試（tests/run-transcribe.sh）涵蓋。
 */
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/transcripts.php';

reset_data();

test('T01 三層以 seq 對齊：時間取 raw、文字優先 final、發言者取 speakers；final 缺段用 raw', function () {
  $raw = [['seq' => 1, 'start_ms' => 0, 'end_ms' => 1000, 'text' => '大家好'], ['seq' => 2, 'start_ms' => 1200, 'end_ms' => 3000, 'text' => '今天開會'],
          ['seq' => 3, 'start_ms' => 3100, 'end_ms' => 3200, 'text' => '  ']];
  $final = [['seq' => 1, 'text' => '大家好。'], ['seq' => 2, 'text' => '']];
  $spk = [['seq' => 1, 'speaker_id' => 'S1'], ['seq' => 2, 'speaker_id' => 'S2']];
  $m = Transcripts::mergeSegments($raw, $final, $spk);
  eq(count($m['segments']), 2, '空白段落要略過');
  eq($m['segments'][0], ['seq' => 1, 'start_ms' => 0, 'end_ms' => 1000, 'speaker' => 'S1', 'text' => '大家好。']);
  eq($m['segments'][1]['text'], '今天開會', 'final 空的段落改用 raw');
  eq($m['uncorrected'], false);
});

test('T01 final 整層沒有（校正失敗）→ 標記 uncorrected、沒有 speakers 也能合併', function () {
  $m = Transcripts::mergeSegments([['seq' => 5, 'start_ms' => 10, 'end_ms' => 20, 'text' => 'x']], [], []);
  eq($m['uncorrected'], true);
  eq($m['segments'][0]['speaker'], '');
});

test('T02 webhook 驗簽：正確簽章通過；錯的密鑰、竄改內容、過期時間戳拒絕', function () {
  $body = '{"event_id":"evt_1","type":"job.succeeded"}'; $ts = (string)time();
  $sig = 'v1=' . hash_hmac('sha256', "$ts.$body", 'whsec_A');
  ok(Jtlw::verifyWebhook($body, ['x-jtlw-timestamp' => $ts, 'x-jtlw-signature' => $sig], ['whsec_A']));
  ok(!Jtlw::verifyWebhook($body, ['x-jtlw-timestamp' => $ts, 'x-jtlw-signature' => $sig], ['whsec_B']), '錯密鑰');
  ok(!Jtlw::verifyWebhook($body . ' ', ['x-jtlw-timestamp' => $ts, 'x-jtlw-signature' => $sig], ['whsec_A']), '竄改');
  $old = (string)(time() - 301);
  ok(!Jtlw::verifyWebhook($body, ['x-jtlw-timestamp' => $old, 'x-jtlw-signature' => 'v1=' . hash_hmac('sha256', "$old.$body", 'whsec_A')], ['whsec_A']), '超過 300 秒');
  ok(!Jtlw::verifyWebhook($body, ['x-jtlw-timestamp' => 'abc', 'x-jtlw-signature' => $sig], ['whsec_A']), '非數字時間戳');
  ok(!Jtlw::verifyWebhook($body, ['x-jtlw-timestamp' => $ts, 'x-jtlw-signature' => base64_encode(hash_hmac('sha256', "$ts.$body", 'whsec_A', true))], ['whsec_A']), '8x8 格式（base64）不可混用');
});

test('T02 webhook 換密鑰期間兩組簽章，任一組符合即通過', function () {
  $body = '{}'; $ts = (string)time();
  $h = ['x-jtlw-timestamp' => $ts, 'x-jtlw-signature' => 'v1=' . hash_hmac('sha256', "$ts.$body", 'old') . ',v1=' . hash_hmac('sha256', "$ts.$body", 'new')];
  ok(Jtlw::verifyWebhook($body, $h, ['new']));
});

// ---- 共用：假的使用者、會議紀錄與錄影 ----
$admin = Users::create(['username' => 'adm', 'email' => 'adm@example.com', 'password' => 'Admin-Pass-12345', 'role' => 'admin']);
$none  = Users::create(['username' => 'h0', 'email' => 'h0@example.com', 'password' => 'Host-Pass-123456', 'role' => 'host']);
$man   = Users::create(['username' => 'h1', 'email' => 'h1@example.com', 'password' => 'Host-Pass-123456', 'role' => 'host']);
$auto  = Users::create(['username' => 'h2', 'email' => 'h2@example.com', 'password' => 'Host-Pass-123456', 'role' => 'host']);
Users::update($man['id'], ['transcribe' => 'manual']);
Users::update($auto['id'], ['transcribe' => 'auto']);
[$none, $man, $auto] = [Users::find($none['id']), Users::find($man['id']), Users::find($auto['id'])];
$now = time();
$sess = function (string $room, string $owner, ?bool $flag) use ($now) {
  Store::appendLine(Rooms::MEETINGS_FILE, ['ts' => $now - 60, 'room' => $room, 'start' => $now - 3600, 'end' => $now - 60, 'dur' => 3540,
    'owner' => $owner, 'owner_name' => 'x', 'attendees' => 2, 'peak' => 2, 'participants' => [['name' => 'Amy', 'in' => $now - 3500, 'out' => $now - 70]], 'transcribe' => $flag]);
};
$rec = fn(string $room, string $id = 'rec-0001') => ['id' => $id, 'room' => $room, 'file' => "$room.mp4", 'mtime' => $now - 30, 'duration' => 3500, 'status' => 'ok', 'size' => 1000];
$sess('r-none', $none['id'], null); $sess('r-man', $man['id'], null); $sess('r-auto', $auto['id'], null);
$sess('r-auto-off', $auto['id'], false); $sess('r-man-on', $man['id'], true); $sess('r-none-on', $none['id'], true);

test('T03 權限：none 不可使用；manual / auto / 管理員可使用', function () use ($none, $man, $auto, $admin) {
  ok(!Transcripts::canUse($none)); ok(Transcripts::canUse($man)); ok(Transcripts::canUse($auto)); ok(Transcripts::canUse($admin));
  eq(Transcripts::perm(['transcribe' => 'bogus']), 'none', '非法值視為 none');
});

test('T04 手動產生：只能對自己主持的場次；管理員任何場次；none 一律不行', function () use ($none, $man, $admin, $rec) {
  ok(Transcripts::canRequest($rec('r-man'), $man), '自己的場次');
  ok(!Transcripts::canRequest($rec('r-auto'), $man), '別人的場次');
  ok(!Transcripts::canRequest($rec('r-none'), $none), 'none 自己的也不行');
  ok(Transcripts::canRequest($rec('r-none'), $admin), '管理員');
  ok(!Transcripts::canView($rec('r-none'), $none), 'none 不可檢視');
  ok(Transcripts::canView($rec('r-man'), $man) && !Transcripts::canView($rec('r-auto'), $man));
});

test('T05 自動產生：帳號 auto → 是；manual / none → 否', function () use ($rec) {
  ok(Transcripts::autoEligible($rec('r-auto')));
  ok(!Transcripts::autoEligible($rec('r-man')));
  ok(!Transcripts::autoEligible($rec('r-none')));
});

test('T06 單場開關覆蓋帳號預設：auto 帳號關掉 → 否；manual 帳號打開 → 是；none 帳號打開仍然否（不能繞過權限）', function () use ($rec) {
  ok(!Transcripts::autoEligible($rec('r-auto-off')));
  ok(Transcripts::autoEligible($rec('r-man-on')));
  ok(!Transcripts::autoEligible($rec('r-none-on')));
});

test('T06 停用的帳號不自動產生', function () use ($auto, $rec) {
  Users::update($auto['id'], ['disabled' => true]);
  ok(!Transcripts::autoEligible($rec('r-auto')));
  Users::update($auto['id'], ['disabled' => false]);
});

test('T07 enqueue：建立 pending；進行中 / 已完成不可重複；id 格式檢查', function () use ($rec, $man) {
  ok(Transcripts::enqueue($rec('r-man', 'rec-aaaa1'), 'manual', $man, 'en'));
  $e = Transcripts::get('rec-aaaa1');
  eq($e['status'], 'pending'); eq($e['language'], 'en'); eq($e['trigger'], 'manual'); eq($e['gen'], 1); eq($e['owner'], $man['id']);
  ok(!Transcripts::enqueue($rec('r-man', 'rec-aaaa1'), 'manual', $man), '進行中不可重複');
  ok(!Transcripts::enqueue($rec('r-man', '../../etc'), 'manual', $man), '非法 id');
  ok(!Transcripts::enqueue($rec('r-man', 'rec-aaaa2'), 'manual', $man, 'xx-bad') === false);
  eq(Transcripts::get('rec-aaaa2')['language'], Settings::getTranscribe()['language'], '非法語言改用預設');
});

test('T08 hints.meeting：會議室、主持人顯示名稱、起訖時間與參與者（ISO 8601 UTC）', function () use ($rec, $man) {
  Users::update($man['id'], ['display_name' => '王經理']);
  $h = Transcripts::meetingHints($rec('r-man'));
  eq($h['room'], 'r-man'); eq($h['host'], '王經理');
  ok(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $h['started_at']) === 1);
  eq($h['participants'][0]['name'], 'Amy');
  ok(!isset($h['title']), '沒有標題就不放 title');
});

test('T09 webhook 事件：只接受自己送出的那件、終態才標記、event_id 去重', function () {
  Store::update(Transcripts::INDEX_FILE, function ($d) { $d['rec-bbbb1'] = ['rec_id' => 'rec-bbbb1', 'status' => 'running', 'job_id' => 'job_X', 'room' => 'r']; return $d; }, []);
  $ev = ['event_id' => 'evt_1', 'type' => 'job.progress', 'job_id' => 'job_X', 'external_ref' => ['system' => 'jtvc', 'job_id' => 'rec-bbbb1']];
  ok(Transcripts::handleEvent($ev)); ok(empty(Transcripts::get('rec-bbbb1')['notify_due']), '非終態不標記');
  ok(Transcripts::handleEvent(['event_id' => 'evt_2', 'type' => 'job.succeeded', 'job_id' => 'job_OTHER', 'external_ref' => ['system' => 'jtvc', 'job_id' => 'rec-bbbb1']]));
  ok(empty(Transcripts::get('rec-bbbb1')['notify_due']), 'job_id 不符（別人的作業）不標記');
  ok(Transcripts::handleEvent(['event_id' => 'evt_3', 'type' => 'job.succeeded', 'job_id' => 'job_X', 'external_ref' => ['system' => 'jtdt', 'job_id' => 'rec-bbbb1']]));
  ok(empty(Transcripts::get('rec-bbbb1')['notify_due']), 'system 不是 jtvc 不標記');
  $ok = ['event_id' => 'evt_4', 'type' => 'job.succeeded', 'job_id' => 'job_X', 'external_ref' => ['system' => 'jtvc', 'job_id' => 'rec-bbbb1']];
  ok(Transcripts::handleEvent($ok)); ok(!empty(Transcripts::get('rec-bbbb1')['notify_due']), '終態 → 待取回');
  Store::update(Transcripts::INDEX_FILE, function ($d) { $d['rec-bbbb1']['notify_due'] = false; return $d; }, []);
  ok(Transcripts::handleEvent($ok)); ok(empty(Transcripts::get('rec-bbbb1')['notify_due']), '同一 event_id 第二次不處理（去重）');
});

test('T10 發言者改名：代號 S\\d、段號為數字、去控制字元、上限 40 字', function () {
  @mkdir(Transcripts::DIR . '/rec-cccc1', 0750, true);
  file_put_contents(Transcripts::DIR . '/rec-cccc1/transcript.json', json_encode(['segments' => [['seq' => 1, 'start_ms' => 0, 'end_ms' => 1, 'speaker' => 'S1', 'text' => 'a']]]));
  Transcripts::saveSpeakers('rec-cccc1', ['S1' => "王\x07經理" . str_repeat('長', 60), 'bad' => 'x', 'S2' => '  '], ['7' => 'Amy', 'x' => 'no']);
  $r = Transcripts::result('rec-cccc1');
  eq(array_keys($r['speaker_names']), ['S1']);
  eq(mb_strlen($r['speaker_names']['S1']), 40); ok(!str_contains($r['speaker_names']['S1'], "\x07"));
  eq($r['speaker_overrides'], ['7' => 'Amy']);
});

test('T11 刪除（purge）：移除本地檔案與索引', function () {
  Store::update(Transcripts::INDEX_FILE, function ($d) { $d['rec-cccc1'] = ['rec_id' => 'rec-cccc1', 'status' => 'done', 'job_id' => '', 'room' => 'r']; return $d; }, []);
  Transcripts::purge('rec-cccc1', 'test');
  ok(!is_dir(Transcripts::DIR . '/rec-cccc1')); ok(Transcripts::get('rec-cccc1') === null);
});

test('T12 錯誤說明：已知代碼有對應文字、未知代碼帶代碼', function () {
  ok(Jtlw::describe('unauthorized') !== Jtlw::describe('queue_full'));
  ok(str_contains(Jtlw::describe('weird_code'), 'weird_code'));
  ok(!str_contains(Jtlw::describe('empty_transcript'), 'empty_transcript'), '沒有說話內容有專屬說明');
});

test('T12 設定：金鑰與 webhook 密鑰留空沿用；首次啟用記下 auto_since；網址去掉 /api/v1', function () {
  Settings::setTranscribe(['enabled' => false, 'jtlw_url' => 'https://10.0.0.5:8790/api/v1', 'jtlw_key' => 'jtlw_x_secret', 'webhook_secret' => 'whsec_1']);
  Settings::setTranscribe(['enabled' => true, 'jtlw_url' => 'https://10.0.0.5:8790', 'jtlw_key' => '', 'webhook_secret' => '']);
  $c = Settings::getTranscribe();
  eq($c['jtlw_key'], 'jtlw_x_secret'); eq($c['webhook_secret'], 'whsec_1'); eq($c['jtlw_url'], 'https://10.0.0.5:8790');
  ok((int)(Settings::getSection('transcribe')['auto_since'] ?? 0) > 0);
  ok(in_array('transcribe', Settings::EXPORTABLE_KEYS, true));
});

$t = $GLOBALS['__t'];
echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
exit($t['fail'] ? 1 : 0);
