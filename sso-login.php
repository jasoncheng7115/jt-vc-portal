<?php
/**
 * 開始 OIDC 登入：產生 state / nonce / PKCE，導向 IdP。
 * 只有啟用 SSO 時可用；已登入者直接回儀表板。受 IP 鎖定保護（被鎖的來源不能再發起）。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/oidc.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/settings.php';

Auth::start();
if (!Oidc::enabled()) Auth::notFound();
if (Auth::check()) { header('Location: /dashboard'); exit; }
if (RateLimit::isLocked(Auth::clientIp())) {
  $_SESSION['login_error'] = t('因多次登入失敗，此來源已被暫時鎖定，請稍後再試。');
  header('Location: ' . Settings::loginUrl());
  exit;
}
try {
  $url = Oidc::authorizeUrl();
} catch (Throwable $e) {
  Audit::log('sso_fail', 'oidc: ' . Oidc::safeReason($e), ['actor' => '-', 'result' => 'fail']);
  $_SESSION['login_error'] = t('無法連線到單一登入服務，請稍後再試或聯絡管理員。');
  header('Location: ' . Settings::loginUrl());
  exit;
}
header('Cache-Control: no-store');
header('Location: ' . $url);
exit;
