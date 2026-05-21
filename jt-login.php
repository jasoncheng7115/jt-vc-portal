<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/layout.php';

// 登入頁路由偽裝：只有「設定的登入路徑」才顯示登入頁。
// 預設 /jt-login 被改掉後直接 404；其他未對應路徑（經 .htaccess 轉來此檔）也一律 404。
$__req_path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($__req_path !== Settings::getLoginPath()) {
  Auth::notFound();
}

Auth::start();
Users::bootstrap(); // 首次自動建立初始管理員

if (Auth::check()) {
  header('Location: /dashboard');
  exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$ip = Auth::clientIp();
$st = RateLimit::status($ip);

render_head('登入');
render_topbar(false);
?>
<main class="container narrow">
  <div class="card">
    <h1>主持人登入</h1>
    <p class="subtitle">使用授權帳號建立並進入 8x8（Jitsi）會議室。</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <?php if ($st['locked']): ?>
      <div class="alert alert-warning">
        <?= icon('lock') ?>
        <span>因多次登入失敗，此來源已被鎖定，請於
          <strong><?= date('H:i', $st['until']) ?></strong> 後再試。</span>
      </div>
      <form method="POST" action="/verify" autocomplete="off">
        <div class="field">
          <label>Email</label>
          <input type="email" disabled placeholder="已鎖定">
        </div>
        <div class="field">
          <label>密碼</label>
          <input type="password" disabled placeholder="已鎖定">
        </div>
        <button type="submit" class="btn btn-primary btn-block" disabled><?= icon('lock') ?>已鎖定</button>
      </form>
    <?php else: ?>
      <?php if ($st['remaining'] < RateLimit::MAX_FAILS): ?>
        <div class="alert alert-warning"><?= icon('info') ?>
          <span>登入失敗，剩餘嘗試次數 <strong><?= (int)$st['remaining'] ?></strong> 次。</span></div>
      <?php endif; ?>
      <form method="POST" action="/verify" autocomplete="off">
        <?= Auth::csrfField() ?>
        <div class="field">
          <label for="email">Email 或帳號</label>
          <input type="text" id="email" name="email" required autofocus
                 placeholder="you@example.com" autocomplete="username">
        </div>
        <div class="field">
          <label for="password">密碼</label>
          <input type="password" id="password" name="password" required
                 placeholder="••••••••" autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block"><?= icon('login') ?>登入</button>
      </form>
    <?php endif; ?>
  </div>
  <p class="muted" style="text-align:center;margin-top:18px;font-size:13px;">
    若您是受邀的來賓，請使用您收到的邀請連結進入會議室。
  </p>
</main>
<?php render_foot(); ?>
