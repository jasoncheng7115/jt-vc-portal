<?php
// 套主題的錯誤頁：由 .htaccess ErrorDocument 導入（Apache 層級的 403/404/500…）。
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/layout.php';

$code = (int)($_GET['code'] ?? $_SERVER['REDIRECT_STATUS'] ?? 0);
if ($code < 400 || $code > 599) $code = 404;

$map = [
  400 => '請求有誤',
  401 => '需要授權',
  403 => '禁止存取',
  404 => '找不到頁面',
  405 => '方法不被允許',
  408 => '請求逾時',
  413 => '內容過大',
  429 => '請求過於頻繁',
  500 => '伺服器發生錯誤',
  502 => '上游服務無回應',
  503 => '服務暫時無法使用',
  504 => '上游服務逾時',
];
$msg = $map[$code] ?? '發生錯誤';

http_response_code($code);
render_head((string)$code);
render_topbar(false);
?>
<main class="container narrow">
  <div class="card" style="text-align:center;padding:48px 24px;">
    <div style="font-size:64px;font-weight:700;line-height:1;"><?= (int)$code ?></div>
    <p class="subtitle" style="margin-top:12px;"><?= htmlspecialchars($msg) ?></p>
    <a class="btn btn-secondary btn-sm" href="/" style="margin-top:18px;display:inline-flex;"><?= icon('home', 14) ?>回首頁</a>
  </div>
</main>
<?php render_foot(); ?>
