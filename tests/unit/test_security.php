<?php
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/ratelimit.php';
require_once '/app/lib/recordings.php';
require_once '/app/lib/jaas.php';
require_once '/app/lib/settings.php';

reset_data();

function jwt_payload(string $jwt): array {
  $p = explode('.', $jwt)[1];
  return json_decode(base64_decode(strtr($p, '-_', '+/')), true);
}

test('IP 限流：5 次失敗鎖定', function () {
  for ($i = 0; $i < 4; $i++) RateLimit::fail('1.2.3.4');
  ok(!RateLimit::isLocked('1.2.3.4'));
  RateLimit::fail('1.2.3.4');
  ok(RateLimit::isLocked('1.2.3.4'));
  RateLimit::reset('1.2.3.4');
  ok(!RateLimit::isLocked('1.2.3.4'));
});

test('帳號層鎖定：10 次失敗（不同 IP）鎖定；大小寫視為同一帳號；不存在帳號行為相同', function () {
  for ($i = 0; $i < 9; $i++) RateLimit::failAccount('Alice');
  ok(!RateLimit::isAccountLocked('alice'));
  RateLimit::failAccount(' ALICE ');
  ok(RateLimit::isAccountLocked('alice'));
  for ($i = 0; $i < 10; $i++) RateLimit::failAccount('nobody-xyz');
  ok(RateLimit::isAccountLocked('nobody-xyz'));
  RateLimit::resetAccount('alice');
  ok(!RateLimit::isAccountLocked('alice'));
  $raw = file_get_contents(RateLimit::FILE);
  ok(strpos($raw, 'alice') === false && strpos($raw, 'nobody') === false, '不以明文儲存登入字串');
});

test('錄影擁有權：房名被他人重新建立後，新擁有者不可調閱舊錄影', function () {
  $t = time();
  // A 的舊場次（meetings.jsonl 有記錄）
  Store::appendLine(Rooms::MEETINGS_FILE, ['room' => 'weekly', 'start' => $t - 90000, 'end' => $t - 86400, 'owner' => 'u_a', 'dur' => 3600]);
  // 無 session 記錄的更舊錄影
  $recA  = ['room' => 'weekly', 'mtime' => $t - 86400 + 10, 'duration' => 3000];
  $recA2 = ['room' => 'weekly', 'mtime' => $t - 200000, 'duration' => 600];
  // B 現在重新建立 weekly
  Rooms::upsert('weekly', ['owner' => 'u_b']);
  $recB  = ['room' => 'weekly', 'mtime' => $t + 5, 'duration' => 2];
  eq(Recordings::ownerOf($recA), 'u_a');
  eq(Recordings::ownerOf($recA2), '', '房間建立前的錄影不歸新擁有者');
  eq(Recordings::ownerOf($recB), 'u_b');
  ok(!Recordings::canAccess($recA, ['id' => 'u_b', 'role' => 'host']));
  ok(!Recordings::canAccess($recA2, ['id' => 'u_b', 'role' => 'host']));
  ok(Recordings::canAccess($recA2, ['id' => 'x', 'role' => 'admin']));
});

test('JWT：來賓關閉所有計費功能、效期 6h；主持人 12h 且可錄影（JaaS RS256）', function () {
  Settings::setSection('jaas', ['_v' => 2, 'mode' => 'jaas', 'app_id' => 'vpaas-magic-cookie-test', 'kid' => 'k1', 'domain' => '8x8.vc']);
  $g = jwt_payload(Jaas::makeJwt('r', ['name' => 'g', 'moderator' => false], Jaas::FEATURES_OFF, Jaas::GUEST_JWT_TTL));
  eq($g['context']['features']['transcription'], false);
  eq($g['context']['features']['livestreaming'], false);
  eq($g['context']['features']['recording'], false);
  ok(abs(($g['exp'] - time()) - 21600) <= 5, 'guest exp 6h');
  $h = jwt_payload(Jaas::makeJwt('r', ['name' => 'h', 'moderator' => true], ['recording' => true] + Jaas::FEATURES_OFF));
  eq($h['context']['features']['recording'], true);
  eq($h['context']['features']['transcription'], false);
  ok(abs(($h['exp'] - time()) - 43200) <= 5, 'host exp 12h');
  ok($h['nbf'] <= time());
});

test('JWT：自建 HS256 簽章可驗證', function () {
  Settings::setSection('jaas', ['_v' => 2, 'mode' => 'selfhosted', 'sh_domain' => 'meet.example.com', 'sh_app_id' => 'app', 'sh_auth' => 'jwt', 'sh_secret' => 's3cret']);
  $j = Jaas::makeJwt('r', ['name' => 'h']);
  [$h, $p, $sig] = explode('.', $j);
  eq(rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 's3cret', true)), '+/', '-_'), '='), $sig);
  eq(jwt_payload($j)['aud'], 'app');
});

test('JWT（T63）：自建主持人帶 lobby_bypass、來賓不帶（大廳房主持人重進不被擋）', function () {
  Settings::setSection('jaas', ['_v' => 2, 'mode' => 'selfhosted', 'sh_domain' => 'meet.example.com', 'sh_app_id' => 'app', 'sh_auth' => 'jwt', 'sh_secret' => 's3cret']);
  $h = jwt_payload(Jaas::makeJwt('r', ['name' => 'h', 'moderator' => true]))['context']['user'];
  eq($h['lobby_bypass'] ?? null, true, 'host');
  $g = jwt_payload(Jaas::makeJwt('r', ['name' => 'g', 'moderator' => false, 'lobby_bypass' => true], [], Jaas::GUEST_JWT_TTL))['context']['user'];
  eq(array_key_exists('lobby_bypass', $g), false, 'guest never gets lobby_bypass');
  Settings::setSection('jaas', ['_v' => 2, 'mode' => 'jaas']);
});

test('T74 小畫面接收畫質：v1.18.0 勾選的 hq_small 升級後視為「較高」；不認得的值回預設', function () {
  Settings::setSection('meeting_custom', ['hq_small' => true]);
  eq(Settings::getMeetingCustom()['small_tile_q'], 'mid', '舊的勾選 → 較高');
  Settings::setSection('meeting_custom', ['small_tile_q' => 'ultra']);
  eq(Settings::getMeetingCustom()['small_tile_q'], 'off');
  Settings::setMeetingCustom(['small_tile_q' => 'high']);
  eq(Settings::getMeetingCustom()['small_tile_q'], 'high');
  Settings::setMeetingCustom(['small_tile_q' => '<script>']);
  eq(Settings::getMeetingCustom()['small_tile_q'], 'off');
  Settings::setSection('meeting_custom', []);
});

test('Settings：並發設定不互相覆蓋；webhook secret 穩定', function () {
  $s1 = Settings::getWebhookSecret();
  run_parallel(4, 'require_once "/app/lib/settings.php"; for($i=0;$i<20;$i++){ Settings::setSection("w{$WORKER}", ["i"=>$i]); }');
  $d = Settings::load();
  for ($w = 0; $w < 4; $w++) eq($d["w$w"]['i'] ?? null, 19, "w$w");
  eq(Settings::getWebhookSecret(), $s1);
});

summary();
