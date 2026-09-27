<?php
/**
 * OIDC 單元測試（SSO 項目 ↔ 測試對照見 TEST_CHECKLIST 第 10 節）。
 * 在測試內產生 RSA 金鑰、自行簽發 / 偽造 id_token，涵蓋所有驗證失敗情境。
 */
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/oidc.php';

reset_data();
Auth::start();

// ---- 測試用金鑰與 JWT 工具 ----
$priv = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$det = openssl_pkey_get_details($priv);
$b64u = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$JWK = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64u($det['rsa']['n']), 'e' => $b64u($det['rsa']['e'])];
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
function sign_jwt(array $payload, $key, array $hdr = []): string {
  global $b64u;
  $h = $b64u(json_encode($hdr + ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1']));
  $p = $b64u(json_encode($payload));
  openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
  return "$h.$p." . $b64u($sig);
}
$ISS = 'https://idp.example.com/realms/jtvc';
$claims = fn(array $o = []) => $o + ['iss' => $ISS, 'aud' => 'jt-vc-portal', 'azp' => 'jt-vc-portal', 'sub' => 'user-1',
  'exp' => time() + 300, 'iat' => time(), 'nonce' => 'N1', 'preferred_username' => 'alice', 'email' => 'alice@example.com', 'name' => 'Alice'];
function rejects(callable $fn, string $needle): void {
  try { $fn(); } catch (OidcError $e) { ok(stripos($e->getMessage(), $needle) !== false, "錯誤訊息應含「{$needle}」，實際：" . $e->getMessage()); return; }
  throw new RuntimeException("應該被拒絕（{$needle}）但通過了");
}

test('S08 JWK → PEM 可驗證正確簽章', function () use ($JWK, $priv, $claims) {
  $p = Oidc::verifyJwt(sign_jwt($claims(), $priv), [$JWK]);
  eq($p['sub'], 'user-1');
});

test('S08 偽造：他人金鑰簽章 → 拒絕', function () use ($JWK, $other, $claims) {
  rejects(fn() => Oidc::verifyJwt(sign_jwt($claims(), $other), [$JWK]), 'signature');
});

test('S08 竄改 payload → 拒絕', function () use ($JWK, $priv, $claims) {
  [$h, $p, $s] = explode('.', sign_jwt($claims(), $priv));
  $p2 = rtrim(strtr(base64_encode(json_encode($claims(['sub' => 'admin']))), '+/', '-_'), '=');
  rejects(fn() => Oidc::verifyJwt("$h.$p2.$s", [$JWK]), 'signature');
});

test('S08 alg=none → 拒絕', function () use ($JWK, $claims, $b64u) {
  $t = $b64u(json_encode(['alg' => 'none', 'typ' => 'JWT'])) . '.' . $b64u(json_encode($claims())) . '.';
  rejects(fn() => Oidc::verifyJwt($t, [$JWK]), 'alg');
});

test('S08 alg 混淆：HS256 以公鑰當 HMAC 密鑰 → 拒絕', function () use ($JWK, $claims, $b64u) {
  $h = $b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'k1'])); $p = $b64u(json_encode($claims()));
  $t = "$h.$p." . $b64u(hash_hmac('sha256', "$h.$p", Oidc::jwkToPem($JWK), true));
  rejects(fn() => Oidc::verifyJwt($t, [$JWK]), 'alg');
});

test('S08 未知 kid：觸發重新抓 JWKS 後可驗證；仍找不到 → 拒絕', function () use ($JWK, $priv, $claims) {
  $t = sign_jwt($claims(), $priv, ['kid' => 'k2']);
  $k2 = ['kid' => 'k2'] + $JWK;
  eq(Oidc::verifyJwt($t, [$JWK], fn() => [$k2])['sub'], 'user-1');
  rejects(fn() => Oidc::verifyJwt($t, [$JWK], fn() => [$JWK]), 'key');
});

test('S08 JWT 格式錯誤 → 拒絕', function () use ($JWK) {
  rejects(fn() => Oidc::verifyJwt('abc.def', [$JWK]), 'Malformed');
});

