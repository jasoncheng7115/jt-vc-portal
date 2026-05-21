<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/layout.php';

$me = Auth::requireLogin();
$ip = Auth::clientIp();

$msg = $_SESSION['profile_msg'] ?? '';
$err = $_SESSION['profile_err'] ?? '';
unset($_SESSION['profile_msg'], $_SESSION['profile_err']);

$setup = isset($_GET['setup']) && empty($me['totp_enabled']);
$pending_secret = null;
if ($setup) {
  // 產生一組 pending secret 存 session，待使用者輸入碼確認後才啟用
  if (empty($_SESSION['pending_totp'])) {
    $_SESSION['pending_totp'] = Totp::generateSecret();
  }
  $pending_secret = $_SESSION['pending_totp'];
  $otpauth = Totp::uri($pending_secret, $me['email'], 'JT 視訊會議');
}

render_head('個人設定');
render_topbar($me, $ip);
?>
<main class="container narrow">
  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <div class="card">
    <h1><?= icon('user', 18) ?>個人設定</h1>
    <p class="subtitle"><?= htmlspecialchars($me['username']) ?> · <?= htmlspecialchars($me['email']) ?>
      <?php if (($me['role'] ?? '') === 'admin'): ?><span class="badge badge-accent">管理員</span><?php endif; ?></p>

    <div class="section-title" style="margin-top:0;">顯示名稱</div>
    <form method="POST" action="/profile-save">
      <?= Auth::csrfField() ?>
      <div class="field">
        <label for="dn">顯示名稱（進入會議室時顯示）</label>
        <input type="text" id="dn" name="display_name" maxlength="60"
               value="<?= htmlspecialchars($me['display_name'] ?? '') ?>" placeholder="例如 Jason 鄭">
      </div>
      <button type="submit" class="btn btn-primary"><?= icon('check') ?>更新顯示名稱</button>
    </form>

    <div class="section-title">變更密碼</div>
    <form method="POST" action="/profile-save">
      <?= Auth::csrfField() ?>
      <div class="field">
        <label for="cur">目前密碼</label>
        <input type="password" id="cur" name="current_password" required autocomplete="current-password">
      </div>
      <div class="field">
        <label for="np">新密碼（至少 8 字）</label>
        <input type="password" id="np" name="new_password" required minlength="8" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="np2">確認新密碼</label>
        <input type="password" id="np2" name="new_password2" required minlength="8" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary"><?= icon('lock') ?>更新密碼</button>
    </form>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0;">雙因素認證（2FA / TOTP）</div>
    <?php if (!empty($me['totp_enabled'])): ?>
      <div class="alert alert-success"><?= icon('check') ?><span>2FA 已啟用。登入時需輸入驗證器的 6 位數碼。</span></div>
      <form method="POST" action="/twofa-setup">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="disable">
        <div class="field">
          <label for="dp">輸入目前密碼以停用 2FA</label>
          <input type="password" id="dp" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-secondary"><?= icon('x') ?>停用 2FA</button>
      </form>
    <?php elseif ($setup): ?>
      <p class="muted" style="font-size:13px;">用 Google Authenticator / Authy 掃描下方 QR Code，再輸入產生的 6 位數碼以完成綁定。</p>
      <div class="qr" id="totpQr" style="margin:14px 0;"></div>
      <p class="muted" style="font-size:12px;">或手動輸入金鑰：<span class="mono"><?= htmlspecialchars($pending_secret) ?></span></p>
      <form method="POST" action="/twofa-setup">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="enable">
        <div class="field">
          <label for="code">驗證碼</label>
          <input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9]*" maxlength="6"
                 placeholder="000000" style="letter-spacing:0.3em;text-align:center;">
        </div>
        <button type="submit" class="btn btn-primary"><?= icon('check') ?>啟用 2FA</button>
        <a class="btn btn-ghost" href="/profile">取消</a>
      </form>
      <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
      <script>
        window.addEventListener('DOMContentLoaded', () => {
          if (window.QRCode) new QRCode(document.getElementById('totpQr'), {
            text: <?= json_encode($otpauth) ?>, width: 180, height: 180,
            colorDark: '#18181b', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M
          });
        });
      </script>
    <?php else: ?>
      <p class="muted" style="font-size:13px;">尚未啟用。建議啟用以增加帳號安全性。</p>
      <a class="btn btn-primary" href="/profile?setup=1"><?= icon('lock') ?>啟用 2FA</a>
    <?php endif; ?>
  </div>
</main>
<?php render_foot(); ?>
