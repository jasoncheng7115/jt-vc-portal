<?php
/**
 * JTLW webhook 接收端（POST /jtlw-webhook）。
 * 驗簽（hex HMAC-SHA256，X-JTLW-Timestamp / X-JTLW-Signature: v1=…，300 秒視窗）→ event_id 去重 → 終態事件標記「待取回」。
 * 取結果這種慢的事交給 transcribe-worker（JTLW 要求 10 秒內回 2xx）。其他事件回 204。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/transcripts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$cfg = Settings::getTranscribe();
if (!$cfg['enabled'] || $cfg['webhook_secret'] === '') { http_response_code(404); exit; }
$raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
$h = [];
foreach ($_SERVER as $k => $v) { if (str_starts_with($k, 'HTTP_')) $h[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v; }
if (!Jtlw::verifyWebhook($raw, $h, [$cfg['webhook_secret']])) { http_response_code(401); exit; }
$ev = json_decode($raw, true);
if (!is_array($ev)) { http_response_code(400); exit; }
Transcripts::handleEvent($ev);
http_response_code(204);
