<?php
/** OIDC 完整回呼流程（迷你 IdP）：token 交換格式、PKCE verifier、userinfo 群組備援與 sub 一致性（S10）。 */
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/oidc.php';
reset_data();
Auth::start();

$srv = proc_open(['php', '-S', '127.0.0.1:18080', __DIR__ . '/stub/idp.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p);
usleep(400000);
register_shutdown_function(fn() => proc_terminate($srv));

$priv = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$det = openssl_pkey_get_details($priv);
$b64u = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64u($det['rsa']['n']), 'e' => $b64u($det['rsa']['e'])];
$ISS = 'http://127.0.0.1:18080/realms/t';
$sign = function (array $c) use ($priv, $b64u) {
  $h = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1'])); $pl = $b64u(json_encode($c));
  openssl_sign("$h.$pl", $s, $priv, OPENSSL_ALGO_SHA256); return "$h.$pl." . $b64u($s);
};
Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'jt-vc-portal', 'client_secret' => 'se&cr=t+', 'require_https' => false,
  'admin_groups' => 'VC-Admins', 'host_groups' => 'VC-Hosts']);
function scenario(array $s) { file_put_contents('/tmp/idp-scenario.json', json_encode($s)); @unlink(Oidc::CACHE_FILE); }
function begin(): array { $u = Oidc::authorizeUrl(); parse_str(parse_url($u, PHP_URL_QUERY), $q); return $q; }
$claims = fn(array $o = []) => $o + ['iss' => 'http://127.0.0.1:18080/realms/t', 'aud' => 'jt-vc-portal', 'azp' => 'jt-vc-portal', 'sub' => 'u1', 'exp' => time() + 300, 'iat' => time()];

test('S05/S08 完整回呼：token 交換帶 PKCE verifier 與 client 認證（特殊字元正確編碼）', function () use ($jwk, $sign, $claims) {
  $q = begin(); $verifier = $_SESSION['oidc_pending']['verifier'];
  scenario(['jwk' => $jwk, 'id_token' => $sign($claims(['nonce' => $q['nonce'], 'groups' => ['VC-Hosts']]))]);
  $r = Oidc::handleCallback(['state' => $q['state'], 'code' => 'CODE-1']);
  eq($r['claims']['sub'], 'u1'); eq($r['groups'], ['VC-Hosts']);
  $last = json_decode(file_get_contents('/tmp/idp-last-realms-t-token.json'), true);
  eq($last['post']['code_verifier'] ?? '', $verifier, 'code_verifier');
  eq($last['post']['code'] ?? '', 'CODE-1');
  eq($last['post']['grant_type'] ?? '', 'authorization_code');
  eq($last['post']['redirect_uri'] ?? '', rtrim(SITE_URL, '/') . '/sso-callback');
  $auth = $last['headers']['Authorization'] ?? '';
  eq(base64_decode(substr($auth, 6)), 'jt-vc-portal:' . rawurlencode('se&cr=t+'), 'client_secret_basic');
});

test('S10 id_token 沒有 groups → 以 userinfo 補（sub 一致時）', function () use ($jwk, $sign, $claims) {
  $q = begin();
  scenario(['jwk' => $jwk, 'id_token' => $sign($claims(['nonce' => $q['nonce']])), 'userinfo' => ['sub' => 'u1', 'groups' => ['VC-Admins']]]);
  eq(Oidc::handleCallback(['state' => $q['state'], 'code' => 'c'])['groups'], ['VC-Admins']);
});

test('S10 userinfo 的 sub 與 id_token 不同 → 拒絕', function () use ($jwk, $sign, $claims) {
  $q = begin();
  scenario(['jwk' => $jwk, 'id_token' => $sign($claims(['nonce' => $q['nonce']])), 'userinfo' => ['sub' => 'attacker', 'groups' => ['VC-Admins']]]);
  try { Oidc::handleCallback(['state' => $q['state'], 'code' => 'c']); throw new RuntimeException('should reject'); }
  catch (OidcError $e) { ok(str_contains($e->getMessage(), 'userinfo sub mismatch'), $e->getMessage()); }
});

test('S02 discovery 的 issuer 與設定不符 → 拒絕', function () use ($jwk) {
  scenario(['jwk' => $jwk, 'issuer' => 'http://127.0.0.1:18080/realms/evil']);
  try { Oidc::discovery(true); throw new RuntimeException('should reject'); }
  catch (OidcError $e) { ok(str_contains($e->getMessage(), 'issuer mismatch'), $e->getMessage()); }
});

test('S08 驗簽失敗時不退回 userinfo（fail-closed）', function () use ($jwk, $sign, $claims) {
  scenario(['jwk' => $jwk]);   // 還原正常 discovery（前一個測試故意設了錯的 issuer）
  $q = begin();
  $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
  $h = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'kid' => 'k1'])), '+/', '-_'), '=');
  $pl = rtrim(strtr(base64_encode(json_encode($claims(['nonce' => $q['nonce']]))), '+/', '-_'), '=');
  openssl_sign("$h.$pl", $s, $other, OPENSSL_ALGO_SHA256);
  scenario(['jwk' => $jwk, 'id_token' => "$h.$pl." . rtrim(strtr(base64_encode($s), '+/', '-_'), '='), 'userinfo' => ['sub' => 'u1', 'groups' => ['VC-Admins']]]);
  try { Oidc::handleCallback(['state' => $q['state'], 'code' => 'c']); throw new RuntimeException('should reject'); }
  catch (OidcError $e) { ok(str_contains($e->getMessage(), 'signature'), $e->getMessage()); }
});

summary();
