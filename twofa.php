<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/layout.php';

Auth::start();

// 必須先通過密碼驗證（verify.php 設了 2fa_uid）
if (empty($_SESSION['2fa_uid'])) {
  header('Location: ' . Settings::loginUrl());
  exit;
}

$error = $_SESSION['2fa_error'] ?? '';
unset($_SESSION['2fa_error']);

render_head('雙因素認證');
render_topbar(false);
?>
<main class="container narrow">
  <div class="card">
    <h1><?= icon('lock', 18) ?>雙因素認證</h1>
    <p class="subtitle">請輸入驗證器 App（Google Authenticator / Authy）顯示的 6 位數驗證碼。</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" action="/twofa-verify" autocomplete="off">
      <?= Auth::csrfField() ?>
      <div class="field">
        <label for="code">驗證碼</label>
        <input type="text" id="code" name="code" required autofocus inputmode="numeric"
               pattern="[0-9]*" maxlength="6" placeholder="000000"
               style="letter-spacing:0.3em;text-align:center;font-size:20px;">
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= icon('check') ?>驗證</button>
    </form>
    <p style="text-align:center;margin-top:14px;">
      <a href="/logout" class="muted" style="font-size:13px;">取消並重新登入</a>
    </p>
  </div>
</main>
<?php render_foot(); ?>
