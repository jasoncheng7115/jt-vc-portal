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

/** 本請求的 CSP nonce（每個 script / style 元素都要帶 nonce 屬性，見 nonce_attr()）。 */
function csp_nonce(): string {
  static $n = null;
  if ($n === null) $n = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
  return $n;
}

/** CSP form-action：本站；啟用 SSO 時加上 IdP 來源（登出表單會被導到 IdP 的 end_session）。 */
function csp_form_action(): string {
  $fa = "'self'";
  if (is_file(__DIR__ . '/oidc.php')) {
    require_once __DIR__ . '/oidc.php';
    if (Oidc::enabled() && ($o = Oidc::origin()) !== '' && preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+$#', $o)) $fa .= ' ' . $o;
  }
  return $fa;
}

/** nonce 屬性字串（直接嵌進標籤）。 */
function nonce_attr(): string {
  return 'nonce="' . csp_nonce() . '"';
}

/** 會議內嵌頁（meeting / guest 進場）的 CSP：腳本只允許本站 + nonce（Jitsi API 已內附）、iframe 只允許 Jitsi 網域。 */
function send_meeting_csp(): void {
  if (headers_sent()) return;
  $n = csp_nonce();
  $frame = Jaas::frameOrigin();
  header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; "
       . "frame-ancestors 'self'; form-action 'self'; img-src 'self' data:; font-src 'self' data:; "
       . "frame-src {$frame}; child-src {$frame}; "
       . "style-src 'self' 'nonce-{$n}'; style-src-elem 'self' 'nonce-{$n}'; style-src-attr 'unsafe-inline'; "
       . "script-src 'self' 'nonce-{$n}' https://cdn.jsdelivr.net; script-src-attr 'none'; connect-src 'self'");
}

function render_head(string $title): void {
  // 內容安全政策（A05 / 縱深防禦）。script 與 style 元素一律以 nonce 放行（不再用 'unsafe-inline'）；
  // 僅 style="" 屬性允許 inline（style-src-attr），因版面與 qrcodejs 產生的元素需要。
  // 會議內嵌頁（meeting/guest 進場）自建 <head>、不經此函式。
  if (!headers_sent()) {
    $n = csp_nonce();
    $fa = csp_form_action();
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; "
         . "frame-ancestors 'self'; form-action {$fa}; img-src 'self' data:; font-src 'self' data:; "
         . "style-src 'self' 'nonce-{$n}' https://cdn.jsdelivr.net; style-src-elem 'self' 'nonce-{$n}' https://cdn.jsdelivr.net; "
         . "style-src-attr 'unsafe-inline'; "
         . "script-src 'self' 'nonce-{$n}' https://cdn.jsdelivr.net; script-src-attr 'none'; connect-src 'self'");
  }
  $theme = Settings::getTheme();
  $body_class = 'theme-' . $theme . (Settings::isDark($theme) ? ' is-dark' : '');
  $brand = site_brand();
?>
<!DOCTYPE html>
<html lang="<?= I18n::htmlLang() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  <title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($brand['brand_name']) ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body class="<?= htmlspecialchars($body_class) ?>">
<?php }