test('S09 聲明驗證：正確通過', function () use ($claims, $ISS) {
  Oidc::validateClaims($claims(), $ISS, 'jt-vc-portal', 'N1');
  ok(true);
});

test('S09 iss / aud / azp / exp / iat / nonce / sub 錯誤皆拒絕', function () use ($claims, $ISS) {
  rejects(fn() => Oidc::validateClaims($claims(['iss' => 'https://evil.example.com/realms/jtvc']), $ISS, 'jt-vc-portal', 'N1'), 'iss');
  rejects(fn() => Oidc::validateClaims($claims(['aud' => 'other-app', 'azp' => 'other-app']), $ISS, 'jt-vc-portal', 'N1'), 'aud');
  rejects(fn() => Oidc::validateClaims($claims(['aud' => ['jt-vc-portal', 'x'], 'azp' => 'x']), $ISS, 'jt-vc-portal', 'N1'), 'azp');
  rejects(fn() => Oidc::validateClaims($claims(['exp' => time() - 3600]), $ISS, 'jt-vc-portal', 'N1'), 'expired');
  rejects(fn() => Oidc::validateClaims($claims(['iat' => time() + 3600]), $ISS, 'jt-vc-portal', 'N1'), 'future');
  rejects(fn() => Oidc::validateClaims($claims(['nonce' => 'OTHER']), $ISS, 'jt-vc-portal', 'N1'), 'nonce');
  $c = $claims(); unset($c['nonce']);
  rejects(fn() => Oidc::validateClaims($c, $ISS, 'jt-vc-portal', 'N1'), 'nonce');
  rejects(fn() => Oidc::validateClaims($claims(['sub' => '']), $ISS, 'jt-vc-portal', 'N1'), 'sub');
});

test('S02/S03/S04 URL 檢查：https 必須、擋 metadata 主機、不可帶帳密', function () {
  Settings::setOidc(['enabled' => true, 'issuer' => 'https://idp.example.com/realms/jtvc', 'client_id' => 'jt-vc-portal', 'require_https' => true, 'host_groups' => 'VC-Hosts']);
  rejects(fn() => Oidc::checkUrl('http://idp.example.com/x'), 'https');
  rejects(fn() => Oidc::checkUrl('https://169.254.169.254/latest'), 'not allowed');
  rejects(fn() => Oidc::checkUrl('https://metadata.google.internal/x'), 'not allowed');
  rejects(fn() => Oidc::checkUrl('https://u:p@idp.example.com/x'), 'credentials');
  Oidc::checkUrl('https://idp.example.com/realms/jtvc');
  Settings::setOidc(['enabled' => true, 'issuer' => 'http://idp.local/realms/jtvc', 'client_id' => 'jt-vc-portal', 'require_https' => false, 'host_groups' => 'VC-Hosts']);
  Oidc::checkUrl('http://idp.local/x');   // 關閉 require_https 時允許（內網 IdP）
  ok(true);
});

test('S05 授權網址：PKCE S256、state、nonce、固定 redirect_uri、scope 含 openid', function () {
  Settings::setOidc(['enabled' => true, 'issuer' => 'https://idp.example.com/realms/jtvc', 'client_id' => 'jt-vc-portal', 'host_groups' => 'VC-Hosts']);
  Store::write(Oidc::CACHE_FILE, ['issuer' => 'https://idp.example.com/realms/jtvc', 'disc_at' => time(), 'disc' => [
    'issuer' => 'https://idp.example.com/realms/jtvc', 'authorization_endpoint' => 'https://idp.example.com/auth',
    'token_endpoint' => 'https://idp.example.com/token', 'jwks_uri' => 'https://idp.example.com/certs']]);
  $u = Oidc::authorizeUrl();
  parse_str(parse_url($u, PHP_URL_QUERY), $q);
  $p = $_SESSION['oidc_pending'];
  eq($q['code_challenge_method'], 'S256');
  eq($q['code_challenge'], Oidc::pkceChallenge($p['verifier']));
  eq($q['state'], $p['state']);
  eq($q['nonce'], $p['nonce']);
  eq($q['redirect_uri'], rtrim(SITE_URL, '/') . '/sso-callback');
  ok(preg_match('/(^| )openid( |$)/', $q['scope']) === 1);
  ok(strlen($p['state']) >= 32 && strlen($p['verifier']) >= 43, 'state / verifier 長度');
});

