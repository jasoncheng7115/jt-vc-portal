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

summary();
