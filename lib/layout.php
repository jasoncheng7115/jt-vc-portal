<?php
/**
 * 共用 layout 與內嵌 iconoir 風格 icon。
 *   icon('play') => '<svg ...><path .../></svg>'
 */
require_once __DIR__ . '/settings.php';

/** 站台品牌（名稱 + logo 來源），供 head / topbar / 訪客頁共用。 */
function site_brand(): array {
  $s = Settings::getSite();
  $s['logo_src'] = ($s['logo_mime'] !== '') ? ('/logo?v=' . $s['logo_v']) : '/assets/logo-64.png';
  return $s;
}

function render_head(string $title): void {
  $theme = Settings::getTheme();
  $body_class = 'theme-' . $theme . (Settings::isDark($theme) ? ' is-dark' : '');
  $brand = site_brand();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  <title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($brand['brand_name']) ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body class="<?= htmlspecialchars($body_class) ?>">
<?php }

/**
 * $user：登入使用者 array（null = 未登入）。$ip：來源 IP（顯示用）。
 * 為相容舊呼叫，也接受 bool（true 視為已登入但不帶細節）。
 */
function render_topbar($user = null, ?string $ip = null): void {
  $logged_in = $user === true || (is_array($user) && !empty($user));
  $name = is_array($user) ? ($user['username'] ?? $user['email'] ?? '') : '';
  $display = is_array($user) ? trim((string)($user['display_name'] ?? '')) : '';
  $role = is_array($user) ? ($user['role'] ?? '') : '';
  $brand = site_brand();
?>
<header class="topbar">
  <div class="topbar-left">
    <a class="brand" href="/">
      <img class="brand-logo" src="<?= htmlspecialchars($brand['logo_src']) ?>" alt="<?= htmlspecialchars($brand['brand_name']) ?>" width="32" height="32">
      <span class="brand-text"><?= htmlspecialchars($brand['brand_name']) ?></span>
    </a>
    <?php if ($logged_in): ?><a class="topbar-version" href="<?= htmlspecialchars(defined('APP_GITHUB_URL') ? APP_GITHUB_URL : '#') ?>" target="_blank" rel="noopener noreferrer" title="系統版本 · 前往 GitHub">v<?= htmlspecialchars(defined('APP_VERSION') ? APP_VERSION : '') ?></a><?php endif; ?>
  </div>
  <div class="topbar-actions">
    <?php if ($logged_in): ?>
      <a class="btn btn-ghost" href="/dashboard"><?= icon('dashboard') ?>儀表板</a>
      <?php if ($name !== ''): ?>
        <div class="topbar-menu">
          <button type="button" class="topbar-user" id="topbarUserBtn" aria-haspopup="true" aria-expanded="false">
            <span class="topbar-avatar"><?= icon('user', 16) ?></span>
            <span class="topbar-user-name"><?= htmlspecialchars($name) ?></span>
            <?php if ($role === 'admin'): ?><span class="badge badge-accent" style="margin-left:2px;">管理員</span><?php endif; ?>
            <?= icon('arrow-right', 14) ?>
          </button>
          <div class="topbar-dropdown" id="topbarDropdown" role="menu">
            <div class="dropdown-head">
              <div class="dropdown-name"><?= htmlspecialchars($display !== '' ? $display : $name) ?></div>
              <?php if ($display !== '' && $display !== $name): ?><div class="dropdown-sub">@<?= htmlspecialchars($name) ?></div><?php endif; ?>
              <?php if ($ip): ?><div class="dropdown-sub"><?= htmlspecialchars($ip) ?></div><?php endif; ?>
            </div>
            <a class="dropdown-item" href="/profile" role="menuitem"><?= icon('user', 16) ?>個人設定 / 2FA</a>
          </div>
        </div>
      <?php endif; ?>
      <a class="btn btn-secondary" href="/logout"><?= icon('log-out') ?>登出</a>
    <?php endif; ?>
  </div>
</header>
<?php }

