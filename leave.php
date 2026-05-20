<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/rooms.php';

Auth::start();
$me = Auth::user();
$was_logged_in = (bool)$me;
$leaving_room = $_SESSION['room'] ?? null;

// 主持人（非來賓）離開時，把房間的 host_joined 標回 false，避免儀表板永遠顯示「主持人在線」
if ($was_logged_in && $leaving_room && empty($_SESSION['invited'])) {
  Rooms::setHostLeft($leaving_room);
}

// 清除會議相關 session，但保留登入狀態（方便主持人接著開下一場）
unset($_SESSION['jwt'], $_SESSION['room'], $_SESSION['invited']);

render_head('已離開會議');
render_topbar($me ?: false, $me ? Auth::clientIp() : null);
?>
<main class="container narrow">
  <div class="card" style="text-align:center;">
    <div class="icon-circle icon-circle-success">
      <?= icon('check', 28) ?>
    </div>
    <h1>已離開會議</h1>
    <p class="subtitle">感謝您的參與。</p>
    <?php if ($was_logged_in): ?>
      <a class="btn btn-primary" href="/dashboard"><?= icon('dashboard') ?>回儀表板</a>
    <?php else: ?>
      <p class="muted" style="font-size:13px;">若需要重新加入，請使用原本的邀請連結。</p>
    <?php endif; ?>
  </div>
</main>
<?php render_foot(); ?>