/** 語言切換連結（顯示「另一個」語言；供未登入頁 / 來賓頁的頂列使用）。 */
function lang_switch_html(string $cls = ''): string {
  // 只顯示目前語言；點了才展開可切換的語言（與右上帳號選單同一套樣式與開關：點外面 / Esc 收合）
  $cur = I18n::lang();
  $items = '';
  foreach (I18n::NAMES as $code => $name) {
    $on = $code === $cur;
    $items .= '<a class="dropdown-item' . ($on ? ' active' : '') . '" role="menuitem" href="' . htmlspecialchars(I18n::switchUrl($code)) . '" hreflang="' . htmlspecialchars($code) . '" lang="' . htmlspecialchars($code) . '"' . ($on ? ' aria-current="true"' : '') . '>'
            . icon($on ? 'check' : 'globe', 16) . htmlspecialchars($name) . '</a>';
  }
  return '<div class="topbar-menu lang-menu">'
       . '<button type="button" class="topbar-user lang-switch" id="topbarLangBtn" aria-haspopup="true" aria-expanded="false" aria-label="' . th('語言') . '">'
       . icon('globe', 16) . '<span class="topbar-user-name">' . htmlspecialchars(I18n::NAMES[$cur] ?? $cur) . '</span>' . icon('arrow-right', 14) . '</button>'
       . '<div class="topbar-dropdown" id="topbarLangMenu" role="menu">' . $items . '</div></div>';
}

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
    <?php if ($logged_in): ?><a class="topbar-version" href="<?= htmlspecialchars(defined('APP_GITHUB_URL') ? APP_GITHUB_URL : '#') ?>" target="_blank" rel="noopener noreferrer" title="<?= th('系統版本 · 前往 GitHub') ?>">v<?= htmlspecialchars(defined('APP_VERSION') ? APP_VERSION : '') ?></a><?php endif; ?>
  </div>
  <div class="topbar-actions">
    <?php if ($logged_in): ?>
      <a class="btn btn-ghost" href="/dashboard"><?= icon('dashboard') ?><?= th('儀表板') ?></a>
      <?php if ($name !== ''): ?>
        <div class="topbar-menu">
          <button type="button" class="topbar-user" id="topbarUserBtn" aria-haspopup="true" aria-expanded="false">
            <span class="topbar-avatar"><?= icon('user', 16) ?></span>
            <span class="topbar-user-name"><?= htmlspecialchars($name) ?></span>
            <?php if ($role === 'admin'): ?><span class="badge badge-accent" style="margin-left:2px;"><?= th('管理員') ?></span><?php endif; ?>
            <?= icon('arrow-right', 14) ?>
          </button>
          <div class="topbar-dropdown" id="topbarDropdown" role="menu">
            <div class="dropdown-head">
              <div class="dropdown-name"><?= htmlspecialchars($display !== '' ? $display : $name) ?></div>
              <?php if ($display !== '' && $display !== $name): ?><div class="dropdown-sub">@<?= htmlspecialchars($name) ?></div><?php endif; ?>
              <?php if ($ip): ?><div class="dropdown-sub"><?= htmlspecialchars($ip) ?></div><?php endif; ?>
            </div>
            <a class="dropdown-item" href="/profile" role="menuitem"><?= icon('user', 16) ?><?= th('個人設定 / 2FA') ?></a>
            <div class="dropdown-sep"></div>
            <?php foreach (I18n::NAMES as $lc => $ln): ?>
              <a class="dropdown-item<?= I18n::lang() === $lc ? ' active' : '' ?>" href="<?= htmlspecialchars(I18n::switchUrl($lc)) ?>" role="menuitem" lang="<?= htmlspecialchars($lc) ?>"><?= icon(I18n::lang() === $lc ? 'check' : 'globe', 16) ?><?= htmlspecialchars($ln) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <form method="POST" action="/logout" style="display:inline;margin:0;"><?= Auth::csrfField() ?><button type="submit" class="btn btn-secondary"><?= icon('log-out') ?><?= th('登出') ?></button></form>
    <?php else: ?>
      <?= lang_switch_html() ?>
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
      'dashboard' => ['/dashboard', 'video',     t('會議室管理')],
      'accounts'  => ['/accounts',  'user',      t('帳號管理')],
      'audit'     => ['/audit-log', 'clock',     t('稽核記錄')],
    ];
    $tabs['usage'] = ['/usage', 'chart', $jaas === 'jaas' ? t('用量統計') : t('會議統計')];
    if (Settings::hasJibri()) $tabs['recordings'] = ['/recordings', 'video', t('錄影記錄')];
    $tabs['settings'] = ['/settings', 'dashboard', t('系統設定')];
  } else {
    // 主持人：只有會議室管理 +（有 Jibri 時）自己會議的錄影記錄
    $tabs = ['dashboard' => ['/dashboard', 'video', t('會議室管理')]];
    if (Settings::hasJibri()) $tabs['recordings'] = ['/recordings', 'video', t('錄影記錄')];
  }

  $html = '<div class="nav-row">';
  foreach ($tabs as $key => $t) {
    [$href, $ic, $label] = $t;
    $cls = 'btn btn-secondary btn-sm' . ($key === $active ? ' active' : '');
    $html .= '<a class="' . $cls . '" href="' . $href . '">' . icon($ic, 14) . htmlspecialchars($label) . '</a>';
  }
  return $html . '</div>';
}

/** 會議室清單 / 建立結果的逐字稿標籤（v1.20.0）：錄影後會自動產生逐字稿時顯示，附會議語言；呼叫端需已載入 lib/transcripts.php。 */
function tx_room_badge(array $room): string {
  $p = class_exists('Transcripts') ? Transcripts::roomPlan($room) : null;
  if (!$p) return '';
  $label = $p['summary'] ? t('逐字稿與摘要') : t('逐字稿');
  $title = $p['summary'] ? t('錄影完成後會自動產生逐字稿與摘要') : t('錄影完成後會自動產生逐字稿（這個語言不支援摘要）');
  $sub = $p['lang'] !== '' ? '<span class="badge-sub">· ' . htmlspecialchars(Transcripts::languageLabel($p['lang'])) . '</span>' : '';
  return '<span class="badge badge-info tx-room-badge" title="' . htmlspecialchars($title) . '">' . icon('file-text', 11) . htmlspecialchars($label) . $sub . '</span>';
}

