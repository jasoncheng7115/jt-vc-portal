<?php
/**
 * 8x8 JaaS USAGE webhook 接收端點。
 *   POST https://your-domain.example/webhooks/usage
 *
 * 8x8 文件： https://developer.8x8.com/jaas/docs/webhooks-signatures/
 *   Header  : X-Jaas-Signature: t=<unix_seconds>,v1=<base64(HMAC_SHA256(secret, "<t>.<body>"))>
 *   Payload : JSON，eventType = "USAGE"
 *
 * 流程：
 *   1. 取 header / body
 *   2. 解析 t= 與 v1=（允許多個 v1=）
 *   3. timestamp 不可超過 ±5 分鐘（replay 保護）
 *   4. 重算 HMAC SHA256，用 hash_equals 常數時間比對
 *   5. 通過 → 解析 payload → Usage::processEvent()
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/usage.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'method not allowed';
  exit;
}

$body = file_get_contents('php://input');
if ($body === false) {
  http_response_code(400);
  echo 'no body';
  exit;
}
$secret = Settings::getWebhookSecret();

/*
 * 8x8 JaaS Console 的「Add webhook endpoint」提供 "Authorization header" 欄位，
 * 設定後每次推送會帶 `Authorization: <值>`。這是實務上的驗證方式。
 * 同時保留 X-Jaas-Signature HMAC 驗證（若未來改用簽章機制）。
 * 兩者擇一通過即可。
 */
$ok = false;

// 方式 A：Authorization header == secret（constant-time）
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authHeader !== '') {
  // 容忍 "Bearer <secret>" 或純值
  $val = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
  if (hash_equals($secret, $val)) $ok = true;
}

// 方式 B：X-Jaas-Signature HMAC（t=...,v1=...）
if (!$ok) {
  $sig_header = $_SERVER['HTTP_X_JAAS_SIGNATURE'] ?? '';
  if ($sig_header !== '') {
    $timestamp = null; $received_sigs = [];
    foreach (explode(',', $sig_header) as $part) {
      $kv = explode('=', trim($part), 2);
      if (count($kv) !== 2) continue;
      if ($kv[0] === 't') $timestamp = $kv[1];
      elseif ($kv[0] === 'v1') $received_sigs[] = $kv[1];
    }
    if ($timestamp !== null && ctype_digit($timestamp) && abs(time() - (int)$timestamp) <= 300) {
      $expected = base64_encode(hash_hmac('sha256', $timestamp . '.' . $body, $secret, true));
      foreach ($received_sigs as $sig) {
        if (hash_equals($expected, $sig)) { $ok = true; break; }
      }
    }
  }
}

if (!$ok) {
  http_response_code(403);
  echo 'unauthorized';
  exit;
}

// 4) 解析 JSON
$payload = json_decode($body, true);
if (!is_array($payload)) {
  http_response_code(400);
  echo 'invalid json';
  exit;
}

$evt = $payload['eventType'] ?? '';
if ($evt !== 'USAGE') {
  // 不是 USAGE 事件就回 200 略過（後續想加 SPEAKER_STATS 等再說）
  http_response_code(200);
  echo 'ignored: ' . $evt;
  exit;
}

$res = Usage::processEvent($payload);
http_response_code(200);
echo json_encode($res);
