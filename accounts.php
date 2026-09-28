<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/layout.php';

$me = Auth::requireAdmin();
$ip = Auth::clientIp();

$msg = $_SESSION['acc_msg'] ?? '';
$err = $_SESSION['acc_err'] ?? '';
unset($_SESSION['acc_msg'], $_SESSION['acc_err']);

$users = Users::all();

render_head(t('帳號管理'));
render_topbar($me, $ip);
?>
<main class="container">
  <?= admin_nav('accounts') ?>

  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <div class="card">
    <h1><?= icon('user', 18) ?><?= th('帳號管理') ?></h1>
    <p class="subtitle"><?= th('管理多個主持人帳號與角色。一般主持人只看得到自己建立的會議室；管理員可看全部。') ?></p>

    <table class="table">
      <thead><tr><th><?= th('帳號') ?></th><th><?= th('顯示名稱') ?></th><th>Email</th><th><?= th('角色') ?></th><th>2FA</th><th><?= th('狀態') ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($u['username']) ?><?php if (Users::isSso($u)): ?> <span class="badge badge-sso" title="<?= th('單一登入（SSO）帳號') ?>">SSO</span><?php endif; ?></td>
          <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
          <td class="mono"><?= htmlspecialchars($u['email']) ?></td>
          <td><?= $u['role'] === 'admin' ? th('管理員') : th('主持人') ?><?php $tp = (string)($u['transcribe'] ?? 'none'); if (in_array($tp, ['manual', 'auto'], true)): ?> <span class="badge badge-muted" title="<?= th('逐字稿權限') ?>"><?= icon('file-text', 11) ?><?= $tp === 'auto' ? th('自動') : th('手動') ?></span><?php endif; ?></td>
          <td><?= !empty($u['totp_enabled']) ? icon('check', 16) : '—' ?></td>
          <td><?= !empty($u['disabled']) ? '<span class="badge badge-muted">' . th('停用') . '</span>' : '<span class="badge badge-success">' . th('啟用') . '</span>' ?></td>
          <td style="text-align:right;white-space:nowrap;">
            <button type="button" class="btn btn-ghost btn-sm" data-edit="<?= htmlspecialchars($u['id']) ?>"><?= icon('edit',14) ?><?= th('編輯') ?></button>
            <form method="POST" action="/account-delete" style="display:inline;"
                  data-confirm="<?= th('確定刪除帳號 {user}？', ['user' => $u['username']]) ?>">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
              <button class="btn btn-ghost btn-sm" <?= $u['id'] === $me['id'] ? 'disabled title="' . th('不能刪除自己') . '"' : '' ?>><?= icon('x',14) ?><?= th('刪除') ?></button>
            </form>
          </td>
        </tr>
        <tr class="edit-row" data-editrow="<?= htmlspecialchars($u['id']) ?>" style="display:none;">
          <td colspan="7">
            <form method="POST" action="/account-save" class="inline-form">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
              <div class="field"><label><?= th('顯示名稱') ?></label>
                <input type="text" name="display_name" value="<?= htmlspecialchars($u['display_name'] ?? '') ?>" placeholder="<?= th('進會議顯示的名字') ?>">
              </div>
              <div class="field"><label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($u['email'] ?? '') ?>" placeholder="<?= th('登入 / 通知用') ?>">
              </div>
              <div class="field"><label><?= th('角色') ?></label>
                <select name="role">
                  <option value="host" <?= $u['role']==='host'?'selected':'' ?>><?= th('主持人') ?></option>
                  <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>><?= th('管理員') ?></option>
                </select>
              </div>
              <div class="field"><label><?= th('逐字稿權限') ?></label>
                <?php $tp = (string)($u['transcribe'] ?? 'none'); ?>
                <select name="transcribe">
                  <option value="none" <?= $tp === 'none' || !in_array($tp, ['manual', 'auto'], true) ? 'selected' : '' ?>><?= th('不可使用') ?></option>
                  <option value="manual" <?= $tp === 'manual' ? 'selected' : '' ?>><?= th('手動（自己的場次可按「產生逐字稿」）') ?></option>
                  <option value="auto" <?= $tp === 'auto' ? 'selected' : '' ?>><?= th('自動（錄影完成後自動產生）') ?></option>
                </select>
              </div>
              <div class="field"><label><?= th('狀態') ?></label>
                <select name="disabled">
                  <option value="0" <?= empty($u['disabled'])?'selected':'' ?>><?= th('啟用') ?></option>
                  <option value="1" <?= !empty($u['disabled'])?'selected':'' ?>><?= th('停用') ?></option>
                </select>
              </div>
              <?php if (!Users::isSso($u)): ?>
              <div class="field"><label><?= th('重設密碼（留空不改，至少 10 字）') ?></label>
                <input type="password" name="password" placeholder="<?= th('新密碼') ?>" minlength="10" autocomplete="new-password">
              </div>
              <?php endif; ?>
              <div class="field"><label style="font-weight:400;"><input type="checkbox" name="revoke" value="1"> <?= th('強制登出此帳號所有已登入的裝置') ?></label></div>
              <button class="btn btn-secondary"><?= icon('check',14) ?><?= th('儲存') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('plus', 18) ?><?= th('新增帳號') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('建立新的主持人或管理員帳號。') ?></p>
    <form method="POST" action="/account-save">
      <?= Auth::csrfField() ?>
      <div class="field-row">
        <div class="field"><label for="nu"><?= th('帳號名稱（登入用）') ?></label>
          <input type="text" id="nu" name="username" required placeholder="<?= th('例如 alice') ?>"></div>
        <div class="field"><label for="nd"><?= th('顯示名稱（進會議顯示）') ?></label>
          <input type="text" id="nd" name="display_name" placeholder="<?= th('例如 Alice 王') ?>"></div>
      </div>
      <div class="field-row">
        <div class="field"><label for="ne">Email</label>
          <input type="email" id="ne" name="email" required placeholder="alice@example.com"></div>
        <div class="field"></div>
      </div>
      <div class="field-row">
        <div class="field"><label for="npw"><?= th('密碼（至少 10 字）') ?></label>
          <input type="password" id="npw" name="password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label for="nr"><?= th('角色') ?></label>
          <select id="nr" name="role"><option value="host"><?= th('主持人') ?></option><option value="admin"><?= th('管理員') ?></option></select></div>
      </div>
      <button class="btn btn-primary"><?= icon('plus') ?><?= th('建立帳號') ?></button>
    </form>
  </div>
</main>
<script <?= nonce_attr() ?>>
  document.querySelectorAll('[data-edit]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-edit');
      var row = document.querySelector('[data-editrow="' + id + '"]');
      if (!row) return;
      row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
    });
  });
</script>
<?php render_foot(); ?>
