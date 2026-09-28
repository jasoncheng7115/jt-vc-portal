<?php
/**
 * 逐字稿背景工作（CLI 專用，網頁存取 404）。每分鐘由主機排程執行：
 *   * * * * * docker exec -u www-data jaas-auth php /var/www/html/transcribe-worker.php
 * 自動送件、上傳、查詢進度、取回結果、清除錄影已不在的結果。以檔案鎖確保同時只有一個在跑。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/transcripts.php';

$lock = @fopen(DATA_DIR . '/transcribe-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);           // 上一輪還在跑（例如大檔上傳中）
$verbose = in_array('-v', $argv, true);
$stats = Transcripts::runWorker(function ($m) use ($verbose) { if ($verbose) fwrite(STDOUT, date('c') . " $m\n"); });
if ($verbose) fwrite(STDOUT, json_encode($stats) . "\n");
flock($lock, LOCK_UN);