test('S06/S07 state：不符、無交易、過期、重放皆拒絕（一次性）', function () {
  $_SESSION['oidc_pending'] = ['state' => 'S1', 'nonce' => 'N1', 'verifier' => 'v', 'ts' => time()];
  rejects(fn() => Oidc::handleCallback(['state' => 'WRONG', 'code' => 'c']), 'state');
  ok(!isset($_SESSION['oidc_pending']), '失敗後交易即作廢');
  rejects(fn() => Oidc::handleCallback(['state' => 'S1', 'code' => 'c']), 'expired');   // 重放（交易已不存在）
  $_SESSION['oidc_pending'] = ['state' => 'S1', 'nonce' => 'N1', 'verifier' => 'v', 'ts' => time() - 601];
  rejects(fn() => Oidc::handleCallback(['state' => 'S1', 'code' => 'c']), 'expired');
  $_SESSION['oidc_pending'] = ['state' => 'S1', 'nonce' => 'N1', 'verifier' => 'v', 'ts' => time()];
  rejects(fn() => Oidc::handleCallback(['error' => "access_denied\r\nfake", 'state' => 'S1']), 'IdP error');
  $_SESSION['oidc_pending'] = ['state' => 'S1', 'nonce' => 'N1', 'verifier' => 'v', 'ts' => time()];
  rejects(fn() => Oidc::handleCallback(['state' => 'S1']), 'code');
});

test('S11 群組 → 角色：admin 優先、host、都不符 → null；不分大小寫、Keycloak 路徑', function () {
  $c = ['admin_groups' => 'VC-Admins', 'host_groups' => 'VC-Hosts, Teachers'];
  eq(Oidc::roleFor(['VC-Hosts', 'vc-admins'], $c), 'admin');
  eq(Oidc::roleFor(['/Org/VC-Hosts'], $c), 'host');
  eq(Oidc::roleFor(['teachers'], $c), 'host');
  eq(Oidc::roleFor(['Staff'], $c), null);
  eq(Oidc::roleFor([], $c), null);
  eq(Oidc::roleFor(['/A/B'], ['admin_groups' => 'A/B', 'host_groups' => '']), 'admin');
  eq(Oidc::groupsFrom(['groups' => 'a, b'], 'groups'), ['a', 'b']);
  eq(Oidc::groupsFrom(['x' => 1], 'groups'), null);
});

test('S12 帳號佈建：以 (iss, sub) 建立 / 同步；衝突與停用拒絕；最後一位管理員不降級', function () use ($claims, $ISS) {
  reset_data();
  Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'jt-vc-portal', 'host_groups' => 'VC-Hosts', 'admin_groups' => 'VC-Admins']);
  $u = Oidc::provision($claims(), 'host');
  ok(Users::isSso($u)); eq($u['username'], 'alice'); eq($u['role'], 'host'); eq($u['oidc_sub'], 'user-1');
  $u2 = Oidc::provision($claims(['name' => 'Alice W']), 'admin');
  eq($u2['id'], $u['id'], '同一 sub → 同一帳號'); eq($u2['display_name'], 'Alice W'); eq($u2['role'], 'admin');
  // 最後一位啟用中的管理員不因群組同步被降級
  eq(Oidc::provision($claims(), 'host')['role'], 'admin');
  // 帳號名稱與本地帳號衝突 → 拒絕（不自動併入）
  Users::create(['username' => 'bob', 'email' => 'bob@example.com', 'password' => 'Local-Pass-12345', 'role' => 'host']);
  rejects(fn() => Oidc::provision($claims(['sub' => 'user-2', 'preferred_username' => 'bob', 'email' => 'b2@example.com']), 'host'), 'conflict');
  // email 與既有帳號衝突 → 拒絕
  rejects(fn() => Oidc::provision($claims(['sub' => 'user-3', 'preferred_username' => 'carol', 'email' => 'bob@example.com']), 'host'), 'conflict');
  // 另一 IdP（不同 iss）同 sub 不會對到既有帳號
  rejects(fn() => Oidc::provision($claims(['iss' => 'https://other.example.com/r', 'preferred_username' => 'alice']), 'host'), 'conflict');
  // 停用 → 拒絕
  Users::update($u['id'], ['disabled' => true]);
  rejects(fn() => Oidc::provision($claims(), 'host'), 'disabled');
});

