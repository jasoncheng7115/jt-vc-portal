<?php
/**
 * OIDC 回呼：驗 state（一次性）→ code + PKCE 換 token → id_token 驗簽與聲明 → 群組 → 角色 → 建立 / 同步帳號 → 登入。
 * 失敗一律顯示通用訊息、詳細原因只進稽核（不回顯 IdP 內容），並計入來源 IP 的失敗次數。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/oidc.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/settings.php';

Auth::start();
if (!Oidc::enabled()) Auth::notFound();
$ip = Auth::clientIp();

$fail = function (string $reason, string $actor = '-') use ($ip) {
  RateLimit::fail($ip);
  Audit::log('sso_fail', 'oidc: ' . $reason, ['actor' => $actor, 'result' => 'fail']);
  $_SESSION['login_error'] = t('單一登入失敗，請重新登入；若持續失敗請聯絡管理員。');
  header('Location: ' . Settings::loginUrl());
  exit;
};

if (RateLimit::isLocked($ip)) $fail('source locked');
try {
  $res = Oidc::handleCallback($_GET);
} catch (Throwable $e) {
  $fail(Oidc::safeReason($e));
}
$claims = $res['claims'];
$who = Oidc::safeText((string)($claims[Oidc::cfg()['username_claim']] ?? $claims['sub'] ?? '-'));
$role = Oidc::roleFor($res['groups']);
if ($role === null) $fail('user not in any allowed group', $who);
try {
  $user = Oidc::provision($claims, $role);
} catch (Throwable $e) {
  $fail(Oidc::safeReason($e), $who);
}
if (!empty($user['disabled'])) $fail('account disabled', $who);

RateLimit::reset($ip);
Auth::login($user);
$_SESSION['oidc_id_token'] = $res['id_token'];   // RP-initiated logout 的 id_token_hint
Audit::log('sso_login', 'oidc: ' . t('單一登入（{role}）', ['role' => $user['role']]));
header('Location: /dashboard');
exit;
