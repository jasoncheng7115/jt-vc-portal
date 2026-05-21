<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/logship.php';
require_once __DIR__ . '/lib/usage.php';
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

render_head('系統設定');
render_topbar($me, $ip);
?>
<main class="container">
  <?= admin_nav('settings') ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= icon('check') ?><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= icon('warning') ?><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

  <!-- 站台設定 -->
  <?php $brand = site_brand(); ?>
  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('home', 18) ?>站台設定</h1>
    <p class="subtitle" style="margin:6px 0 18px;">設定左上角 logo 與站台名稱，套用到所有頁面（含登入頁、來賓頁）。</p>
    <form method="POST" action="/save-site" enctype="multipart/form-data">
      <?= Auth::csrfField() ?>
      <div class="field"><label>站台名稱</label>
        <input type="text" name="brand_name" maxlength="40" value="<?= htmlspecialchars($brand['brand_name']) ?>" placeholder="JT 視訊會議"></div>
      <div class="field">
        <label>目前 logo</label>
        <div class="file-picker">
          <img id="logoPreview" class="logo-preview" src="<?= htmlspecialchars($brand['logo_src']) ?>?v=<?= (int)$brand['logo_v'] ?>" alt="logo">
          <label class="file-btn"><?= icon('upload', 16) ?>選擇圖片
            <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/webp,image/gif">
          </label>
          <span class="file-name" id="logoName">未選擇檔案</span>
        </div>
        <div class="help">建議正方形 PNG，小於 2MB。留空則不更動。</div>
      </div>
      <script>
        (function(){
          var inp = document.getElementById('logoInput');
          if (!inp) return;
          inp.addEventListener('change', function(){
            var f = inp.files && inp.files[0];
            document.getElementById('logoName').textContent = f ? f.name : '未選擇檔案';
            if (f) document.getElementById('logoPreview').src = URL.createObjectURL(f);
          });
        })();
      </script>
      <?php if ($brand['logo_mime'] !== ''): ?>
      <div class="field">
        <label style="font-weight:400;"><input type="checkbox" name="remove_logo" value="1"> 恢復成預設 logo</label>
      </div>
      <?php endif; ?>
      <button class="btn btn-primary"><?= icon('check') ?>儲存站台設定</button>
    </form>
  </div>

  <!-- 會議室介面 -->
  <?php $meeting_lang = Settings::getMeetingLang(); $meeting_retention = Settings::getMeetingRetentionDays(); ?>
  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('video', 18) ?>會議室介面</h1>
    <p class="subtitle" style="margin:6px 0 18px;">設定來賓 / 主持人進入會議室時的預設介面語言（會關閉瀏覽器語言自動偵測，強制使用此語言）。</p>
    <form method="POST" action="/save-settings" class="inline-form">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="meeting">
      <div class="field"><label>預設 UI 語言</label>
        <select name="meeting_lang">
          <?php foreach (Settings::MEETING_LANGS as $code => $label): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $meeting_lang===$code?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>會議時長保留天數</label>
        <input type="number" name="meeting_retention_days" min="7" max="3650" value="<?= (int)$meeting_retention ?>" style="width:120px;">
      </div>
      <button class="btn btn-secondary"><?= icon('check',14) ?>儲存</button>
    </form>
    <p class="help" style="margin:10px 0 0;">會議時長記錄（用量統計頁）保留最近 N 天，超過自動清除；預設 365 天。</p>
  </div>

  <!-- 會議室自訂（僅自建 Jitsi Meet 模式；依連線模式下拉即時顯示） -->
  <?php $mc = Settings::getMeetingCustom(); ?>
  <div class="card js-card-selfhosted"<?= $jaas['mode']==='selfhosted' ? '' : ' style="display:none;"' ?>>
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('video', 18) ?>會議室自訂</h1>
    <p class="subtitle" style="margin:6px 0 18px;">自建 Jitsi Meet 模式專用：自訂會議室左上 logo、進入預設值與工具列功能。設定會在進入會議時帶入 Jitsi。</p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="meeting_custom">

      <div class="field">
        <label>會議室左上 logo</label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="radio" name="logo_mode" value="site" <?= $mc['logo_mode']==='site'?'checked':'' ?>> 沿用本系統 jt-vc-portal 的站台 logo（即「系統設定 → 站台設定」上傳的那個）</label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="radio" name="logo_mode" value="custom" <?= $mc['logo_mode']==='custom'?'checked':'' ?>> 自訂 logo 網址</label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="radio" name="logo_mode" value="none" <?= $mc['logo_mode']==='none'?'checked':'' ?>> 不顯示 logo</label>
      </div>
      <div class="field" id="fieldLogoUrl"><label>自訂 logo 網址</label>
        <input type="url" name="logo_url" value="<?= htmlspecialchars($mc['logo_url']) ?>" placeholder="https://example.com/logo.png">
        <div class="help">選「自訂 logo 網址」時生效，需為公開可存取的圖片網址。</div>
      </div>
      <div class="field" id="fieldLogoLink"><label>logo 點擊連結</label>
        <input type="url" name="logo_link" value="<?= htmlspecialchars($mc['logo_link']) ?>" placeholder="https://example.com">
        <div class="help">點按會議室 logo 時開啟的網址；留空則用本系統對外網址。</div>
      </div>
      <script>
        (function(){
          var radios = document.querySelectorAll('input[name="logo_mode"]');
          var fUrl = document.getElementById('fieldLogoUrl');
          var fLink = document.getElementById('fieldLogoLink');
          function upd(){
            var m = (document.querySelector('input[name="logo_mode"]:checked') || {}).value;
            if (fUrl)  fUrl.style.display  = (m === 'custom') ? '' : 'none';   // 僅「自訂網址」顯示
            if (fLink) fLink.style.display = (m === 'none')   ? 'none' : '';   // 「不顯示 logo」時連結也隱藏
          }
          radios.forEach(function(r){ r.addEventListener('change', upd); });
          upd();
        })();
      </script>

      <div class="field">
        <label>進入預設值</label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="mute_audio" value="1" <?= $mc['mute_audio']?'checked':'' ?>> 進入時靜音麥克風</label>
        <label style="font-weight:400;display:block;margin:4px 0;"><input type="checkbox" name="mute_video" value="1" <?= $mc['mute_video']?'checked':'' ?>> 進入時關閉鏡頭</label>
      </div>
      <div class="field"><label>畫質上限</label>
        <select name="resolution" style="width:160px;">
          <option value="720" <?= $mc['resolution']===720?'selected':'' ?>>720p（HD）</option>
          <option value="1080" <?= $mc['resolution']===1080?'selected':'' ?>>1080p（Full HD）</option>
        </select>
      </div>

      <div class="field">
        <label>工具列功能</label>
        <div class="help" style="margin:0 0 8px;">勾選要在會議室工具列開放的功能。基本功能（麥克風、鏡頭、掛斷、全螢幕、設定等）一律保留。</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;">
          <?php foreach (Settings::MEETING_TOGGLE_BUTTONS as $key => $label): ?>
            <label style="font-weight:400;"><input type="checkbox" name="tb[<?= htmlspecialchars($key) ?>]" value="1" <?= !empty($mc['toolbar'][$key])?'checked':'' ?>> <?= htmlspecialchars($label) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="btn btn-primary"><?= icon('check') ?>儲存會議室自訂</button>
    </form>
    <p class="help" style="margin:10px 0 0;">提示：切換上方「連線模式」為自建即可即時預覽本卡，<strong>需按下「儲存連線設定」並維持自建模式</strong>，設定才會套用到會議。</p>
  </div>

  <!-- 連線模式設定 -->
  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('link', 18) ?>連線模式設定</h1>
    <p class="subtitle" style="margin:6px 0 18px;">選擇使用 8x8 JaaS（雲端託管）或自建 Jitsi Meet。私鑰仍由主機掛載（<span class="mono">/var/www/html/keys/private.key</span>）。</p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="jaas">
      <div class="field"><label>連線模式</label>
        <select name="mode" id="jaasMode">
          <option value="jaas" <?= $jaas['mode']==='jaas'?'selected':'' ?>>8x8 JaaS（雲端託管）</option>
          <option value="selfhosted" <?= $jaas['mode']==='selfhosted'?'selected':'' ?>>自建 Jitsi Meet</option>
        </select>
      </div>
      <!-- 共用 -->
      <div class="field"><label>本系統對外網址（組邀請連結 / QR，兩模式共用）</label>
        <input type="text" name="site_url" value="<?= htmlspecialchars($jaas['site_url']) ?>" placeholder="https://vc.example.com"></div>

      <!-- JaaS 專用（與自建設定分開儲存，互不覆蓋） -->
      <div class="mode-jaas">
        <div class="field"><label>服務網域</label>
          <input type="text" name="domain" value="<?= htmlspecialchars($jaas['jaas_domain']) ?>" placeholder="8x8.vc"></div>
        <div class="field"><label>App ID <span class="muted" style="font-weight:400;">（Tenant／App ID，必填）</span></label>
          <input type="text" name="app_id" value="<?= htmlspecialchars($jaas['jaas_app_id']) ?>" placeholder="vpaas-magic-cookie-xxxxxxxx"></div>
        <div class="field"><label>Key ID（kid，JaaS JWT 簽章用，通常為 App ID/xxxxxx）</label>
          <input type="text" name="kid" value="<?= htmlspecialchars($jaas['kid']) ?>" placeholder="vpaas-magic-cookie-xxxxxxxx/abc123"></div>
      </div>

      <!-- 自建專用（與 JaaS 設定分開儲存，互不覆蓋） -->
      <div class="mode-self">
        <div class="alert alert-info" style="align-items:flex-start;">
          <?= icon('info') ?>
          <span>
            <strong>切換成自建 Jitsi Meet 前，請先在你的 Jitsi 伺服器準備：</strong><br>
            ① Jitsi 已部署且可由外網 <strong>HTTPS</strong> 存取，並允許本站載入其 <span class="mono">external_api.js</span>（CORS / 反向代理放行）。<br>
            ② 下方「服務網域」填你的 Jitsi 網域（如 <span class="mono">meet.example.com</span>）。<br>
            ③ 若採「需要 JWT」：Jitsi 的 <span class="mono">prosody</span> 需啟用 JWT token 驗證（<span class="mono">mod_auth_token</span> / <span class="mono">authentication = "token"</span>），且 <strong>App ID 與 app_id 一致、下方共享密鑰與 prosody 的 <span class="mono">app_secret</span> 一致</strong>，jicofo/prosody 設定完成。<br>
            ④ 若採「不需 JWT」：Jitsi 須允許匿名加入（預設）。<br>
            ⑤ 錄影需自建環境另架 <span class="mono">Jibri</span>；逐字稿 / 直播同理（本站工具列已預設關閉這兩項）。
          </span>
        </div>
        <div class="field"><label>服務網域</label>
          <input type="text" name="sh_domain" value="<?= htmlspecialchars($jaas['sh_domain']) ?>" placeholder="meet.example.com"></div>
        <div class="field"><label>App ID <span class="muted" style="font-weight:400;">（JWT 的 aud / iss，可留空）</span></label>
          <input type="text" name="sh_app_id" value="<?= htmlspecialchars($jaas['sh_app_id']) ?>" placeholder="jt-vc-portal"></div>
        <div class="field"><label>認證方式</label>
          <select name="sh_auth">
            <option value="none" <?= $jaas['sh_auth']==='none'?'selected':'' ?>>不需 JWT（匿名加入）</option>
            <option value="jwt" <?= $jaas['sh_auth']==='jwt'?'selected':'' ?>>需要 JWT（HS256）</option>
          </select>
        </div>
        <div class="field"><label>JWT 共享密鑰（HS256，須與 prosody 的 app_secret 一致）</label>
          <input type="password" name="sh_secret" value="<?= htmlspecialchars($jaas['sh_secret']) ?>" autocomplete="new-password" placeholder="自建 Jitsi Meet 的 app_secret"></div>
        <div class="field"><label>JWT sub（留空預設送 *）</label>
          <input type="text" name="sh_sub" value="<?= htmlspecialchars($jaas['sh_sub']) ?>" placeholder="通常為 * 或租戶名"></div>
      </div>

      <button class="btn btn-primary"><?= icon('check') ?>儲存連線設定</button>
    </form>
    <script>
      (function(){
        var sel = document.getElementById('jaasMode');
        function upd(){
          var m = sel.value;
          document.querySelectorAll('.mode-jaas').forEach(function(e){ e.style.display = (m==='jaas')?'':'none'; });
          document.querySelectorAll('.mode-self').forEach(function(e){ e.style.display = (m==='selfhosted')?'':'none'; });
          // 依下拉即時顯示對應的整張卡（會議室自訂＝自建；8x8 用量＝JaaS）
          document.querySelectorAll('.js-card-selfhosted').forEach(function(e){ e.style.display = (m==='selfhosted')?'':'none'; });
          document.querySelectorAll('.js-card-jaas').forEach(function(e){ e.style.display = (m==='jaas')?'':'none'; });
        }
        sel.addEventListener('change', upd); upd();
      })();
    </script>
  </div>

  <!-- 8x8 用量 webhook（僅 JaaS 模式；依連線模式下拉即時顯示） -->
  <div class="card js-card-jaas"<?= $jaas['mode']==='jaas' ? '' : ' style="display:none;"' ?>>
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('chart', 18) ?>8x8 用量 Webhook</h1>
    <p class="subtitle" style="margin:6px 0 18px;">在 8x8 JaaS Console → Webhooks 設定下列 endpoint 與 secret，即可開始計量 MAU。</p>
    <div class="field"><label>Webhook URL</label>
      <div class="link-row">
        <input type="text" readonly value="<?= htmlspecialchars($webhook_url) ?>">
        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($webhook_url) ?>"><?= icon('copy',14) ?>複製</button>
      </div>
    </div>
    <div class="field"><label>Signing Secret</label>
      <div class="link-row">
        <input type="text" readonly value="<?= htmlspecialchars($secret) ?>">
        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($secret) ?>"><?= icon('copy',14) ?>複製</button>
      </div>
    </div>
    <form method="POST" action="/save-settings" class="inline-form" style="margin-top:6px;">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="plan">
      <div class="field"><label>方案 MAU 上限</label>
        <input type="number" name="plan_mau_limit" min="1" value="<?= (int)$plan_limit ?>" style="min-width:120px;"></div>
      <div class="field"><label>計費週期每月起始日</label>
        <input type="number" name="billing_start_day" min="1" max="28" value="<?= (int)$billing_day ?>" style="min-width:120px;"></div>
      <button class="btn btn-secondary"><?= icon('check',14) ?>儲存</button>
    </form>
    <p class="muted" style="font-size:12px;margin:10px 0 0;">
      8x8 用量是依「訂閱週期」歸零（不是日曆月）。請把起始日設成與你 8x8 帳號相同（如 8x8 顯示 4/23–5/23 就填 <strong>23</strong>），計量才會對齊。目前本期：<strong><?= htmlspecialchars(Usage::currentPeriodLabel()) ?></strong>。
    </p>

    <div class="section-title">本期用量手動校正</div>
    <p class="muted" style="font-size:12px;margin:0 0 10px;">
      若在啟用 webhook 前本期已產生過 MAU，可在此填入目前實際值（參考 8x8 Console 的 Activity 頁），
      系統會以此為基準，之後每來一位新與會者再往上累加。目前顯示值：<strong><?= (int)$usage_now ?></strong>。
    </p>
    <form method="POST" action="/save-settings" class="inline-form">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="usage_baseline">
      <div class="field"><label>本期目前用量</label>
        <input type="number" name="current_value" min="0" value="<?= (int)$usage_now ?>" style="min-width:120px;"></div>
      <button class="btn btn-secondary"><?= icon('check',14) ?>校正本期用量</button>
    </form>
  </div>

  <!-- SMTP -->
  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('calendar', 18) ?>SMTP 寄信（會議邀請 .ics）</h1>
    <p class="subtitle" style="margin:6px 0 18px;">啟用後，建立會議室時填寫的與會者 email 會收到含行事曆的邀請信。</p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="smtp">
      <div class="field">
        <label><input type="checkbox" name="enabled" value="1" <?= $smtp['enabled']?'checked':'' ?>> 啟用 SMTP 寄信</label>
      </div>
      <div class="field-row">
        <div class="field"><label>SMTP 主機</label><input type="text" name="host" value="<?= htmlspecialchars($smtp['host']) ?>" placeholder="smtp.gmail.com"></div>
        <div class="field"><label>埠</label><input type="number" name="port" value="<?= (int)$smtp['port'] ?>" placeholder="587"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>加密</label>
          <select name="security">
            <?php foreach (['none'=>'無','starttls'=>'STARTTLS','tls'=>'SSL/TLS'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $smtp['security']===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>寄件人名稱</label><input type="text" name="from_name" value="<?= htmlspecialchars($smtp['from_name']) ?>"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>帳號（留空表示不認證）</label><input type="text" name="username" value="<?= htmlspecialchars($smtp['username']) ?>" autocomplete="off"></div>
        <div class="field"><label>密碼</label><input type="password" name="password" value="<?= htmlspecialchars($smtp['password']) ?>" autocomplete="new-password"></div>
      </div>
      <div class="field"><label>寄件人 Email</label><input type="email" name="from_email" value="<?= htmlspecialchars($smtp['from_email']) ?>" placeholder="no-reply@example.com"></div>

      <div class="section-title">郵件範本</div>
      <p class="muted" style="font-size:12px;margin:0 0 10px;">
        可用變數：<span class="kbd">{room}</span> 會議室名、<span class="kbd">{invite_url}</span> 邀請連結、
        <span class="kbd">{time}</span> 會議時間、<span class="kbd">{site_name}</span> 站台名稱、<span class="kbd">{host}</span> 主持人。
      </p>
      <div class="field"><label>郵件主旨範本</label>
        <input type="text" name="subject_tpl" value="<?= htmlspecialchars($smtp['subject_tpl']) ?>" placeholder="會議邀請：{room}"></div>
      <div class="field"><label>郵件內文範本</label>
        <textarea name="body_tpl" rows="8" style="min-height:160px;"><?= htmlspecialchars($smtp['body_tpl']) ?></textarea></div>

      <div class="field"><label>測試收件人（按「測試寄信」時使用）</label><input type="email" name="test_to" placeholder="you@example.com"></div>
      <div class="btn-group">
        <button class="btn btn-primary" name="action" value="save"><?= icon('check') ?>儲存</button>
        <button class="btn btn-secondary" name="action" value="test"><?= icon('arrow-right') ?>儲存並測試寄信</button>
      </div>
    </form>
  </div>

  <!-- Log 外拋 -->
  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('upload', 18) ?>登入記錄外拋（SIEM）</h1>
    <p class="subtitle" style="margin:6px 0 18px;">將登入事件即時送往 syslog / CEF / GELF collector，支援 UDP / TCP。</p>
    <form method="POST" action="/save-settings">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="section" value="logship">
      <div class="field">
        <label><input type="checkbox" name="enabled" value="1" <?= $ship['enabled']?'checked':'' ?>> 啟用外拋</label>
      </div>
      <div class="field-row">
        <div class="field"><label>Collector 主機</label><input type="text" name="host" value="<?= htmlspecialchars($ship['host']) ?>" placeholder="10.0.0.5"></div>
        <div class="field"><label>埠</label><input type="number" name="port" value="<?= (int)$ship['port'] ?>" placeholder="514"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>協定</label>
          <select name="protocol">
            <?php foreach (['udp'=>'UDP','tcp'=>'TCP'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $ship['protocol']===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>格式</label>
          <select name="format">
            <?php foreach (['syslog'=>'Syslog (RFC5424)','cef'=>'CEF','gelf'=>'GELF'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $ship['format']===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field"><label>Syslog facility（0–23，預設 16 = local0）</label>
        <input type="number" name="facility" min="0" max="23" value="<?= (int)$ship['facility'] ?>" style="width:120px;"></div>
      <div class="btn-group">
        <button class="btn btn-primary" name="action" value="save"><?= icon('check') ?>儲存</button>
        <button class="btn btn-secondary" name="action" value="test"><?= icon('arrow-right') ?>儲存並送測試事件</button>
      </div>
    </form>
  </div>

  <!-- 外觀主題 -->
  <?php
    $current_theme = Settings::getTheme();
    $themes = Settings::themeOptions();
  ?>
  <div class="card" id="theme">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('edit', 18) ?>外觀主題</h1>
    <p class="subtitle" style="margin:6px 0 0;">站台層級設定，套用到所有頁面（含來賓頁）。點選即時套用。</p>
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
    <script>
      document.querySelectorAll('#themeForm input[name=theme]').forEach(el => {
        el.addEventListener('change', () => document.getElementById('themeForm').submit());
      });
    </script>
  </div>

  <!-- 設定匯出 / 匯入 -->
  <div class="card">
    <h1 style="font-size:18px;margin:0 0 4px;"><?= icon('upload', 18) ?>設定匯出 / 匯入</h1>
    <p class="subtitle" style="margin:6px 0 18px;">備份或搬移本系統的所有設定（含主題、連線模式、SMTP、外拋、會議室自訂、站台 logo 圖檔）。不含帳號、會議室與稽核記錄。</p>
    <div class="alert alert-info" style="align-items:flex-start;">
      <?= icon('warning') ?>
      <span>匯出檔包含 <strong>機敏資訊</strong>（SMTP 密碼、JWT 共享密鑰、Webhook secret）。請妥善保管，勿外流或上傳到第三方。匯入會以檔案內容<strong>覆寫對應設定區塊</strong>。</span>
    </div>
    <div class="field-row" style="align-items:flex-end;">
      <form method="POST" action="/settings-export">
        <?= Auth::csrfField() ?>
        <button class="btn btn-secondary"><?= icon('upload',14) ?>匯出設定（下載 JSON）</button>
      </form>
    </div>
    <form method="POST" action="/settings-import" enctype="multipart/form-data" style="margin-top:14px;"
          onsubmit="return confirm('確定要匯入並覆寫目前設定嗎？建議先匯出一份現有設定備份。');">
      <?= Auth::csrfField() ?>
      <div class="field">
        <label>選擇設定檔（.json）</label>
        <div class="file-picker">
          <label class="file-btn"><?= icon('upload', 16) ?>選擇檔案
            <input type="file" name="settings_file" id="impInput" accept="application/json,.json" required>
          </label>
          <span class="file-name" id="impName">未選擇檔案</span>
        </div>
      </div>
      <button class="btn btn-primary"><?= icon('check') ?>匯入設定</button>
    </form>
    <script>
      (function(){
        var inp = document.getElementById('impInput');
        if (!inp) return;
        inp.addEventListener('change', function(){
          var f = inp.files && inp.files[0];
          document.getElementById('impName').textContent = f ? f.name : '未選擇檔案';
        });
      })();
    </script>
  </div>
</main>

<div id="flash" class="copy-flash"><?= icon('check', 14) ?>已複製</div>
<script>
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]'); if (!btn) return;
  try { await navigator.clipboard.writeText(btn.getAttribute('data-copy')); } catch(_) {}
  const f = document.getElementById('flash'); f.classList.add('show');
  clearTimeout(window.__ft); window.__ft = setTimeout(()=>f.classList.remove('show'),1400);
});
</script>
<?php render_foot(); ?>