/**
 * 管理頁共用頁籤列。$active = dashboard|accounts|audit|usage|settings。
 * 統計頁兩種模式皆顯示：JaaS 稱「用量統計」（含 MAU），自建稱「會議統計」。
 */
function admin_nav(string $active = ''): string {
  if (Auth::isAdmin()) {
    $jaas = Settings::getJaas()['mode'];
    $tabs = [
      'dashboard' => ['/dashboard', 'video',     '會議室管理'],
      'accounts'  => ['/accounts',  'user',      '帳號管理'],
      'audit'     => ['/audit-log', 'clock',     '稽核記錄'],
    ];
    $tabs['usage'] = ['/usage', 'chart', $jaas === 'jaas' ? '用量統計' : '會議統計'];
    if (Settings::hasJibri()) $tabs['recordings'] = ['/recordings', 'video', '錄影記錄'];
    $tabs['settings'] = ['/settings', 'dashboard', '系統設定'];
  } else {
    // 主持人：只有會議室管理 +（有 Jibri 時）自己會議的錄影記錄
    $tabs = ['dashboard' => ['/dashboard', 'video', '會議室管理']];
    if (Settings::hasJibri()) $tabs['recordings'] = ['/recordings', 'video', '錄影記錄'];
  }

  $html = '<div class="nav-row">';
  foreach ($tabs as $key => $t) {
    [$href, $ic, $label] = $t;
    $cls = 'btn btn-secondary btn-sm' . ($key === $active ? ' active' : '');
    $html .= '<a class="' . $cls . '" href="' . $href . '">' . icon($ic, 14) . htmlspecialchars($label) . '</a>';
  }
  return $html . '</div>';
}

