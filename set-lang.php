<?php
/**
 * 切換介面語言：/set-lang?l=en&r=/目前頁面（不可叫 /lang：會與字典目錄 lang/ 衝突）
 *   - 寫 cookie（一年）；已登入者同步存到個人設定（users.json 的 lang）
 *   - r 只接受站內相對路徑（防開放式重新導向）
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';

$l = I18n::normalize((string)($_GET['l'] ?? ''));
$r = (string)($_GET['r'] ?? '/');
$r = str_replace(["\r", "\n", "\0"], '', $r);
if ($r === '' || $r[0] !== '/' || strpos($r, '//') === 0 || strpos($r, '\\') !== false) $r = '/';
// 去掉目標網址上的 ?lang=，避免覆蓋剛選的語言
$r = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $r);
$r = rtrim($r, '?&');
if ($r === '') $r = '/';

if ($l !== null) {
  I18n::remember($l);
  Auth::start();
  $u = Auth::user();
  if ($u) {
    Users::update($u['id'], ['lang' => $l]);
    $_SESSION['lang'] = $l;
  }
}
header('Cache-Control: no-store');
header('Location: ' . $r);
exit;
