<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/layout.php';

Auth::start();

$raw_room = $_GET['room'] ?? '';
$room = Rooms::sanitize($raw_room);

if ($room === '') {
  render_head('連結錯誤');
  render_topbar(false);
  ?>
  <main class="container narrow">
    <div class="card" style="text-align:center;">
      <div class="icon-circle icon-circle-danger"><?= icon('warning', 28) ?></div>
      <h1>邀請連結錯誤</h1>
      <p class="subtitle">這個邀請連結似乎不完整，請向主持人重新索取。</p>
    </div>
  </main>
  <?php
  render_foot();
  exit;
}

// 標記為來賓並記住房間；名字改在 /guest 由來賓自行輸入（預設留空、必填）
$_SESSION['invited']  = true;
$_SESSION['room']     = $room;
$_SESSION['guest_id'] = $_SESSION['guest_id'] ?? ('guest-' . bin2hex(random_bytes(6)));
// 每次重新點邀請連結都要求重新輸入名字
unset($_SESSION['guest_name'], $_SESSION['guest_jwt'], $_SESSION['guest_ready']);

header('Location: /guest');
exit;
