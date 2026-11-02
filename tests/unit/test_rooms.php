<?php
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/rooms.php';

reset_data();

function meetings(): array {
  $f = Rooms::MEETINGS_FILE;
  if (!file_exists($f)) return [];
  return array_map(fn($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

test('upsert 建立房間並設定 owner（之後不可被改寫）', function () {
  Rooms::upsert('r1', ['owner' => 'u_a', 'owner_name' => 'a']);
  Rooms::upsert('r1', ['owner' => 'u_b', 'owner_name' => 'b']);
  eq(Rooms::get('r1')['owner'], 'u_a');
});

test('canHost：擁有者 / 管理員可、其他主持人不可、不存在房間不可', function () {
  $r = Rooms::get('r1');
  ok(Rooms::canHost($r, ['id' => 'u_a', 'role' => 'host']));
  ok(Rooms::canHost($r, ['id' => 'u_x', 'role' => 'admin']));
  ok(!Rooms::canHost($r, ['id' => 'u_b', 'role' => 'host']));
  ok(!Rooms::canHost(null, ['id' => 'u_a', 'role' => 'admin']));
});

test('心跳 → 主持人在線 → 來賓放行；離開後結算時長', function () {
  Rooms::recordHostHeartbeat('r1', [['name' => 'g1', 'in' => time() - 5, 'out' => null]]);
  eq(Rooms::evaluate(Rooms::get('r1'))['allow'], true);
  // 讓 session 起點往前 120 秒
  Store::update(AUTO_ALLOW_FILE, function ($d) { $d['r1']['host_joined_at'] = time() - 120; return $d; });
  Rooms::setHostLeft('r1');
  $m = meetings();
  eq(count($m), 1);
  ok($m[0]['dur'] >= 119 && $m[0]['dur'] <= 125, 'dur≈120');
  eq($m[0]['peak'], 1);
  eq(Rooms::get('r1')['host_joined'], false);
});

test('當掉未送離開：下次心跳先以最後心跳結算舊 session，不延續舊起點', function () {
  $t0 = time() - 7200;
  Store::update(AUTO_ALLOW_FILE, function ($d) use ($t0) {
    $d['r1']['host_joined'] = true; $d['r1']['host_joined_at'] = $t0; $d['r1']['host_seen_at'] = $t0 + 600; return $d;
  });
  Rooms::recordHostHeartbeat('r1');
  $m = meetings();
  eq(count($m), 2);
  eq($m[1]['dur'], 600, '舊 session 以最後心跳結算');
  ok(Rooms::get('r1')['host_joined_at'] >= time() - 2, '新 session 由現在起算');
});

test('並發：心跳持續寫入時建立 40 個房間，不遺失任何房間', function () {
  run_parallel(1, 'require_once "/app/lib/rooms.php"; for($i=0;$i<200;$i++) Rooms::recordHostHeartbeat("r1");');
  run_parallel(4, 'require_once "/app/lib/rooms.php"; for($i=0;$i<10;$i++) Rooms::upsert("c{$WORKER}x{$i}", ["owner"=>"u"]);');
  $all = Rooms::load();
  $n = count(array_filter(array_keys($all), fn($k) => $k[0] === 'c'));
  eq($n, 40);
  ok(isset($all['r1']), 'r1 仍在');
});

test('清除過期房間前先結算未收尾 session', function () {
  $t0 = time() - 3 * 86400;
  Store::update(AUTO_ALLOW_FILE, function ($d) use ($t0) {
    $d['old'] = ['created_at' => $t0, 'host_joined' => true, 'host_joined_at' => $t0, 'host_seen_at' => $t0 + 300, 'owner' => 'u'];
    return $d;
  });
  $before = count(meetings());
  Rooms::pruneAndGet();
  ok(!isset(Rooms::load()['old']), '過期房間已清除');
  eq(count(meetings()), $before + 1);
});

test('sanitize 僅留 ASCII 英數 - _', function () {
  eq(Rooms::sanitize(' 週會 Weekly  Sync!! '), 'Weekly-Sync');
  eq(Rooms::sanitize('a--b__c'), 'a-b__c');
  eq(Rooms::sanitize('中文'), '');
});

test('evaluate：排程倒數 / 開放 / 結束', function () {
  $now = time();
  eq(Rooms::evaluate(['starts_at' => $now + 60, 'ends_at' => null], $now)['status'], 'countdown');
  eq(Rooms::evaluate(['starts_at' => $now - 60, 'ends_at' => $now + 60], $now)['status'], 'open');
  eq(Rooms::evaluate(['starts_at' => $now - 120, 'ends_at' => $now - 60], $now)['status'], 'expired');
  eq(Rooms::evaluate(['starts_at' => null], $now)['status'], 'wait_host');
});

test('T79 主持人離開時帶名冊：離開前剛進場、還沒被心跳回報的人也記得到；重複的離開通知不多記一段', function () {
  Rooms::upsert('r79', ['owner' => 'u_a', 'owner_name' => 'a']);
  Rooms::recordHostHeartbeat('r79', [['name' => '主持人', 'in' => time() - 60, 'out' => null]]);
  Store::update(AUTO_ALLOW_FILE, function ($d) { $d['r79']['host_joined_at'] = time() - 60; return $d; });   // 會議開了 60 秒（同一秒內結束的不記）
  $before = count(meetings());
  Rooms::setHostLeft('r79', [['name' => '主持人', 'in' => time() - 60, 'out' => null], ['name' => '晚到的來賓', 'in' => time() - 5, 'out' => null]]);
  $m = meetings();
  eq(count($m), $before + 1);
  $last = end($m);
  eq($last['room'], 'r79');
  eq($last['attendees'], 2, '離開通知帶的名冊有兩人');
  ok(in_array('晚到的來賓', array_column($last['participants'], 'name'), true), '晚到的來賓有記到');
  Rooms::setHostLeft('r79', [['name' => '主持人', 'in' => time() - 60, 'out' => null], ['name' => '又一位', 'in' => time() - 1, 'out' => null]]);
  eq(count(meetings()), $before + 1, '會議已結算：重複的離開通知不重開、不多記');
  ok(empty(Rooms::get('r79')['roster']), '沒有進行中的會議時不採用送來的名冊');
  Rooms::recordHostHeartbeat('r79', [['name' => '主持人', 'in' => time(), 'out' => null]]);
  Store::update(AUTO_ALLOW_FILE, function ($d) { $d['r79']['host_joined_at'] = time() - 60; return $d; });   // 會議開了 60 秒（同一秒內結束的不記）
  Rooms::setHostLeft('r79');
  $mm = meetings(); eq(end($mm)['attendees'], 1, '舊的離開通知（不帶名冊）照舊用心跳回報的名冊');
});

test('T87 已建立的會議室補寄邀請：email 解析與受邀名單合併', function () {
  require_once '/app/lib/invites.php';
  [$ok, $bad] = Invites::parseEmails("a@example.com, B@Example.com;\nb@example.com  not-an-email\n\nc@example.com");
  eq($ok, ['a@example.com', 'B@Example.com', 'c@example.com'], '逗號 / 分號 / 空白 / 換行都能分隔，大小寫不同視為同一人');
  eq($bad, ['not-an-email'], '格式不對的另外列出');
  ok(Invites::MAX_PER_SEND === 50, '單次上限 50 位');
  Rooms::upsert('r87', ['owner' => 'u_a', 'owner_name' => 'a', 'attendees' => ['old@example.com']]);
  eq(Rooms::addAttendees('r87', ['new@example.com', 'OLD@example.com']), ['old@example.com', 'new@example.com'], '併入名單、不重複、保留原順序');
  eq(Rooms::get('r87')['attendees'], ['old@example.com', 'new@example.com'], '寫進房間資料');
  eq(Rooms::get('r87')['owner'], 'u_a', '其他欄位不受影響');
  ok(Rooms::addAttendees('no-such-room', ['x@example.com']) === null, '房間不存在回 null');
});

test('T88 會議主題：整理、儲存、顯示名稱、邀請信預設範本', function () {
  eq(Rooms::cleanTitle("  第四季\t產品\n規劃  會議 "), '第四季 產品 規劃 會議', '控制字元與多餘空白收成一個空白');
  eq(mb_strlen(Rooms::cleanTitle(str_repeat('會', 150))), Rooms::TITLE_MAX, '最多 100 字');
  Rooms::upsert('r88', ['owner' => 'u_a', 'owner_name' => 'a', 'title' => "  週會\x07 "]);
  eq(Rooms::get('r88')['title'], '週會', '建立時存入整理過的主題');
  Rooms::upsert('r88', ['host_joined' => true]);
  eq(Rooms::get('r88')['title'], '週會', '進入會議（沒帶主題）不會清掉主題');
  ok(Rooms::setTitle('r88', '第四季規劃') && Rooms::get('r88')['title'] === '第四季規劃', 'setTitle 修改主題');
  ok(!Rooms::setTitle('no-such-room', 'x'), '房間不存在回 false');
  eq(Rooms::label('r88', Rooms::get('r88')), '第四季規劃', '有主題用主題');
  Rooms::upsert('r88b', ['owner' => 'u_a']);
  eq(Rooms::label('r88b', Rooms::get('r88b')), 'r88b', '沒有主題用會議室名稱');
  require_once '/app/lib/mailer.php';
  I18n::set('zh-TW');
  eq(Mailer::renderTemplate(Mailer::defaultSubject(), ['title' => '第四季規劃', 'room' => 'q4']), '會議邀請：第四季規劃', '預設主旨用 {title}');
  ok(Mailer::isDefaultTpl('會議邀請：{room}', 'subject') && Mailer::isDefaultTpl('會議邀請：{title}', 'subject'), '舊版（{room}）與新版（{title}）預設主旨都視為未自訂');
  ok(Mailer::isDefaultTpl('Meeting invitation: {room}', 'subject') && Mailer::isDefaultTpl('Meeting invitation: {title}', 'subject'), '英文的新舊預設也一樣');
  ok(!Mailer::isDefaultTpl('【公司】{room} 開會', 'subject'), '自訂範本照原樣使用');
});

test('T89 自動錄影旗標：建立時存、進入會議不清掉、舊資料預設關閉', function () {
  Rooms::upsert('r89', ['owner' => 'u_a', 'auto_record' => true]);
  ok(Rooms::get('r89')['auto_record'] === true, '存成開啟');
  Rooms::upsert('r89', ['host_joined' => true]);
  ok(Rooms::get('r89')['auto_record'] === true, '進入會議（沒帶旗標）不會清掉');
  Rooms::upsert('r89b', ['owner' => 'u_a']);
  ok(Rooms::get('r89b')['auto_record'] === false, '沒指定時預設關閉');
});

summary();