function render_foot(): void { ?>
<script <?= nonce_attr() ?>>
/* Turn each card's h1 title into a full-width, clickable collapse header. */
(function () {
  var CHEV = '<svg class="chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
  document.querySelectorAll('.card').forEach(function (card) {
    var h = card.firstElementChild;
    if (!h || h.tagName !== 'H1') return;          // only cards whose first child is an h1
    card.classList.add('collapsible');

    var header = document.createElement('div');
    header.className = 'card-header';
    var span = document.createElement('span');
    span.className = 'card-header-title';
    span.innerHTML = h.innerHTML;          // keep the icon inside the title
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

/* Top-right account / language menus: click to open; click outside or Esc to close. */
[['topbarUserBtn', 'topbarDropdown'], ['topbarLangBtn', 'topbarLangMenu']].forEach(function (ids) {
  var btn = document.getElementById(ids[0]);
  var menu = document.getElementById(ids[1]);
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
});

/* Site-styled dropdown for <select data-cselect>. The native select stays in the form (value, required, change events)
   but is visually hidden; a button + listbox gives it the site look. The menu is position:fixed and lives in <body>,
   so collapsible cards (overflow:hidden) cannot clip it. Keyboard: arrows / Home / End / Enter / Space / Esc. */
(function () {
  var CHECK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
  var seq = 0;
  document.querySelectorAll('select[data-cselect]').forEach(function (sel) {
    var id = 'cselect' + (++seq);
    var wrap = document.createElement('div'); wrap.className = 'cselect';
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel);
    sel.classList.add('cselect-native'); sel.tabIndex = -1; sel.setAttribute('aria-hidden', 'true');
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'cselect-btn';
    btn.id = (sel.id || id) + 'Btn';
    btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', id);
    var txt = document.createElement('span'); txt.className = 'cselect-text'; btn.appendChild(txt);
    wrap.appendChild(btn);
    var lab = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
    if (lab) { if (!lab.id) lab.id = sel.id + 'Label'; lab.htmlFor = btn.id; btn.setAttribute('aria-labelledby', lab.id + ' ' + btn.id); }
    var menu = document.createElement('ul'); menu.className = 'cselect-menu'; menu.id = id; menu.setAttribute('role', 'listbox'); menu.hidden = true;
    if (lab) menu.setAttribute('aria-labelledby', lab.id);
    document.body.appendChild(menu);
    var items = [];
    Array.prototype.forEach.call(sel.options, function (o, i) {
      if (o.value === '') return;                       // the "please choose" placeholder is not a choice
      var li = document.createElement('li'); li.setAttribute('role', 'option'); li.id = id + '-' + i; li.setAttribute('data-value', o.value);
      var ck = document.createElement('span'); ck.className = 'cselect-check'; ck.innerHTML = CHECK;
      var lb = document.createElement('span'); lb.textContent = o.textContent;
      li.appendChild(ck); li.appendChild(lb); menu.appendChild(li); items.push(li);
    });
    var active = -1;
    function sync() {
      var o = sel.options[sel.selectedIndex];
      txt.textContent = o ? o.textContent : '';
      btn.classList.toggle('is-placeholder', !o || o.value === '');
      items.forEach(function (li) { li.setAttribute('aria-selected', li.getAttribute('data-value') === sel.value ? 'true' : 'false'); });
      if (sel.value !== '') wrap.classList.remove('invalid');
    }
    function setActive(i, fromMouse) {
      if (!items.length) return;
      active = (i + items.length) % items.length;
      items.forEach(function (li, k) { li.classList.toggle('active', k === active); });
      btn.setAttribute('aria-activedescendant', items[active].id);
      if (!fromMouse) items[active].scrollIntoView({ block: 'nearest' });
    }
    function place() {
      var r = btn.getBoundingClientRect();
      menu.style.minWidth = r.width + 'px';
      menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
      var h = menu.offsetHeight, below = window.innerHeight - r.bottom;
      menu.style.top = (below < h + 8 && r.top > below ? Math.max(8, r.top - h - 4) : r.bottom + 4) + 'px';
    }
    function open() {
      if (!menu.hidden) return;
      menu.hidden = false; wrap.classList.add('open'); btn.setAttribute('aria-expanded', 'true');
      place();
      var cur = -1; items.forEach(function (li, k) { if (li.getAttribute('data-value') === sel.value) cur = k; });
      setActive(cur >= 0 ? cur : 0);
    }
    function close() {
      if (menu.hidden) return;
      menu.hidden = true; wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); btn.removeAttribute('aria-activedescendant');
    }
    function choose(i) {
      var li = items[i]; if (!li) return;
      if (sel.value !== li.getAttribute('data-value')) { sel.value = li.getAttribute('data-value'); sel.dispatchEvent(new Event('change', { bubbles: true })); }
      sync(); close(); btn.focus();
    }
    btn.addEventListener('click', function () { if (menu.hidden) open(); else close(); });
    btn.addEventListener('keydown', function (e) {
      var k = e.key;
      if (menu.hidden) { if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'Enter' || k === ' ') { e.preventDefault(); open(); } return; }
      if (k === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (k === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (k === 'Home') { e.preventDefault(); setActive(0); }
      else if (k === 'End') { e.preventDefault(); setActive(items.length - 1); }
      else if (k === 'Enter' || k === ' ') { e.preventDefault(); choose(active); }
      else if (k === 'Escape') { e.preventDefault(); close(); }
      else if (k === 'Tab') close();
    });
    menu.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep focus on the button
    menu.addEventListener('click', function (e) { var li = e.target.closest('li[role=option]'); if (li) choose(items.indexOf(li)); });
    menu.addEventListener('mousemove', function (e) { var li = e.target.closest('li[role=option]'); if (li && items.indexOf(li) !== active) setActive(items.indexOf(li), true); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target) && !menu.contains(e.target)) close(); });
    window.addEventListener('resize', function () { if (!menu.hidden) place(); });
    window.addEventListener('scroll', function () { if (!menu.hidden) place(); }, true);
    sel.addEventListener('change', sync);                                         // value changed elsewhere
    sel.addEventListener('invalid', function () { wrap.classList.add('invalid'); });   // required and empty on submit
    sync();
  });
})();

/* Click a table column header to sort (sorts visible rows; paginated tables sort the current page only). */
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
        // Pair each main row with its following detail row (.row-detail) and move them together so they stay adjacent.
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

/* Custom confirm dialog replacing the native confirm(). Forms with data-confirm="message" are intercepted. */
(function () {
  var pending = null;
  function m() { return document.getElementById('confirmModal'); }
  function close() { var x = m(); if (x) x.classList.remove('open'); pending = null; }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM' || !form.getAttribute('data-confirm')) return;
    var msg = form.getAttribute('data-confirm');
    var x = m();
    if (!x) { if (!window.confirm(msg)) e.preventDefault(); return; }   // fallback
    e.preventDefault();
    pending = form;
    x.querySelector('.confirm-msg').textContent = msg;
    x.classList.add('open');
  }, true);
  document.addEventListener('click', function (e) {
    var x = m(); if (!x || !x.classList.contains('open')) return;
    if (e.target.closest('#confirmOk')) { var f = pending; close(); if (f) f.submit(); }   // .submit() does not fire the submit event again
    else if (e.target.closest('#confirmCancel') || e.target === x) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<div class="modal-backdrop" id="confirmModal">
  <div class="modal" role="dialog" aria-modal="true" style="max-width:420px;">
    <h2><?= th('請確認') ?></h2>
    <p class="confirm-msg modal-sub" style="margin:8px 0 0;"></p>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="confirmCancel"><?= icon('x',14) ?><?= th('取消') ?></button>
      <button type="button" class="btn btn-primary" id="confirmOk"><?= icon('check',14) ?><?= th('確定') ?></button>
    </div>
  </div>
</div>
</body>
</html>
<?php }

/**
 * 內嵌 SVG icon（iconoir 風格：24x24、stroke 1.5、round caps、currentColor）。
 * 用法：<?= icon('play') ?>
 */
/**
 * Jitsi Meet 故障時給主持人 / 來賓看的友善頁面（v1.16.0）：說明狀況、每 30 秒自動重試、可手動重試。
 * 不透露內部細節（網域、錯誤碼），只記在伺服器端。
 */
function render_service_down(string $room, bool $isHost): void {
  http_response_code(503);
  header('Retry-After: 30');
  render_head(t('視訊服務暫時無法使用'));
  $b = site_brand(); ?>
<div class="page">
  <header class="topbar">
    <a class="brand" href="<?= $isHost ? '/dashboard' : '#' ?>">
      <img class="brand-logo" src="<?= htmlspecialchars($b['logo_src']) ?>" alt="<?= htmlspecialchars($b['brand_name']) ?>" width="32" height="32">
      <span class="brand-text"><?= htmlspecialchars($b['brand_name']) ?></span>
    </a>
  </header>
  <main class="center-stage">
    <div class="stage-card" id="svcDown">
      <?php if ($room !== ''): ?><div class="room-tag"><?= icon('door-out', 12) ?><?= htmlspecialchars($room) ?></div><?php endif; ?>
      <div class="icon-circle icon-circle-danger" style="margin-bottom:18px;"><?= icon('warning', 30) ?></div>
      <h1><?= th('視訊服務暫時無法使用') ?></h1>
      <p class="muted" style="margin:6px 0 0;"><?= th('會議系統正在維護或暫時發生問題，目前無法進入會議室。您的邀請連結仍然有效，請稍後再試。') ?></p>
      <p class="stage-note"><?= icon('refresh', 14) ?> <?= th('本頁每 30 秒自動重新嘗試。') ?></p>
      <?php if ($isHost): ?><p class="stage-note"><?= icon('info', 14) ?> <?= th('管理員可在儀表板的「系統狀態」查看原因。') ?></p><?php endif; ?>
      <div class="stage-actions">
        <button type="button" class="btn btn-primary" id="svcRetry"><?= icon('refresh', 14) ?><?= th('立即重試') ?></button>
        <?php if ($isHost): ?><a class="btn btn-secondary" href="/dashboard"><?= th('回到儀表板') ?></a><?php endif; ?>
      </div>
    </div>
  </main>
</div>
<script <?= nonce_attr() ?>>
document.getElementById('svcRetry').addEventListener('click', () => location.reload());
setTimeout(() => location.reload(), 30000);
</script>
<?php render_foot();
}

/** 會議頁的「連不上 Jitsi」遮罩（會議畫面載入後才出問題時用）。 */
function jitsi_down_overlay(bool $isHost): string {
  return '<div class="svc-overlay" id="svcOverlay" hidden><div class="stage-card">'
    . '<div class="icon-circle icon-circle-danger" style="margin-bottom:18px;">' . icon('warning', 30) . '</div>'
    . '<h1>' . th('無法連線到會議') . '</h1>'
    . '<p class="muted" style="margin:6px 0 0;">' . th('會議系統沒有回應，可能正在維護或網路暫時不穩。請稍後再試；若一直無法進入，請聯絡會議主持人。') . '</p>'
    . '<div class="stage-actions"><button type="button" class="btn btn-primary" id="svcOverlayRetry">' . icon('refresh', 14) . th('重試') . '</button>'
    . ($isHost ? '<a class="btn btn-secondary" href="/dashboard">' . th('回到儀表板') . '</a>' : '') . '</div></div></div>';
}

/**
 * 會議頁 JS：Jitsi 會議畫面（iframe）載入後會用 postMessage 跟外層溝通；
 * 20 秒內一個訊息都沒收到＝會議畫面根本沒起來（Jitsi 故障），Jitsi 回報致命錯誤也一樣 → 顯示遮罩。
 * 需在建立 api 之後呼叫：jitsiWatch(api)。
 */
function jitsi_watch_js(): string {
  $origin = Jaas::frameOrigin();
  return 'function jitsiWatch(api){var alive=false,shown=false,origin=' . json_encode($origin) . ';'
    . 'function show(){if(shown)return;shown=true;var o=document.getElementById("svcOverlay");if(o){o.hidden=false;}}'
    . 'window.addEventListener("message",function(e){if(e.origin===origin)alive=true;});'
    . 'setTimeout(function(){if(!alive)show();},20000);'
    . 'api.addEventListener("errorOccurred",function(e){if(e&&e.error&&e.error.isFatal)show();});'
    . 'var r=document.getElementById("svcOverlayRetry");if(r)r.addEventListener("click",function(){location.reload();});}';
}

function icon(string $name, int $size = 18): string {
  static $paths = null;
  if ($paths === null) {
    $paths = [
      // 控制
      'play'        => '<path d="M6 4v16l14-8L6 4z"/>',
      'pause'       => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
      'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8M8 9h2"/>',
      'list'        => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
      'sparkles'    => '<path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
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
      'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
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
      'globe'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/>',
      'trash'       => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
    ];
  }
  $body = $paths[$name] ?? '';
  return '<svg class="icon" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$body.'</svg>';
}
