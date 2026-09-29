<?php
/**
 * 測試用的假 Jibri 錄影服務（jibri-recordings-api 的最小子集）：php -S 0.0.0.0:9080 jibri.php
 * 錄影清單在 /data/recs.json（[{id,room,file,size,mtime,status,duration}]），檔案內容為 /data/<id>.mp4。
 * 只給 tests/run-transcribe.sh 用；Bearer token 固定 jibri-test。
 */
$data = '/data';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer jibri-test') { http_response_code(401); echo '{}'; return true; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$recs = json_decode((string)@file_get_contents("$data/recs.json"), true) ?: [];
header('Content-Type: application/json');
if ($path === '/api/ping') { echo '{"ok":true}'; return true; }
if ($path === '/api/stats') { echo json_encode(['disk' => ['total' => 1e10, 'used' => 1e9, 'free' => 9e9], 'recordings' => ['count' => count($recs), 'size' => (int)array_sum(array_column($recs, 'size'))]]); return true; }
if ($path === '/api/recordings' && $_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode(['recordings' => $recs]); return true; }
if (preg_match('#^/api/recordings/([A-Za-z0-9_-]+)/file$#', $path, $m)) {
  $f = "$data/{$m[1]}.mp4";
  if (!is_file($f)) { http_response_code(404); echo '{}'; return true; }
  header('Content-Type: video/mp4'); header('Content-Length: ' . filesize($f)); header('Accept-Ranges: bytes');
  readfile($f); return true;
}
if (preg_match('#^/api/recordings/([A-Za-z0-9_-]+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
  $recs = array_values(array_filter($recs, fn($r) => $r['id'] !== $m[1]));
  file_put_contents("$data/recs.json", json_encode($recs)); @unlink("$data/{$m[1]}.mp4");
  echo '{"ok":true}'; return true;
}
http_response_code(404); echo '{}'; return true;
