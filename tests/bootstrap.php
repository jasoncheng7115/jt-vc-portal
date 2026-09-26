<?php
/**
 * 單元測試啟動檔。於 php:8.4-cli 容器內執行，/var/jaas-data 掛載為拋棄式暫存目錄，
 * /app 掛載 app/（見 tests/run-unit.sh）。絕不可對正式資料目錄執行。
 */
if (!is_dir('/var/jaas-data') || file_exists('/var/jaas-data/.production')) {
  fwrite(STDERR, "refuse: /var/jaas-data missing or marked production\n");
  exit(2);
}
require_once '/app/config.php';

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0, 'cur' => ''];

function test(string $name, callable $fn): void {
  $GLOBALS['__t']['cur'] = $name;
  try {
    $fn();
    echo "  ok   {$name}\n";
    $GLOBALS['__t']['pass']++;
  } catch (Throwable $e) {
    echo "  FAIL {$name}\n       " . $e->getMessage() . "\n";
    $GLOBALS['__t']['fail']++;
  }
}

function ok($cond, string $msg = 'assertion failed'): void {
  if (!$cond) throw new RuntimeException($msg);
}

function eq($a, $b, string $msg = ''): void {
  if ($a !== $b) throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
}

/** 清空測試資料目錄（只在拋棄式目錄內操作）。 */
function reset_data(): void {
  foreach (glob('/var/jaas-data/{,.}*', GLOB_BRACE) ?: [] as $f) {
    if (is_file($f)) @unlink($f);
  }
}

/** 平行啟動 $n 個 php 子行程執行 $code（每個子行程先 require bootstrap）。 */
function run_parallel(int $n, string $code): void {
  $procs = [];
  for ($i = 0; $i < $n; $i++) {
    $script = "<?php require '/app/config.php'; \$WORKER={$i}; " . $code;
    $tmp = tempnam(sys_get_temp_dir(), 'w');
    file_put_contents($tmp, $script);
    $procs[] = [proc_open(['php', $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $tmp];
  }
  foreach ($procs as [$p, $pipes, $tmp]) {
    $err = stream_get_contents($pipes[2]);
    stream_get_contents($pipes[1]);
    proc_close($p);
    @unlink($tmp);
    if (trim($err) !== '') throw new RuntimeException("worker stderr: $err");
  }
}

function summary(): void {
  $t = $GLOBALS['__t'];
  echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
  exit($t['fail'] ? 1 : 0);
}
