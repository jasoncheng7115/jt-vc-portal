<?php
// 單元測試用迷你 IdP（php -S 啟動）：discovery / jwks / token / userinfo。行為由 /tmp/idp-scenario.json 控制。
$sc = json_decode(@file_get_contents('/tmp/idp-scenario.json'), true) ?: [];
$base = 'http://127.0.0.1:18080';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
file_put_contents('/tmp/idp-last-' . trim(str_replace('/', '-', $path), '-') . '.json', json_encode(['headers' => getallheaders(), 'post' => $_POST, 'raw' => file_get_contents('php://input')]));
if ($path === '/realms/t/.well-known/openid-configuration') {
  echo json_encode(['issuer' => $sc['issuer'] ?? "$base/realms/t", 'authorization_endpoint' => "$base/realms/t/auth",
    'token_endpoint' => "$base/realms/t/token", 'jwks_uri' => "$base/realms/t/certs", 'userinfo_endpoint' => "$base/realms/t/userinfo",
    'end_session_endpoint' => "$base/realms/t/logout"]);
} elseif ($path === '/realms/t/certs') {
  echo json_encode(['keys' => [$sc['jwk']]]);
} elseif ($path === '/realms/t/token') {
  echo json_encode(['id_token' => $sc['id_token'], 'access_token' => 'AT-1', 'token_type' => 'Bearer']);
} elseif ($path === '/realms/t/userinfo') {
  echo json_encode($sc['userinfo'] ?? []);
} else { http_response_code(404); echo '{}'; }