test('S13 SSO 帳號不能以密碼登入、也不能被設定本地密碼', function () use ($ISS) {
  reset_data();
  $u = Users::createSso(['username' => 'sso1', 'display_name' => 'S', 'email' => '', 'role' => 'host', 'oidc_iss' => $ISS, 'oidc_sub' => 's1']);
  ok(!Users::verifyPassword($u, ''));
  $u = Users::update($u['id'], ['password' => 'Try-To-Set-12345']);
  ok(!Users::verifyPassword($u, 'Try-To-Set-12345'), '管理員重設也無效');
});

test('S14 僅限單一登入：本地登入只允許指定 IP；清單空白＝只允許本機；未啟用時一律允許', function () use ($ISS) {
  Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'c', 'host_groups' => 'g', 'sso_only' => false]);
  ok(Oidc::localLoginAllowed('8.8.8.8'), '非僅限 SSO → 允許');
  Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'c', 'host_groups' => 'g', 'sso_only' => true, 'local_login_cidrs' => '192.168.1.0/24, 10.8.0.5']);
  ok(Oidc::localLoginAllowed('192.168.1.77'));
  ok(Oidc::localLoginAllowed('10.8.0.5'));
  ok(!Oidc::localLoginAllowed('8.8.8.8'));
  Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'c', 'host_groups' => 'g', 'sso_only' => true, 'local_login_cidrs' => '']);
  ok(Oidc::localLoginAllowed('127.0.0.1'));
  ok(!Oidc::localLoginAllowed('192.168.1.77'));
  Settings::setOidc(['enabled' => false, 'issuer' => $ISS, 'client_id' => 'c', 'sso_only' => true]);
  ok(Oidc::localLoginAllowed('8.8.8.8'), 'SSO 未啟用時僅限 SSO 不生效');
});

test('S16 登出網址：end_session + id_token_hint + post_logout_redirect_uri', function () use ($ISS) {
  Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'jt-vc-portal', 'host_groups' => 'g']);
  Store::write(Oidc::CACHE_FILE, ['issuer' => $ISS, 'disc_at' => time(), 'disc' => ['issuer' => $ISS,
    'authorization_endpoint' => "$ISS/auth", 'token_endpoint' => "$ISS/token", 'jwks_uri' => "$ISS/certs", 'end_session_endpoint' => "$ISS/logout"]]);
  $u = Oidc::logoutUrl('TOKEN123');
  parse_str(parse_url($u, PHP_URL_QUERY), $q);
  ok(str_starts_with($u, "$ISS/logout?"));
  eq($q['id_token_hint'], 'TOKEN123');
  eq($q['post_logout_redirect_uri'], rtrim(SITE_URL, '/') . '/');
  eq(Oidc::origin(), 'https://idp.example.com');
});

test('S18 稽核內容安全：去控制字元、限長', function () {
  eq(Oidc::safeText("a\r\nb\x00c"), 'a b c');
  eq(mb_strlen(Oidc::safeText(str_repeat('x', 500))), 120);
  eq(Oidc::safeReason(new RuntimeException('secret detail')), 'RuntimeException');
});

test('S20 設定：client_secret 留空沿用、列入匯出白名單', function () use ($ISS) {
  Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'c', 'client_secret' => 'S3CRET', 'host_groups' => 'g']);
  Settings::setOidc(['enabled' => true, 'issuer' => $ISS, 'client_id' => 'c', 'client_secret' => '', 'host_groups' => 'g']);
  eq(Settings::getOidc()['client_secret'], 'S3CRET');
  ok(in_array('oidc', Settings::EXPORTABLE_KEYS, true));
  eq(Settings::getOidc()['scopes'], 'openid email profile');
});

summary();
