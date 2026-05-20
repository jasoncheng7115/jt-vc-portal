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

render_head('帳號管理');
render_topbar($me, $ip);
?>
<main class="container">
  <div class="nav-row">
    <a class="btn btn-ghost btn-sm" href="/dashboard"><?= icon('arrow-right', 14) ?>回儀表板</a>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <div class="card">
    <h1>帳號管理</h1>
    <p class="subtitle">管理多個主持人帳號與角色。一般主持人只看得到自己建立的會議室；管理員可看全部。</p>

    <table class="table">
      <thead><tr><th>帳號</th><th>顯示名稱</th><th>Email</th><th>角色</th><th>2FA</th><th>狀態</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($u['username']) ?></td>
          <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
          <td class="mono"><?= htmlspecialchars($u['email']) ?></td>
          <td><?= $u['role'] === 'admin' ? '管理員' : '主持人' ?></td>
          <td><?= !empty($u['totp_enabled']) ? '✓' : '—' ?></td>
          <td><?= !empty($u['disabled']) ? '<span class="badge badge-muted">停用</span>' : '<span class="badge badge-success">啟用</span>' ?></td>
          <td style="text-align:right;white-space:nowrap;">
            <button type="button" class="btn btn-ghost btn-sm" data-edit="<?= htmlspecialchars($u['id']) ?>"><?= icon('edit',14) ?>編輯</button>
            <form method="POST" action="/account-delete" style="display:inline;"
                  onsubmit="return confirm('確定刪除帳號 <?= htmlspecialchars($u['username']) ?>？');">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
              <button class="btn btn-ghost btn-sm" <?= $u['id'] === $me['id'] ? 'disabled title="不能刪除自己"' : '' ?>><?= icon('x',14) ?>刪除</button>
            </form>
          </td>
        </tr>
        <tr class="edit-row" data-editrow="<?= htmlspecialchars($u['id']) ?>" style="display:none;">
          <td colspan="7">
            <form method="POST" action="/account-save" class="inline-form">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
              <div class="field"><label>顯示名稱</label>
                <input type="text" name="display_name" value="<?= htmlspecialchars($u['display_name'] ?? '') ?>" placeholder="進會議顯示的名字">
              </div>
              <div class="field"><label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($u['email'] ?? '') ?>" placeholder="登入 / 通知用">
              </div>
              <div class="field"><label>角色</label>
                <select name="role">
                  <option value="host" <?= $u['role']==='host'?'selected':'' ?>>主持人</option>
                  <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>管理員</option>
                </select>
              </div>
              <div class="field"><label>狀態</label>
                <select name="disabled">
                  <option value="0" <?= empty($u['disabled'])?'selected':'' ?>>啟用</option>
                  <option value="1" <?= !empty($u['disabled'])?'selected':'' ?>>停用</option>
                </select>
              </div>
              <div class="field"><label>重設密碼（留空不改）</label>
                <input type="password" name="password" placeholder="新密碼" autocomplete="new-password">
              </div>
              <button class="btn btn-secondary"><?= icon('check',14) ?>儲存</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;">新增帳號</h1>
    <p class="subtitle" style="margin:6px 0 18px;">建立新的主持人或管理員帳號。</p>
    <form method="POST" action="/account-save">
      <?= Auth::csrfField() ?>
      <div class="field-row">
        <div class="field"><label for="nu">帳號名稱（登入用）</label>
          <input type="text" id="nu" name="username" required placeholder="例如 alice"></div>
        <div class="field"><label for="nd">顯示名稱（進會議顯示）</label>
          <input type="text" id="nd" name="display_name" placeholder="例如 Alice 王"></div>
      </div>
      <div class="field-row">
        <div class="field"><label for="ne">Email</label>
          <input type="email" id="ne" name="email" required placeholder="alice@example.com"></div>
        <div class="field"></div>
      </div>
      <div class="field-row">
        <div class="field"><label for="npw">密碼（至少 8 字）</label>
          <input type="password" id="npw" name="password" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label for="nr">角色</label>
          <select id="nr" name="role"><option value="host">主持人</option><option value="admin">管理員</option></select></div>
      </div>
      <button class="btn btn-primary"><?= icon('plus') ?>建立帳號</button>
    </form>
  </div>
</main>
<script>
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
