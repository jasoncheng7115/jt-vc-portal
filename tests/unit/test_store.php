<?php
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/store.php';

reset_data();
$f = '/var/jaas-data/counter.json';

test('write 為原子寫入且可讀回', function () use ($f) {
  ok(Store::write($f, ['a' => 1]));
  eq(Store::read($f), ['a' => 1]);
  eq(count(glob('/var/jaas-data/.counter.json.*') ?: []), 0, '不可殘留暫存檔');
});

test('read 遇到不存在 / 空檔 / 壞 JSON 回預設值', function () {
  eq(Store::read('/var/jaas-data/none.json', ['d']), ['d']);
  file_put_contents('/var/jaas-data/empty.json', '');
  eq(Store::read('/var/jaas-data/empty.json', ['d']), ['d']);
  file_put_contents('/var/jaas-data/bad.json', '{bad');
  eq(Store::read('/var/jaas-data/bad.json', ['d']), ['d']);
});

test('update 並發 8 行程 × 50 次遞增不遺失', function () use ($f) {
  Store::write($f, ['n' => 0]);
  run_parallel(8, 'require_once "/app/lib/store.php"; for ($i=0;$i<50;$i++) Store::update("' . $f . '", function($d){ $d["n"]=($d["n"]??0)+1; return $d; });');
  eq(Store::read($f)['n'], 400);
});

test('update 回傳 null 不寫檔', function () use ($f) {
  $before = filemtime($f); clearstatcache();
  Store::update($f, fn($d) => null);
  eq(Store::read($f)['n'], 400);
});

test('並發寫入期間讀者永遠讀不到空檔', function () use ($f) {
  Store::write($f, ['n' => 0, 'pad' => str_repeat('x', 200000)]);
  $w = proc_open(['php', '-r', 'require_once "/app/config.php"; require_once "/app/lib/store.php"; for($i=0;$i<300;$i++) Store::update("' . $f . '", function($d){ $d["n"]++; return $d; });'], [2 => ['pipe', 'w']], $p);
  $bad = 0;
  for ($i = 0; $i < 3000; $i++) { $d = Store::read($f, null); if ($d === null || !isset($d['n'])) $bad++; }
  $err = stream_get_contents($p[2]); proc_close($w);
  eq(trim($err), '', 'writer stderr');
  eq(Store::read($f)['n'], 300, '寫入者完成');
  eq($bad, 0, '讀到空 / 半寫入檔案的次數');
});

test('pruneLines 與並發 append 不遺失新記錄', function () {
  $j = '/var/jaas-data/lines.jsonl';
  @unlink($j);
  for ($i = 0; $i < 100; $i++) Store::appendLine($j, ['old' => true, 'i' => $i]);
  $w = proc_open(['php', '-r', 'require_once "/app/config.php"; require_once "/app/lib/store.php"; for($i=0;$i<200;$i++) Store::appendLine("' . $j . '", ["old"=>false,"i"=>$i]);'], [2 => ['pipe', 'w']], $p);
  for ($k = 0; $k < 20; $k++) Store::pruneLines($j, fn($ln) => strpos($ln, '"old":true') === false);
  $err = stream_get_contents($p[2]); proc_close($w);
  eq(trim($err), '', 'writer stderr');
  Store::pruneLines($j, fn($ln) => strpos($ln, '"old":true') === false);
  $lines = file($j, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  eq(count($lines), 200, '新記錄數');
});

summary();
