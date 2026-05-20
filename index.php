<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

Auth::start();
if (Auth::check()) {
  header('Location: /dashboard');
  exit;
}

render_head('首頁');
render_topbar(false);
?>
<main class="container narrow">
  <div class="card" style="text-align:center;">
    <div class="logo-tile">
      <img src="/assets/logo-64.png" alt="JT" width="40" height="40" style="display:block;">
    </div>
    <h1>JT 視訊會議</h1>
    <p class="subtitle">請使用主持人提供的邀請連結進入會議室。</p>
    <p class="muted" style="font-size:13px;margin-top:6px;">若邀請連結尚未開啟，可稍後再試一次。</p>
  </div>
</main>
<?php render_foot(); ?>