function render_foot(): void { ?>
<script>
/* 把每張卡片的 h1 標題轉成「整條標題列 + 可點選收合」。 */
(function () {
  var CHEV = '<svg class="chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
  document.querySelectorAll('.card').forEach(function (card) {
    var h = card.firstElementChild;
    if (!h || h.tagName !== 'H1') return;          // 只處理「首個子元素是 h1」的卡片
    card.classList.add('collapsible');

    var header = document.createElement('div');
    header.className = 'card-header';
    var span = document.createElement('span');
    span.className = 'card-header-title';
    span.innerHTML = h.innerHTML;          // 保留標題內的 icon
    header.appendChild(span);
    var chevWrap = document.createElement('span');
    chevWrap.innerHTML = CHEV;
    header.appendChild(chevWrap.firstChild);

    var body = document.createElement('div');
    body.className = 'card-body';
    var n = h.nextSibling;
    while (n) { var next = n.nextSibling; body.appendChild(n); n = next; }
    h.remove();
    card.appendChild(header);
    card.appendChild(body);

    header.addEventListener('click', function () { card.classList.toggle('collapsed'); });
  });
})();

/* 右上帳號選單：點頭像 / 名稱展開，點外面或 Esc 收合。 */
(function () {
  var btn = document.getElementById('topbarUserBtn');
  var menu = document.getElementById('topbarDropdown');
  if (!btn || !menu) return;
  var wrap = btn.parentElement;
  function close() { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
  function toggle(e) {
    e.stopPropagation();
    var open = wrap.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  btn.addEventListener('click', toggle);
  document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();

/* 表格欄位標題點擊排序（排序目前顯示的列；分頁表格僅排本頁）。 */
(function () {
  function num(v) { v = String(v).replace(/[, ]/g, ''); return /^-?\d+(\.\d+)?$/.test(v) ? parseFloat(v) : null; }
  document.querySelectorAll('table.table').forEach(function (table) {
    var thead = table.tHead;
    if (!thead || !thead.rows.length) return;
    var ths = thead.rows[0].cells;
    Array.prototype.forEach.call(ths, function (th, idx) {
      if (th.classList.contains('no-sort')) return;
      th.classList.add('sortable');
      th.addEventListener('click', function () {
        var tbody = table.tBodies[0];
        if (!tbody) return;
        // 把每個主列與其後緊接的明細列（.row-detail）配對，排序後一起搬動，避免拆散。
        var pairs = [];
        Array.prototype.forEach.call(tbody.rows, function (r) {
          if (r.classList.contains('row-detail')) return;
          var det = r.nextElementSibling;
          pairs.push({ main: r, detail: (det && det.classList.contains('row-detail')) ? det : null });
        });
        var asc = th.getAttribute('data-sort') !== 'asc';
        Array.prototype.forEach.call(ths, function (o) { if (o !== th) o.removeAttribute('data-sort'); });
        th.setAttribute('data-sort', asc ? 'asc' : 'desc');
        pairs.sort(function (a, b) {
          var x = (a.main.cells[idx] ? a.main.cells[idx].innerText : '').trim();
          var y = (b.main.cells[idx] ? b.main.cells[idx].innerText : '').trim();
          var nx = num(x), ny = num(y), r;
          if (nx !== null && ny !== null) r = nx - ny;
          else r = x.localeCompare(y, 'zh-Hant', { numeric: true });
          return asc ? r : -r;
        });
        pairs.forEach(function (p) { tbody.appendChild(p.main); if (p.detail) tbody.appendChild(p.detail); });
      });
    });
  });
})();
</script>
</body>
</html>
<?php }

/**
 * 內嵌 SVG icon（iconoir 風格：24x24、stroke 1.5、round caps、currentColor）。
 * 用法：<?= icon('play') ?>
 */
function icon(string $name, int $size = 18): string {
  static $paths = null;
  if ($paths === null) {
    $paths = [
      // 控制
      'play'        => '<path d="M6 4v16l14-8L6 4z"/>',
      'video'       => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3z"/>',
      'link'        => '<path d="M14 9l1.5-1.5a4 4 0 0 1 5.66 5.66L19 15.5"/><path d="M10 15l-1.5 1.5a4 4 0 0 1-5.66-5.66L5 9.5"/><path d="M9 15l6-6"/>',
      'copy'        => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
      'arrow-right' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
      'login'       => '<path d="M14 12H4"/><path d="M4 12l4-4"/><path d="M4 12l4 4"/><path d="M10 4h7a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-7"/>',
      'log-out'     => '<path d="M10 12h10"/><path d="M20 12l-4-4"/><path d="M20 12l-4 4"/><path d="M14 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8"/>',
      'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/>',
      'chart'       => '<path d="M4 4v16h16"/><rect x="7" y="12" width="2.6" height="5" rx="0.6"/><rect x="11.7" y="9" width="2.6" height="8" rx="0.6"/><rect x="16.4" y="6" width="2.6" height="11" rx="0.6"/>',
      'refresh'     => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
      'x'           => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
      'share'       => '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8 11l8-4"/><path d="M8 13l8 4"/>',
      'qr'          => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h2v2h-2zM18 14h2v2h-2zM14 18h2v2h-2zM18 18h2v2h-2"/>',
      'calendar'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M3 10h18"/>',
      'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'check'       => '<path d="M5 12l5 5L20 7"/>',
      'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 8v.01"/>',
      'warning'     => '<path d="M12 4l10 17H2L12 4z"/><path d="M12 10v5"/><path d="M12 18v.01"/>',
      'door-out'    => '<path d="M4 4h6v16H4z"/><path d="M14 12h7"/><path d="M21 12l-3-3"/><path d="M21 12l-3 3"/>',
      'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
      'lock'        => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
      'plus'        => '<path d="M12 5v14"/><path d="M5 12h14"/>',
      'home'        => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
      'edit'        => '<path d="M4 20h4L18.5 9.5l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
      'download'    => '<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M5 21h14"/>',
      'upload'      => '<path d="M12 21V9"/><path d="M7 13l5-5 5 5"/><path d="M5 4h14"/>',
      'trash'       => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
    ];
  }
  $body = $paths[$name] ?? '';
  return '<svg class="icon" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$body.'</svg>';
}
