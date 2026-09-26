<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

Auth::start();
if (Auth::check()) {
  header('Location: /dashboard');
  exit;
}

render_head(t('首頁'));
render_topbar(false);
?>
<main class="container narrow">
  <div class="card" style="text-align:center;">
    <div class="logo-tile">
      <img src="/assets/icon.svg" alt="jt-vc-portal" width="40" height="40" style="display:block;">
    </div>
    <h1><?= htmlspecialchars(Settings::getSite()['brand_name']) ?></h1>
    <p class="subtitle"><?= th('請使用主持人提供的邀請連結進入會議室。') ?></p>
    <p class="muted" style="font-size:13px;margin-top:6px;"><?= th('若邀請連結尚未開啟，可稍後再試一次。') ?></p>
  </div>
</main>
<?php render_foot(); ?>
