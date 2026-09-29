<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/logship.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/transcripts.php';
require_once __DIR__ . '/lib/requirements.php';
require_once __DIR__ . '/lib/layout.php';

$me = Auth::requireAdmin();
$ip = Auth::clientIp();
$usage_now = Usage::currentMonthCount();

$msg = $_SESSION['set_msg'] ?? '';
$err = $_SESSION['set_err'] ?? '';
unset($_SESSION['set_msg'], $_SESSION['set_err']);

$smtp = Mailer::config();
$ship = LogShip::config();
$plan_limit = Settings::getPlanLimit();
$billing_day = Settings::getBillingStartDay();
$secret = Settings::getWebhookSecret();
$jaas = Settings::getJaas();
$webhook_url = ($jaas['site_url'] ?: SITE_URL) . '/webhooks/usage';

render_head(t('系統設定'));
render_topbar($me, $ip);
?>
<main class="container settings-page">
  <?= admin_nav('settings') ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>
  <?php if ($req_miss = Requirements::missing()): ?>
    <div class="alert alert-error" id="reqMissing"><?= icon('warning') ?><span><?= th('這台主機缺少部分必要元件，相關功能無法使用。請依 README「系統需求」安裝（例如 Debian / Ubuntu：apt install php-curl php-mbstring），再重新啟動 Apache：') ?>
      <?php foreach ($req_miss as $n => $use): ?><br><strong class="mono"><?= htmlspecialchars($n) ?></strong> — <?= htmlspecialchars($use) ?><?php endforeach; ?></span></div>
  <?php endif; ?>

  <?php
  /* 設定目錄：點選只顯示該卡片（#id），「全部」顯示全部；依連線模式隱藏的卡片，目錄項目一併隱藏 */
  $toc = [
    ['site', 'home', t('站台設定'), ''],
    ['login-path', 'lock', t('登入頁路徑'), ''],
    ['sso', 'user', t('單一登入（SSO）'), ''],
    ['meeting-ui', 'video', t('會議室介面'), ''],
    ['connection', 'link', t('連線模式設定'), ''],
    ['meeting-custom', 'video', t('會議室自訂'), 'js-card-selfhosted'],
    ['recording', 'video', t('錄製設定'), ''],
    ['transcribe', 'file-text', t('逐字稿與摘要'), ''],
    ['webhook', 'chart', t('8x8 用量 Webhook'), 'js-card-jaas'],
    ['smtp', 'calendar', t('SMTP 寄信'), ''],
    ['logship', 'upload', t('記錄外拋（SIEM）'), ''],
    ['theme', 'edit', t('外觀主題'), ''],
    ['backup', 'upload', t('設定匯出 / 匯入'), ''],
  ];
  $toc_hidden = fn($cls) => ($cls === 'js-card-selfhosted' && $jaas['mode'] !== 'selfhosted') || ($cls === 'js-card-jaas' && $jaas['mode'] !== 'jaas');
  ?>
  <div class="settings-layout">
  <nav class="settings-toc" aria-label="<?= th('設定目錄') ?>">
    <a href="#all" class="settings-toc-item" data-toc="all"><?= th('全部') ?></a>
    <?php foreach ($toc as [$tid, $ticon, $tlabel, $tcls]): ?>
      <a href="#<?= $tid ?>" class="settings-toc-item <?= $tcls ?>" data-toc="<?= $tid ?>"<?= $toc_hidden($tcls) ? ' style="display:none;"' : '' ?>><?= icon($ticon, 14) ?><?= htmlspecialchars($tlabel) ?></a>
    <?php endforeach; ?>
  </nav>
  <script <?= nonce_attr() ?>>
  (function () {
    var KEY = 'jtvc-settings-tab';
    function show(id, push) {
      var cards = document.querySelectorAll('.settings-card');
      var el = id !== 'all' ? document.getElementById('card-' + id) : null;
      var ok = el && el.classList.contains('settings-card') && el.style.display !== 'none';
      if (!ok) id = 'all';
      cards.forEach(function (c) { c.classList.toggle('toc-hidden', id !== 'all' && c.id !== 'card-' + id); });
      document.querySelectorAll('.settings-toc-item').forEach(function (a) { a.classList.toggle('active', a.getAttribute('data-toc') === id); });
      try { sessionStorage.setItem(KEY, id); } catch (e) {}
      if (push && history.replaceState) history.replaceState(null, '', id === 'all' ? location.pathname : '#' + id);
    }
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.settings-toc-item').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-toc'), true); window.scrollTo(0, 0); });
      });
      var h = location.hash.replace('#', ''), saved = '';
      try { saved = sessionStorage.getItem(KEY) || ''; } catch (e) {}
      show(h || saved || 'all', false);
    });
    window.addEventListener('hashchange', function () { show(location.hash.replace('#', '') || 'all', false); window.scrollTo(0, 0); });
    // cards use id="card-<id>" so the URL #<id> never triggers the browser's own anchor jump (the index stays visible)
  })();
  </script>
  <div class="settings-main">

  <?php /* 站台設定 */ ?>
  <?php $brand = site_brand(); ?>
  <div id="card-site" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('home', 18) ?><?= th('站台設定') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('設定左上角 logo 與站台名稱，套用到所有頁面（含登入頁、來賓頁）。') ?></p>
    <form method="POST" action="/save-site" enctype="multipart/form-data">
      <?= Auth::csrfField() ?>
      <div class="field"><label><?= th('站台名稱') ?></label>
        <input type="text" name="brand_name" maxlength="40" value="<?= htmlspecialchars(I18n::isDefaultBrand($brand['brand_name']) ? '' : $brand['brand_name']) ?>" placeholder="<?= th('JT 視訊會議') ?>"></div>
      <div class="field">
        <label><?= th('目前 logo') ?></label>
        <div class="file-picker">
          <img id="logoPreview" class="logo-preview" src="<?= htmlspecialchars($brand['logo_src']) ?>?v=<?= (int)$brand['logo_v'] ?>" alt="logo">
          <label class="file-btn"><?= icon('upload', 16) ?><?= th('選擇圖片') ?>
            <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/webp,image/gif">
          </label>
          <span class="file-name" id="logoName"><?= th('未選擇檔案') ?></span>
        </div>
        <div class="help"><?= th('建議正方形 PNG，小於 2MB。留空則不更動。') ?></div>
      </div>
      <script <?= nonce_attr() ?>>
        (function(){
          var inp = document.getElementById('logoInput');
          if (!inp) return;
          inp.addEventListener('change', function(){
            var f = inp.files && inp.files[0];
            document.getElementById('logoName').textContent = f ? f.name : <?= json_encode(t('未選擇檔案')) ?>;
            if (f) document.getElementById('logoPreview').src = URL.createObjectURL(f);
          });
        })();
      </script>
      <?php if ($brand['logo_mime'] !== ''): ?>
      <div class="field">
        <label style="font-weight:400;"><input type="checkbox" name="remove_logo" value="1"> <?= th('恢復成預設 logo') ?></label>
      </div>
      <?php endif; ?>
      <button class="btn btn-primary"><?= icon('check') ?><?= th('儲存站台設定') ?></button>
    </form>
  </div>

  <?php /* 登入頁路徑偽裝 */ ?>
  <?php $login_path = Settings::getLoginPath(); ?>
  <div id="card-login-path" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('lock', 18) ?><?= th('登入頁路徑') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= t('把登入入口改成只有你知道的祕密路徑，降低被自動掃描 / 暴力嘗試的機會。改掉後原本的 <span class="mono">/jt-login</span> 會直接回 404。') ?></p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="login_path">
      <div class="field">
        <label><?= th('登入路徑') ?></label>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span class="mono" style="color:var(--text-muted);white-space:nowrap;"><?= htmlspecialchars(rtrim(SITE_URL, '/')) ?>/</span>
          <input type="text" name="login_path" maxlength="64" value="<?= htmlspecialchars($login_path) ?>" placeholder="jt-login" pattern="[A-Za-z0-9._-]{1,64}" style="width:240px;max-width:100%;">
          <button class="btn btn-primary" style="white-space:nowrap;height:42px;"><?= icon('check',14) ?><?= th('儲存登入路徑') ?></button>
        </div>
        <div class="help"><?= t('僅允許英數與 <span class="mono">. _ -</span>，長度 1–64。留空還原為預設 <span class="mono">jt-login</span>。') ?></div>
      </div>
    </form>
    <div class="alert alert-info" style="align-items:flex-start;margin-top:14px;">
      <?= icon('warning') ?>
      <span><?= th('請務必記住新路徑——忘記時只能從伺服器端用 CLI 還原：') ?><br>
        <span class="mono">docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php reset</span></span>
    </div>
  </div>

  <?php /* 單一登入（OIDC SSO） */ ?>
  <?php $oidc = Settings::getOidc(); $oidc_ip = Auth::clientIp(); ?>
  <div id="card-sso" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('user', 18) ?><?= th('單一登入（SSO / OIDC）') ?></h1>
    <p class="subtitle" style="margin:6px 0 12px;"><?= th('讓主持人與管理員用公司帳號登入（Keycloak、Microsoft Entra ID 等 OIDC 身分驗證服務）。本系統不直接連 AD / LDAP；地端 AD 請由 Keycloak 聯合。來賓仍使用邀請連結，不受影響。') ?></p>
    <div class="help" style="margin-bottom:12px;"><?= t('Keycloak 完整部署步驟見 {link}。', ['link' => '<a href="' . htmlspecialchars(I18n::docUrl('KEYCLOAK-SETUP')) . '" target="_blank" rel="noopener">KEYCLOAK-SETUP.md</a>']) ?></div>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="oidc">
      <div class="field"><label><input type="checkbox" name="enabled" value="1" <?= $oidc['enabled'] ? 'checked' : '' ?>> <?= th('啟用單一登入') ?></label></div>
      <div class="field"><label><?= th('Redirect URI（請在 IdP 註冊這個網址）') ?></label>
        <input type="text" readonly class="mono" value="<?= htmlspecialchars(rtrim(SITE_URL, '/') . '/sso-callback') ?>"></div>
      <div class="field-row">
        <div class="field"><label><?= th('Issuer（例：https://sso.example.com/realms/jtvc）') ?></label>
          <input type="url" name="issuer" value="<?= htmlspecialchars($oidc['issuer']) ?>" placeholder="https://sso.example.com/realms/jtvc"></div>
        <div class="field"><label><?= th('登入按鈕文字（留空用預設）') ?></label>
          <input type="text" name="display_name" maxlength="60" value="<?= htmlspecialchars($oidc['display_name']) ?>" placeholder="<?= th('以公司帳號登入（SSO）') ?>"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Client ID</label>
          <input type="text" name="client_id" value="<?= htmlspecialchars($oidc['client_id']) ?>" placeholder="jt-vc-portal"></div>
        <div class="field"><label>Client secret</label>
          <input type="password" name="client_secret" value="" autocomplete="new-password" placeholder="<?= $oidc['client_secret'] !== '' ? th('已設定（留空不變更）') : '' ?>"></div>
      </div>
      <div class="field-row">
        <div class="field"><label><?= th('管理員群組（逗號分隔）') ?></label>
          <input type="text" name="admin_groups" value="<?= htmlspecialchars($oidc['admin_groups']) ?>" placeholder="VC-Admins"></div>
        <div class="field"><label><?= th('主持人群組（逗號分隔）') ?></label>
          <input type="text" name="host_groups" value="<?= htmlspecialchars($oidc['host_groups']) ?>" placeholder="VC-Hosts"></div>
      </div>
      <div class="help"><?= th('不在上述任一群組的使用者一律無法登入。群組名稱不分大小寫；Keycloak 的完整路徑（/A/B）可填完整路徑或最後一段。') ?></div>
      <div class="field" style="margin-top:12px;"><label><input type="checkbox" name="require_https" value="1" <?= $oidc['require_https'] ? 'checked' : '' ?>> <?= th('IdP 必須使用 HTTPS（建議保持勾選）') ?></label></div>
      <div class="field"><label><input type="checkbox" name="sso_only" value="1" <?= $oidc['sso_only'] ? 'checked' : '' ?>> <?= th('僅限單一登入：一般帳號不能再用本地密碼登入') ?></label></div>
      <div class="field"><label><?= th('僅限單一登入時，仍允許本地密碼登入的來源 IP / CIDR（緊急用管理員；逗號分隔，留空＝只允許本機）') ?></label>
        <input type="text" name="local_login_cidrs" value="<?= htmlspecialchars($oidc['local_login_cidrs']) ?>" placeholder="192.168.1.0/24, 10.8.0.0/16">
        <div class="help"><?= th('您目前的來源 IP：') ?><span class="mono"><?= htmlspecialchars($oidc_ip) ?></span></div></div>
      <details style="margin:10px 0;"><summary class="muted" style="cursor:pointer;"><?= th('進階：Scopes 與 claim 名稱') ?></summary>
        <div class="field-row" style="margin-top:10px;">
          <div class="field"><label>Scopes</label><input type="text" name="scopes" value="<?= htmlspecialchars($oidc['scopes']) ?>"></div>
          <div class="field"><label><?= th('群組 claim') ?></label><input type="text" name="groups_claim" value="<?= htmlspecialchars($oidc['groups_claim']) ?>"></div>
        </div>
        <div class="field-row">
          <div class="field"><label><?= th('帳號名稱 claim') ?></label><input type="text" name="username_claim" value="<?= htmlspecialchars($oidc['username_claim']) ?>"></div>
          <div class="field"><label><?= th('Email claim') ?></label><input type="text" name="email_claim" value="<?= htmlspecialchars($oidc['email_claim']) ?>"></div>
          <div class="field"><label><?= th('顯示名稱 claim') ?></label><input type="text" name="name_claim" value="<?= htmlspecialchars($oidc['name_claim']) ?>"></div>
        </div>
      </details>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="submit" name="action" value="save" class="btn btn-primary"><?= icon('check', 14) ?><?= th('儲存') ?></button>
        <button type="submit" name="action" value="test" class="btn btn-secondary"><?= icon('refresh', 14) ?><?= th('儲存並測試連線') ?></button>
      </div>
    </form>
    <details style="margin-top:14px;"><summary class="muted" style="cursor:pointer;"><?= th('常見 IdP 設定對照') ?></summary>
      <table class="table" style="margin-top:8px;font-size:13px;">
        <thead><tr><th class="no-sort">IdP</th><th class="no-sort">Issuer</th><th class="no-sort"><?= th('群組 claim') ?></th></tr></thead>
        <tbody>
          <tr><td>Keycloak</td><td class="mono">https://&lt;host&gt;/realms/&lt;realm&gt;</td><td><?= th('groups（需 Group Membership mapper，建議關閉完整路徑）') ?></td></tr>
          <tr><td>Microsoft Entra ID</td><td class="mono">https://login.microsoftonline.com/&lt;tenant&gt;/v2.0</td><td><?= th('groups（需在 Token configuration 加入群組；值為群組 ID）') ?></td></tr>
        </tbody>
      </table>
    </details>
  </div>

  <?php /* 會議室介面 */ ?>
  <?php $meeting_lang = Settings::getMeetingLangSetting(); $meeting_retention = Settings::getMeetingRetentionDays(); $audit_retention = Settings::getAuditRetentionDays(); $guest_poll = Settings::getGuestPollSeconds(); ?>
  <div id="card-meeting-ui" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('video', 18) ?><?= th('會議室介面') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('設定來賓 / 主持人進入會議室時的預設介面語言（會關閉瀏覽器語言自動偵測，強制使用此語言）。') ?></p>
    <form method="POST" action="/save-settings" class="inline-form">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="meeting">
      <div class="field"><label><?= th('預設 UI 語言') ?></label>
        <select name="meeting_lang">
          <option value="<?= Settings::MEETING_LANG_UI ?>" <?= $meeting_lang===Settings::MEETING_LANG_UI?'selected':'' ?>><?= th('跟隨介面語言（依每位使用者 / 來賓的語言）') ?></option>
          <?php foreach (Settings::MEETING_LANGS as $code => $label): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $meeting_lang===$code?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label><?= th('會議時長保留天數') ?></label>
        <input type="number" name="meeting_retention_days" min="7" max="3650" value="<?= (int)$meeting_retention ?>" style="width:120px;">
      </div>
      <div class="field"><label><?= th('稽核記錄保留天數') ?></label>
        <input type="number" name="audit_retention_days" min="30" max="3650" value="<?= (int)$audit_retention ?>" style="width:120px;">
      </div>
      <div class="field"><label><?= th('等候頁自動檢查間隔（秒）') ?></label>
        <input type="number" name="guest_poll_seconds" min="10" max="600" value="<?= (int)$guest_poll ?>" style="width:120px;">
      </div>
      <button class="btn btn-primary"><?= icon('check',14) ?><?= th('儲存') ?></button>
    </form>
    <p class="help" style="margin:10px 0 0;"><?= th('會議時長記錄（用量統計頁）保留最近 N 天，超過自動清除；預設 365 天。') ?><br><?= th('來賓在「等候主持人 / 倒數」頁每隔此秒數自動重新檢查是否可進入；預設 30 秒，範圍 10–600。') ?></p>
  </div>

  <?php /* 連線模式設定 */ ?>
  <div id="card-connection" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('link', 18) ?><?= th('連線模式設定') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= t('選擇使用 8x8 JaaS（雲端託管）或自建 Jitsi Meet。私鑰仍由主機掛載（<span class="mono">/var/www/html/keys/private.key</span>）。') ?></p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="jaas">
      <div class="field"><label><?= th('連線模式') ?></label>
        <select name="mode" id="jaasMode">
          <option value="jaas" <?= $jaas['mode']==='jaas'?'selected':'' ?>><?= th('8x8 JaaS（雲端託管）') ?></option>
          <option value="selfhosted" <?= $jaas['mode']==='selfhosted'?'selected':'' ?>><?= th('自建 Jitsi Meet') ?></option>
        </select>
      </div>
      <?php /* 共用 */ ?>
      <div class="field"><label><?= th('本系統對外網址（組邀請連結 / QR，兩模式共用）') ?></label>
        <input type="text" name="site_url" value="<?= htmlspecialchars($jaas['site_url']) ?>" placeholder="https://vc.example.com"></div>

      <?php /* JaaS 專用（與自建設定分開儲存，互不覆蓋） */ ?>
      <div class="mode-jaas">
        <div class="field"><label><?= th('服務網域') ?></label>
          <input type="text" name="domain" value="<?= htmlspecialchars($jaas['jaas_domain']) ?>" placeholder="8x8.vc"></div>
        <div class="field"><label>App ID <span class="muted" style="font-weight:400;"><?= th('（Tenant／App ID，必填）') ?></span></label>
          <input type="text" name="app_id" value="<?= htmlspecialchars($jaas['jaas_app_id']) ?>" placeholder="vpaas-magic-cookie-xxxxxxxx"></div>
        <div class="field"><label><?= th('Key ID（kid，JaaS JWT 簽章用，通常為 App ID/xxxxxx）') ?></label>
          <input type="text" name="kid" value="<?= htmlspecialchars($jaas['kid']) ?>" placeholder="vpaas-magic-cookie-xxxxxxxx/abc123"></div>
      </div>

      <?php /* 自建專用（與 JaaS 設定分開儲存，互不覆蓋） */ ?>
      <div class="mode-self">
        <div class="alert alert-info" style="align-items:flex-start;">
          <?= icon('info') ?>
          <span>
            <strong><?= th('切換成自建 Jitsi Meet 前，請先在你的 Jitsi 伺服器準備：') ?></strong><br>
            <?= t('① Jitsi 已部署且可由外網 <strong>HTTPS</strong> 存取，並允許本站載入其 <span class="mono">external_api.js</span>（CORS / 反向代理放行）。') ?><br>
            <?= t('② 下方「服務網域」填你的 Jitsi 網域（如 <span class="mono">meet.example.com</span>）。') ?><br>
            <?= t('③ 若採「需要 JWT」：Jitsi 的 <span class="mono">prosody</span> 需啟用 JWT token 驗證（<span class="mono">mod_auth_token</span> / <span class="mono">authentication = "token"</span>），且 <strong>App ID 與 app_id 一致、下方共享密鑰與 prosody 的 <span class="mono">app_secret</span> 一致</strong>，jicofo/prosody 設定完成。') ?><br>
            <?= th('④ 若採「不需 JWT」：Jitsi 須允許匿名加入（預設）。') ?><br>
            <?= t('⑤ 錄影需自建環境另架 <span class="mono">Jibri</span>；逐字稿 / 直播同理（本站工具列已預設關閉這兩項）。') ?><br>
            <?= t('<strong>完整設定步驟</strong>（從官方 Docker 版 Jitsi Meet 一路設到與本系統搭配，含 JWT、媒體後援 / TURN、Jibri 錄影）見 {link}。', ['link' => '<a href="' . htmlspecialchars(I18n::docUrl('JITSI-MEET-SETUP')) . '" target="_blank" rel="noopener">JITSI-MEET-SETUP.md</a>']) ?>
          </span>
        </div>
        <div class="field"><label><?= th('服務網域') ?></label>
          <input type="text" name="sh_domain" value="<?= htmlspecialchars($jaas['sh_domain']) ?>" placeholder="meet.example.com"></div>
        <div class="field"><label>App ID <span class="muted" style="font-weight:400;"><?= th('（JWT 的 aud / iss，可留空）') ?></span></label>
          <input type="text" name="sh_app_id" value="<?= htmlspecialchars($jaas['sh_app_id']) ?>" placeholder="jt-vc-portal"></div>
        <div class="field"><label><?= th('認證方式') ?></label>
          <select name="sh_auth">
            <option value="none" <?= $jaas['sh_auth']==='none'?'selected':'' ?>><?= th('不需 JWT（匿名加入）') ?></option>
            <option value="jwt" <?= $jaas['sh_auth']==='jwt'?'selected':'' ?>><?= th('需要 JWT（HS256）') ?></option>
          </select>
          <?php if ($jaas['mode'] === 'selfhosted' && $jaas['sh_auth'] === 'none'): ?>
            <div class="alert alert-warning" style="margin-top:8px;"><?= icon('warning') ?><span><?= th('目前未啟用 JWT：任何知道會議室名稱的人都能直接連到 Jitsi 網域進入會議，繞過本系統的等候、具名與排程控管。建議改為「需要 JWT」。') ?></span></div>
          <?php endif; ?>
        </div>
        <div class="field"><label><?= th('JWT 共享密鑰（HS256，須與 prosody 的 app_secret 一致）') ?></label>
          <input type="password" name="sh_secret" value="" autocomplete="new-password" placeholder="<?= $jaas['sh_secret'] !== '' ? th('已設定（留空不變更）') : th('自建 Jitsi Meet 的 app_secret') ?>"></div>
        <div class="field"><label><?= th('JWT sub（留空預設送 *）') ?></label>
          <input type="text" name="sh_sub" value="<?= htmlspecialchars($jaas['sh_sub']) ?>" placeholder="<?= th('通常為 * 或租戶名') ?>"></div>
      </div>

      <button class="btn btn-primary"><?= icon('check') ?><?= th('儲存連線設定') ?></button>
    </form>
    <script <?= nonce_attr() ?>>
      (function(){
        var sel = document.getElementById('jaasMode');
        function upd(){
          var m = sel.value;
          document.querySelectorAll('.mode-jaas').forEach(function(e){ e.style.display = (m==='jaas')?'':'none'; });
          document.querySelectorAll('.mode-self').forEach(function(e){ e.style.display = (m==='selfhosted')?'':'none'; });
          <?php /* 依下拉即時顯示對應的整張卡（會議室自訂＝自建；8x8 用量＝JaaS） */ ?>
          document.querySelectorAll('.js-card-selfhosted').forEach(function(e){ e.style.display = (m==='selfhosted')?'':'none'; });
          document.querySelectorAll('.js-card-jaas').forEach(function(e){ e.style.display = (m==='jaas')?'':'none'; });
        }
        sel.addEventListener('change', upd); upd();
      })();
    </script>
  </div>

  <?php /* 會議室自訂（僅自建 Jitsi Meet 模式；依連線模式下拉即時顯示） */ ?>
  <?php $mc = Settings::getMeetingCustom(); ?>
  <div id="card-meeting-custom" class="card settings-card js-card-selfhosted"<?= $jaas['mode']==='selfhosted' ? '' : ' style="display:none;"' ?>>
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('video', 18) ?><?= th('會議室自訂') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('自建 Jitsi Meet 模式專用：進入預設值與工具列功能。設定會在進入會議時帶入 Jitsi。') ?></p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="meeting_custom">

      <div class="field">
        <label><?= th('進入預設值') ?></label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="mute_audio" value="1" <?= $mc['mute_audio']?'checked':'' ?>> <?= th('進入時靜音麥克風') ?></label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="mute_video" value="1" <?= $mc['mute_video']?'checked':'' ?>> <?= th('進入時關閉鏡頭') ?></label>
      </div>
      <div class="field"><label><?= th('畫質上限') ?></label>
        <select name="resolution" style="width:160px;">
          <option value="720" <?= $mc['resolution']===720?'selected':'' ?>><?= th('720p（HD）') ?></option>
          <option value="1080" <?= $mc['resolution']===1080?'selected':'' ?>><?= th('1080p（Full HD）') ?></option>
        </select>
      </div>
      <div class="field"><label><?= th('預設檢視') ?></label>
        <select name="default_view" style="max-width:320px;">
          <option value="speaker" <?= $mc['default_view']==='speaker'?'selected':'' ?>><?= th('演講者檢視（大畫面 + 縮圖）') ?></option>
          <option value="tile" <?= $mc['default_view']==='tile'?'selected':'' ?>><?= th('畫廊檢視（並排格狀）') ?></option>
        </select>
        <div class="help"><?= th('進入會議時的預設版面；之後仍可在會議內自行切換。') ?></div>
      </div>
      <div class="field">
        <label><?= th('視訊頻寬') ?></label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="bw_save_off" value="1" <?= !empty($mc['bw_save_off'])?'checked':'' ?>> <?= th('關閉「視訊省頻寬」自動降載（不因頻寬自動關閉他人視訊）') ?></label>
        <div class="help"><?= th('預設勾選＝一律接收所有人視訊，畫質較好但較吃頻寬（網路差時可能改成卡頓）。取消勾選＝維持 Jitsi 省頻寬，網路差時自動關他人視訊以保流暢。') ?></div>
      </div>

      <div class="field">
        <label><?= th('工具列功能') ?></label>
        <div class="help" style="margin:0 0 8px;"><?= th('勾選要在會議室工具列開放的功能。基本功能（麥克風、鏡頭、掛斷、全螢幕、設定等）一律保留。') ?></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;">
          <?php foreach (Settings::toggleButtonLabels() as $key => $label): ?>
            <label style="font-weight:400;"><input type="checkbox" name="tb[<?= htmlspecialchars($key) ?>]" value="1" <?= !empty($mc['toolbar'][$key])?'checked':'' ?>> <?= htmlspecialchars($label) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="btn btn-primary"><?= icon('check') ?><?= th('儲存會議室自訂') ?></button>
    </form>
    <p class="help" style="margin:10px 0 0;"><?= t('提示：切換上方「連線模式」為自建即可即時預覽本卡，<strong>需按下「儲存連線設定」並維持自建模式</strong>，設定才會套用到會議。') ?></p>
  </div>

  <?php /* 錄製設定 */ ?>
  <?php
    $recorder_name = Settings::getRecorderName();
    $jibri  = Settings::getJibri();
    $jibri_has = Settings::hasJibri();
    $jstats = $jibri_has ? Recordings::stats() : null;   // 一次取回連線狀態 + 設定 + 錄製器數
    $jibri_ok = $jstats !== null;
    $rconf  = is_array($jstats['config'] ?? null) ? $jstats['config'] : [];
    $jrec   = $jstats['jibri']['recorders'] ?? null;
  ?>
  <div id="card-recording" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('video', 18) ?><?= th('錄製設定') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('錄製者顯示名稱、自建 Jibri 錄影服務串接與錄影保留政策。') ?></p>

    <form method="POST" action="/save-settings" class="inline-form">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="recording">
      <div class="field"><label><?= th('錄製者顯示名稱') ?></label>
        <input type="text" name="recorder_name" maxlength="40" value="<?= htmlspecialchars(I18n::isDefaultRecorder($recorder_name) ? '' : $recorder_name) ?>" placeholder="<?= th('會議錄影') ?>" style="max-width:280px;">
      </div>
      <button class="btn btn-primary"><?= icon('check',14) ?><?= th('儲存') ?></button>
    </form>
    <p class="help" style="margin:10px 0 0;"><?= th('此名稱實際是「未具名與會者」的預設顯示名稱；本系統的主持人與來賓一律具名，因此只有錄製者會套用到。留空則用「會議錄影」。') ?></p>

    <hr style="border:none;border-top:1px solid var(--border);margin:20px 0;">

    <h2 style="font-size:15px;margin:0 0 4px;"><?= th('Jibri 錄影服務') ?></h2>
    <p class="subtitle" style="margin:4px 0 12px;">
      <?= t('在自建 Jibri 主機上安裝 <span class="mono">jibri-recordings-api</span> 後即可串接——安裝步驟見 {link}。', [
        'link' => '<a href="' . htmlspecialchars(I18n::docUrl('JIBRI-SETUP', '六錄影檔與調閱jibri-recordings-api', '6-recording-files-and-playback-jibri-recordings-api', '6-録画ファイルと再生jibri-recordings-api')) . '" target="_blank" rel="noopener">JIBRI-SETUP.md</a>',  // i18n-ignore（GitHub 錨點）
      ]) ?><br>
      <?= th('偵測狀態：') ?>
      <?php if (!$jibri_has): ?><span class="badge badge-muted"><?= th('未設定') ?></span>
      <?php elseif ($jibri_ok): ?><span class="badge badge-success"><?= icon('check',11) ?><?= th('已連線') ?></span>
      <?php else: ?><span class="badge badge-warning"><?= icon('warning',11) ?><?= th('無法連線') ?></span><?php endif; ?>
      <?php if ($jibri_ok): ?><a href="/recordings" style="margin-left:8px;"><?= th('前往錄影記錄 →') ?></a><?php endif; ?>
      <?php if ($jibri_ok && $jrec !== null): ?>
        <br><?= t('已註冊錄製器：<strong>{n}</strong> 個（可同時錄製場數）。', ['n' => (int)$jrec]) ?>
        <a href="<?= htmlspecialchars(I18n::docUrl('JIBRI-SETUP', '四增減錄製器', '4-scaling-recorders-up-or-down', '4-録画機の増減')) ?>" target="_blank" rel="noopener"><?php /* i18n-ignore（GitHub 錨點） */ ?>
          <?= th('增減錄製器 →') ?></a>
      <?php endif; ?>
    </p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="jibri">
      <div class="field"><label><?= th('服務 URL') ?></label>
        <input type="url" name="jibri_url" value="<?= htmlspecialchars($jibri['url']) ?>" placeholder="http://10.0.0.10:9080" style="max-width:320px;">
        <div class="help"><?= th('自建 Jibri 主機上 jibri-recordings-api 的位址（含通訊埠，預設 9080）。') ?></div>
      </div>
      <div class="field"><label><?= th('存取 Token') ?></label>
        <input type="password" name="jibri_token" value="" placeholder="<?= $jibri['token'] !== '' ? th('已設定（留空不變更）') : th('尚未設定') ?>" autocomplete="new-password" style="max-width:320px;">
        <div class="help"><?= t('對應服務端 <span class="mono">/etc/jibri-recordings-api.env</span> 的 <span class="mono">API_TOKEN</span>。留空表示沿用既有。') ?></div>
      </div>
      <button class="btn btn-primary"><?= icon('check',14) ?><?= th('儲存並測試') ?></button>
    </form>

    <?php if ($jibri_ok):
      $r_time = !empty($rconf['time_enabled']); $r_days = (int)($rconf['time_days'] ?? 30);
      $r_cap  = !empty($rconf['cap_enabled']);  $r_mode = $rconf['cap_mode'] ?? 'min_free_gb'; $r_cap_gb = (int)($rconf['cap_value_gb'] ?? 10);
      $r_orp  = !empty($rconf['orphan_auto']);  $r_orp_h = (int)($rconf['orphan_age_hours'] ?? 24);
    ?>
    <hr style="border:none;border-top:1px solid var(--border);margin:20px 0;">
    <h2 style="font-size:15px;margin:0 0 4px;"><?= th('錄影保留政策') ?></h2>
    <p class="subtitle" style="margin:4px 0 12px;"><?= th('超過條件的錄影由服務端自動清理（預設全部停用）。錄製中的檔案永不清理。') ?></p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="recording_retention">
      <div class="field">
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="time_enabled" value="1" <?= $r_time?'checked':'' ?>> <?= t('依時間清理：保留最近 {input} 天，超過自動刪除', ['input' => '<input type="number" name="time_days" min="1" max="3650" value="' . $r_days . '" style="width:80px;">']) ?></label>
      </div>
      <div class="field">
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="cap_enabled" value="1" <?= $r_cap?'checked':'' ?>> <?= th('依容量清理：') ?>
          <select name="cap_mode" style="width:150px;">
            <option value="min_free_gb" <?= $r_mode==='min_free_gb'?'selected':'' ?>><?= th('保留可用空間') ?></option>
            <option value="max_used_gb" <?= $r_mode==='max_used_gb'?'selected':'' ?>><?= th('錄影總量上限') ?></option>
          </select>
          <input type="number" name="cap_value_gb" min="1" max="100000" value="<?= $r_cap_gb ?>" style="width:90px;"> <?= th('GB（由舊到新刪除）') ?></label>
      </div>
      <div class="field">
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="orphan_auto" value="1" <?= $r_orp?'checked':'' ?>> <?= t('自動清理殘留 / 未完成錄影：超過 {input} 小時', ['input' => '<input type="number" name="orphan_age_hours" min="1" max="8760" value="' . $r_orp_h . '" style="width:80px;">']) ?></label>
        <div class="help"><?= th('會議異常結束時可能留下無法播放的殘片，此選項會在指定時數後清除。') ?></div>
      </div>
      <button class="btn btn-primary"><?= icon('check',14) ?><?= th('儲存保留政策') ?></button>
    </form>
    <?php endif; ?>
  </div>

  <?php /* 8x8 用量 webhook（僅 JaaS 模式；依連線模式下拉即時顯示） */ ?>
  <?php $tx = Settings::getTranscribe(); $tx_fp = ($tx['jtlw_ca'] !== '' && ($x = @openssl_x509_read($tx['jtlw_ca']))) ? strtoupper(implode(':', str_split(openssl_x509_fingerprint($x, 'sha256'), 2))) : ''; ?>
  <div id="card-transcribe" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('file-text', 18) ?><?= th('逐字稿與摘要') ?></h1>
    <p class="subtitle" style="margin:6px 0 12px;"><?= th('錄影完成後，交給語音服務 jt-live-whisper（JTLW）產生逐字稿（含發言者）與會議摘要，結果存在本系統、跟著錄影的保留政策走。本系統不直接連接語言模型。') ?></p>
    <div class="help" style="margin-bottom:12px;"><?= th('誰可以使用：在「帳號管理」設定每位主持人的「逐字稿權限」（不可使用 / 手動 / 自動）；建立會議室時可單場開關；管理員可以對任何場次手動產生。自動產生只處理啟用之後錄的會議。') ?></div>
    <?php if (!Settings::hasJibri()): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= th('逐字稿需要自建 Jibri 錄影服務，請先完成錄製設定。') ?></span></div><?php endif; ?>
    <?php if ($tx['enabled'] && Requirements::workerLastRun() < time() - 600): ?>
      <div class="alert alert-error" id="txWorkerDown"><?= icon('warning') ?><span><?= th('背景排程沒有在執行（超過 10 分鐘沒有動靜），逐字稿不會送出也不會取回。請在主機加入每分鐘執行的排程（見 README「會議逐字稿與摘要」）：') ?>
        <br>Docker: <code>* * * * * root docker exec -u www-data jt-vc-portal php /var/www/html/transcribe-worker.php</code>
        <br><?= th('直接安裝') ?>: <code>* * * * * www-data php <?= htmlspecialchars(__DIR__) ?>/transcribe-worker.php</code></span></div>
    <?php endif; ?>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="transcribe">
      <div class="field"><label><input type="checkbox" name="enabled" value="1" <?= $tx['enabled'] ? 'checked' : '' ?>> <?= th('啟用逐字稿與摘要') ?></label></div>
      <div class="field-row">
        <div class="field"><label><?= th('語音服務（JTLW）網址') ?></label>
          <input type="url" name="jtlw_url" value="<?= htmlspecialchars($tx['jtlw_url']) ?>" placeholder="https://10.0.0.30:8790"></div>
        <div class="field"><label><?= th('API 金鑰') ?></label>
          <input type="password" name="jtlw_key" value="" autocomplete="new-password" placeholder="<?= $tx['jtlw_key'] !== '' ? th('已設定（留空不變更）') : 'jtlw_…' ?>"></div>
      </div>
      <div class="field"><label><?= th('語音服務的憑證（自簽憑證請貼 PEM；一律驗證，不關閉檢查）') ?></label>
        <textarea name="jtlw_ca" rows="4" class="mono" placeholder="-----BEGIN CERTIFICATE-----"><?= htmlspecialchars($tx['jtlw_ca']) ?></textarea>
        <?php if ($tx_fp !== ''): ?><div class="help mono" style="font-size:11px;"><?= th('SHA-256 指紋：{fp}（請與語音服務提供的指紋核對）', ['fp' => $tx_fp]) ?></div><?php endif; ?></div>
      <div class="field-row">
        <div class="field"><label><?= th('預設會議語言（建立會議室時沒指定的場次才用）') ?></label>
          <select name="language">
            <?php foreach (Transcripts::meetingLanguages() + ['auto' => t('自動判斷')] as $lv => $ll): ?>
              <option value="<?= $lv ?>" <?= $tx['language'] === $lv ? 'selected' : '' ?>><?= htmlspecialchars($ll) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help"><?= th('勾選產生逐字稿的會議，建立時必須選主要語言；這裡只用在舊會議或沒指定語言的場次。「自動判斷」只看開頭約 30 秒決定整場語言，開頭有人先講另一種語言時整場都可能辨識錯。會議摘要支援中文、英文與台語（閩南語）。') ?></div></div>
        <div class="field"><label><?= th('辨識模式') ?></label>
          <input type="text" name="profile_id" value="<?= htmlspecialchars($tx['profile_id']) ?>" placeholder="meeting.balanced">
          <div class="help"><?= th('一般會議用 meeting.balanced（目前唯一的會議模式；meeting.detailed 已停用，會自動改用 balanced）。台語（閩南語）會議不看這裡，依會議主要語言自動使用台語專用模式。') ?></div></div>
      </div>
      <div class="field"><label><input type="checkbox" name="summarize" value="1" <?= $tx['summarize'] ? 'checked' : '' ?>> <?= th('同時產生會議摘要（決議、待辦、風險、議題，附時間點）') ?></label></div>
      <div class="field"><label><?= th('完成通知（webhook）') ?></label>
        <input type="text" readonly class="mono" value="<?= htmlspecialchars(rtrim(SITE_URL, '/') . '/jtlw-webhook') ?>">
        <div class="help"><?= $tx['webhook_endpoint_id'] !== '' ? th('已註冊（{id}）。', ['id' => $tx['webhook_endpoint_id']]) : th('尚未註冊：先儲存金鑰，再按「註冊 webhook」。') ?> <?= th('沒有 webhook 也能運作：排程每分鐘會查詢一次進度。') ?></div></div>
      <?php if ($tx['enabled'] && !empty(Settings::getSection('transcribe')['auto_since'])): ?>
        <p class="help"><?= th('自動產生：處理 {t} 之後錄的會議。', ['t' => date('Y-m-d H:i', (int)Settings::getSection('transcribe')['auto_since'])]) ?></p>
      <?php endif; ?>
      <div class="btn-row" style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary" name="action" value="save"><?= icon('check') ?><?= th('儲存') ?></button>
        <button type="submit" class="btn btn-secondary" name="action" value="test"><?= icon('refresh') ?><?= th('儲存並測試連線') ?></button>
        <button type="submit" class="btn btn-secondary" name="action" value="webhook"><?= icon('link') ?><?= th('註冊 webhook') ?></button>
      </div>
    </form>
  </div>

  <div id="card-webhook" class="card settings-card js-card-jaas"<?= $jaas['mode']==='jaas' ? '' : ' style="display:none;"' ?>>
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('chart', 18) ?><?= th('8x8 用量 Webhook') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('在 8x8 JaaS Console → Webhooks 設定下列 endpoint 與 secret，即可開始計量 MAU。') ?></p>
    <div class="field"><label>Webhook URL</label>
      <div class="link-row">
        <input type="text" readonly value="<?= htmlspecialchars($webhook_url) ?>">
        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($webhook_url) ?>"><?= icon('copy',14) ?><?= th('複製') ?></button>
      </div>
    </div>
    <div class="field"><label>Signing Secret</label>
      <div class="link-row">
        <input type="text" readonly value="<?= htmlspecialchars($secret) ?>">
        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($secret) ?>"><?= icon('copy',14) ?><?= th('複製') ?></button>
      </div>
    </div>
    <form method="POST" action="/save-settings" class="inline-form" style="margin-top:6px;">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="plan">
      <div class="field"><label><?= th('方案 MAU 上限') ?></label>
        <input type="number" name="plan_mau_limit" min="1" value="<?= (int)$plan_limit ?>" style="min-width:120px;"></div>
      <div class="field"><label><?= th('計費週期每月起始日') ?></label>
        <input type="number" name="billing_start_day" min="1" max="28" value="<?= (int)$billing_day ?>" style="min-width:120px;"></div>
      <button class="btn btn-primary"><?= icon('check',14) ?><?= th('儲存') ?></button>
    </form>
    <p class="muted" style="font-size:12px;margin:10px 0 0;">
      <?= t('8x8 用量是依「訂閱週期」歸零（不是日曆月）。請把起始日設成與你 8x8 帳號相同（如 8x8 顯示 4/23–5/23 就填 <strong>23</strong>），計量才會對齊。目前本期：<strong>{period}</strong>。', ['period' => htmlspecialchars(Usage::currentPeriodLabel())]) ?>
    </p>

    <div class="section-title"><?= th('本期用量手動校正') ?></div>
    <p class="muted" style="font-size:12px;margin:0 0 10px;">
      <?= t('若在啟用 webhook 前本期已產生過 MAU，可在此填入目前實際值（參考 8x8 Console 的 Activity 頁），系統會以此為基準，之後每來一位新與會者再往上累加。目前顯示值：<strong>{n}</strong>。', ['n' => (int)$usage_now]) ?>
    </p>
    <form method="POST" action="/save-settings" class="inline-form">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="usage_baseline">
      <div class="field"><label><?= th('本期目前用量') ?></label>
        <input type="number" name="current_value" min="0" value="<?= (int)$usage_now ?>" style="min-width:120px;"></div>
      <button class="btn btn-primary"><?= icon('check',14) ?><?= th('校正本期用量') ?></button>
    </form>
  </div>

  <!-- SMTP -->
  <div id="card-smtp" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('calendar', 18) ?><?= th('SMTP 寄信（會議邀請 .ics）') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('啟用後，建立會議室時填寫的與會者 email 會收到含行事曆的邀請信。') ?></p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="smtp">
      <div class="field">
        <label><input type="checkbox" name="enabled" value="1" <?= $smtp['enabled']?'checked':'' ?>> <?= th('啟用 SMTP 寄信') ?></label>
      </div>
      <div class="field-row">
        <div class="field"><label><?= th('SMTP 主機') ?></label><input type="text" name="host" value="<?= htmlspecialchars($smtp['host']) ?>" placeholder="smtp.gmail.com"></div>
        <div class="field"><label><?= th('埠') ?></label><input type="number" name="port" value="<?= (int)$smtp['port'] ?>" placeholder="587"></div>
      </div>
      <div class="field-row">
        <div class="field"><label><?= th('加密') ?></label>
          <select name="security">
            <?php foreach (['none'=>t('無'),'starttls'=>'STARTTLS','tls'=>'SSL/TLS'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $smtp['security']===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label><?= th('寄件人名稱') ?></label><input type="text" name="from_name" value="<?= htmlspecialchars(I18n::isDefaultBrand($smtp['from_name']) ? '' : $smtp['from_name']) ?>" placeholder="<?= th('JT 視訊會議') ?>"></div>
      </div>
      <div class="field-row">
        <div class="field"><label><?= th('帳號（留空表示不認證）') ?></label><input type="text" name="username" value="<?= htmlspecialchars($smtp['username']) ?>" autocomplete="off"></div>
        <div class="field"><label><?= th('密碼') ?></label><input type="password" name="password" value="" autocomplete="new-password" placeholder="<?= $smtp['password'] !== '' ? th('已設定（留空不變更）') : '' ?>">
          <?php if ($smtp['password'] !== ''): ?><label style="font-weight:400;font-size:12px;"><input type="checkbox" name="password_clear" value="1"> <?= th('清除已儲存的密碼') ?></label><?php endif; ?></div>
      </div>
      <div class="field"><label><?= th('寄件人 Email') ?></label><input type="email" name="from_email" value="<?= htmlspecialchars($smtp['from_email']) ?>" placeholder="no-reply@example.com"></div>

      <div class="section-title"><?= th('郵件範本') ?></div>
      <p class="muted" style="font-size:12px;margin:0 0 10px;">
        <?= t('可用變數：<span class="kbd">{room}</span> 會議室名、<span class="kbd">{invite_url}</span> 邀請連結、<span class="kbd">{time}</span> 會議時間、<span class="kbd">{site_name}</span> 站台名稱、<span class="kbd">{host}</span> 主持人。') ?>
      </p>
      <div class="field"><label><?= th('郵件主旨範本') ?></label>
        <input type="text" name="subject_tpl" value="<?= htmlspecialchars($smtp['subject_tpl']) ?>" placeholder="<?= th('會議邀請：{room}') ?>"></div>
      <div class="field"><label><?= th('郵件內文範本') ?></label>
        <textarea name="body_tpl" rows="8" style="min-height:160px;"><?= htmlspecialchars($smtp['body_tpl']) ?></textarea></div>

      <div class="field"><label><?= th('測試收件人（按「測試寄信」時使用）') ?></label><input type="email" name="test_to" placeholder="you@example.com"></div>
      <div class="btn-group">
        <button class="btn btn-primary" name="action" value="save"><?= icon('check') ?><?= th('儲存') ?></button>
        <button class="btn btn-secondary" name="action" value="test"><?= icon('arrow-right') ?><?= th('儲存並測試寄信') ?></button>
      </div>
    </form>
  </div>

  <?php /* Log 外拋 */ ?>
  <div id="card-logship" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('upload', 18) ?><?= th('登入記錄外拋（SIEM）') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('將登入事件即時送往 syslog / CEF / GELF collector，支援 UDP / TCP。') ?></p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="logship">
      <div class="field">
        <label><input type="checkbox" name="enabled" value="1" <?= $ship['enabled']?'checked':'' ?>> <?= th('啟用外拋') ?></label>
      </div>
      <div class="field-row">
        <div class="field"><label><?= th('Collector 主機') ?></label><input type="text" name="host" value="<?= htmlspecialchars($ship['host']) ?>" placeholder="10.0.0.5"></div>
        <div class="field"><label><?= th('埠') ?></label><input type="number" name="port" value="<?= (int)$ship['port'] ?>" placeholder="514"></div>
      </div>
      <div class="field-row">
        <div class="field"><label><?= th('協定') ?></label>
          <select name="protocol">
            <?php foreach (['udp'=>'UDP','tcp'=>'TCP'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $ship['protocol']===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label><?= th('格式') ?></label>
          <select name="format">
            <?php foreach (['syslog'=>'Syslog (RFC5424)','cef'=>'CEF','gelf'=>'GELF'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $ship['format']===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field"><label><?= th('Syslog facility（0–23，預設 16 = local0）') ?></label>
        <input type="number" name="facility" min="0" max="23" value="<?= (int)$ship['facility'] ?>" style="width:120px;"></div>
      <div class="btn-group">
        <button class="btn btn-primary" name="action" value="save"><?= icon('check') ?><?= th('儲存') ?></button>
        <button class="btn btn-secondary" name="action" value="test"><?= icon('arrow-right') ?><?= th('儲存並送測試事件') ?></button>
      </div>
    </form>
  </div>

  <?php /* 外觀主題 */ ?>
  <?php
    $current_theme = Settings::getTheme();
    $themes = Settings::themeOptions();
  ?>
  <div id="card-theme" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('edit', 18) ?><?= th('外觀主題') ?></h1>
    <p class="subtitle" style="margin:6px 0 0;"><?= th('站台層級設定，套用到所有頁面（含來賓頁）。點選即時套用。') ?></p>
    <form method="POST" action="/set-theme" class="theme-picker" id="themeForm">
      <?= Auth::csrfField() ?>
      <?php foreach ($themes as $key => $info): ?>
        <label class="theme-option<?= $current_theme === $key ? ' active' : '' ?>">
          <input type="radio" name="theme" value="<?= htmlspecialchars($key) ?>" <?= $current_theme === $key ? 'checked' : '' ?>>
          <span class="theme-swatch theme-swatch-<?= htmlspecialchars($key) ?>"></span>
          <span class="theme-name"><?= htmlspecialchars($info['name']) ?></span>
          <span class="theme-desc"><?= htmlspecialchars($info['desc']) ?></span>
        </label>
      <?php endforeach; ?>
    </form>
    <script <?= nonce_attr() ?>>
      document.querySelectorAll('#themeForm input[name=theme]').forEach(el => {
        el.addEventListener('change', () => document.getElementById('themeForm').submit());
      });
    </script>
  </div>

  <?php /* 設定匯出 / 匯入 */ ?>
  <div id="card-backup" class="card settings-card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('upload', 18) ?><?= th('設定匯出 / 匯入') ?></h1>
    <p class="subtitle" style="margin:6px 0 18px;"><?= th('備份或搬移本系統的所有設定（含主題、連線模式、SMTP、外拋、會議室自訂、站台 logo、錄製/錄影服務、登入路徑、來賓等候等）。不含帳號、會議室與稽核記錄。') ?></p>
    <div class="alert alert-info" style="align-items:flex-start;">
      <?= icon('warning') ?>
      <span><?= t('匯出檔包含 <strong>機敏資訊</strong>（SMTP 密碼、JWT 共享密鑰、Webhook secret）。請妥善保管，勿外流或上傳到第三方。匯入會以檔案內容<strong>覆寫對應設定區塊</strong>。') ?></span>
    </div>
    <div class="field-row" style="align-items:flex-end;">
      <form method="POST" action="/settings-export">
        <?= Auth::csrfField() ?>
        <button class="btn btn-secondary"><?= icon('upload',14) ?><?= th('匯出設定（下載 JSON）') ?></button>
      </form>
    </div>
    <form method="POST" action="/settings-import" enctype="multipart/form-data" style="margin-top:14px;"
          data-confirm="<?= th('確定要匯入並覆寫目前設定嗎？建議先匯出一份現有設定備份。') ?>">
      <?= Auth::csrfField() ?>
      <div class="field">
        <label><?= th('選擇設定檔（.json）') ?></label>
        <div class="file-picker">
          <label class="file-btn"><?= icon('upload', 16) ?><?= th('選擇檔案') ?>
            <input type="file" name="settings_file" id="impInput" accept="application/json,.json" required>
          </label>
          <span class="file-name" id="impName"><?= th('未選擇檔案') ?></span>
        </div>
      </div>
      <button class="btn btn-primary"><?= icon('check') ?><?= th('匯入設定') ?></button>
    </form>
    <script <?= nonce_attr() ?>>
      (function(){
        var inp = document.getElementById('impInput');
        if (!inp) return;
        inp.addEventListener('change', function(){
          var f = inp.files && inp.files[0];
          document.getElementById('impName').textContent = f ? f.name : <?= json_encode(t('未選擇檔案')) ?>;
        });
      })();
    </script>
  </div>
  </div></div>
</main>

<div id="flash" class="copy-flash"><?= icon('check', 14) ?><?= th('已複製') ?></div>
<script <?= nonce_attr() ?>>
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]'); if (!btn) return;
  try { await navigator.clipboard.writeText(btn.getAttribute('data-copy')); } catch(_) {}
  const f = document.getElementById('flash'); f.classList.add('show');
  clearTimeout(window.__ft); window.__ft = setTimeout(()=>f.classList.remove('show'),1400);
});
</script>
<?php render_foot(); ?>
