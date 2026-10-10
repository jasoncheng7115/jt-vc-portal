<p align="center"><img src="docs/images/icon.svg" alt="jt-vc-portal" width="96" height="96"></p>

# jt-vc-portal v1.19.0 — 會議管理系統

> English: [README.md](README.md) · 日本語: [README_ja.md](README_ja.md)

> 強化 Jitsi Meet 基底的會議入口系統，**雙模式**支援 [8x8 JaaS](https://jaas.8x8.vc/)[^8x8]（雲端託管）與 **[自建 Jitsi Meet](https://github.com/jitsi/jitsi-meet)**。
> 主持人登入後即可建立會議室、產生邀請連結（含 QR / `.ics` 行事曆邀請），來賓經由邀請連結加入。
> 內建多帳號 / 角色 / 2FA、完整稽核記錄與 SIEM 外拋、fail2ban、預約時段等企業功能。

[^8x8]: **8x8** 自 2018 年起為 Jitsi / Jitsi Meet 的開發與維護公司；**8x8 JaaS（Jitsi as a Service）** 即其官方雲端託管的 Jitsi 服務。

**介紹頁 / Demo：** <https://jasoncheng7115.github.io/jt-vc-portal/>

![License](https://img.shields.io/badge/License-GPL--3.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4.svg)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED.svg)
![OWASP](https://img.shields.io/badge/OWASP-Top_10_2025-success.svg)
![Dependencies](https://img.shields.io/badge/PHP_deps-zero-brightgreen.svg)

---

## 功能特色

- **雙連線模式**：8x8 JaaS（雲端託管，RS256 + kid）或自建 Jitsi Meet（HS256 或免 JWT），於管理介面一鍵切換，不動程式碼。
- **會議室管理**：建立 / 進入 / 刪除會議室（受邀者會收到行事曆取消通知）、亂數命名、近期清單、一鍵複製邀請、QR Code 彈窗。
- **預約時段**：可設定開放時段；時段未到顯示翻頁時鐘倒數，主持人提前進入即自動放行。
- **來賓流程**：來賓必填顯示名稱才進場；非開放時段顯示等候 / 倒數 / 已結束頁。
- **大廳模式**：建立會議室時可選；主持人進場自動開啟，來賓需經主持人逐一允許才能進入會議室。
- **Email 邀請**：填寫與會者 email，寄送含 `.ics`（METHOD:REQUEST）的邀請信，可一鍵加入行事曆。
- **多帳號 / 角色 / 2FA**：admin 看全部、host 只看自己建立的會議室；支援 TOTP 雙因素認證。
- **單一登入（OIDC）**：主持人與管理員可透過 Keycloak / Entra ID 以公司帳號登入（AD 群組對應角色、MFA 由 IdP 負責）；portal 不直接連 AD / LDAP。支援「僅限單一登入」並保留限 IP 的緊急用管理員。設定步驟：[KEYCLOAK-SETUP_zh-TW.md](KEYCLOAK-SETUP_zh-TW.md)。
- **會議逐字稿與摘要（jt-live-whisper）**：自建 Jibri 錄影完成後，portal 可將錄影交給 [jt-live-whisper](https://github.com/jasoncheng7115/jt-live-whisper)（JTLW）產生標示發言者的逐字稿與會議摘要——重點摘要、決議與待辦、事件、風險、未決問題、議題與發言統計，每一條都附錄影中的時間點。依帳號設定權限（不可使用 / 手動 / 自動）、可單場開關，管理員可對任何場次產生。檢視頁提供波形播放器、點時間跳到該處、發言者改名與發言者對應建議（依 Jitsi 發言時間軸），整份會議記錄可匯出 PDF / DOCX / ODT / HTML（另有 TXT / SRT / JSON / Markdown）。portal 本身不直接連接語言模型。完整設定見 [TRANSCRIPTS-SETUP_zh-TW.md](TRANSCRIPTS-SETUP_zh-TW.md)。
- **稽核與安全**：完整行為稽核記錄（登入、建室、邀請、設定變更…）+ 即時外拋 syslog / CEF / GELF；fail2ban 登入鎖定；CSRF；遵循 OWASP Top 10:2025。
- **錄影調閱**（自建 Jibri）：串接 Jibri 主機的錄影服務，線上列表 / 播放 / 下載 / 刪除、主機容量、保留政策（時間 / 容量 / 殘留，預設停用）；主持人可調閱自己主持會議的錄影。
- **多語系介面**：portal 介面支援繁體中文、English 與日本語，依瀏覽器語言自動判斷，可從右上帳號選單或個人設定切換；Jitsi 會議語言可設為跟隨介面語言。
- **可自訂外觀**：60 種 Jitsi 介面語言、22 種主題、可換站台名稱與 logo、登入頁路徑偽裝。
- **會議統計**：會議時長排行、尖峰同時人數與參與者進出時間軸；JaaS 模式另可接 USAGE webhook 統計 MAU。
- **零外部 PHP 套件**：核心全部手寫，無 composer 相依（前端僅用 CDN 的 qrcodejs / flatpickr）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 系統需求

| 項目 | 最低 | 建議 |
|---|---|---|
| PHP | 8.3 | **8.4** |
| Web 伺服器 | Apache + `mod_rewrite`（`AllowOverride All`） | 同左 |
| PHP 擴充 | `openssl`、`fileinfo`、`json`、`mbstring`、`curl`、`zlib` | 同左 |
| 其他 | 可寫入的資料目錄；JaaS 模式需 8x8 私鑰 | Docker 24+ |

> 自建 Jitsi Meet 模式另需一台可用的 Jitsi Meet 伺服器（詳見「連線模式」）。

> **PHP 8.2 不再列為支援**：PHP 官方對 8.2 只剩安全修補，到 2026-12-31 結束（EOL）。直接安裝的站台請升到 8.3 以上（8.3 安全修補到 2027-12-31、8.4 到 2028-12-31）；Docker 與 Release 映像已是 8.4，不受影響。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 安裝方式一：直接安裝（Apache + PHP）

```bash
# 1) 取得程式
git clone https://github.com/jasoncheng7115/jt-vc-portal.git
cd jt-vc-portal

# 2) 啟用 Apache 模組與 .htaccess（Debian/Ubuntu 範例）
a2enmod rewrite
# 確認站台設定為 AllowOverride All，DocumentRoot 指向本資料夾

# 3) 啟用 .htaccess（本專案以 dot.htaccess 收錄，避免誤觸）
cp dot.htaccess .htaccess

# 4) 建立持久化資料目錄（預設 /var/jaas-data，可於 config.php 的 DATA_DIR 調整）
sudo mkdir -p /var/jaas-data
sudo chown www-data:www-data /var/jaas-data

# 5)（JaaS 模式才需要）放置 8x8 私鑰
sudo mkdir -p keys
sudo cp /path/to/your/private.key keys/private.key
```

Apache 站台設定範例（`/etc/apache2/sites-available/jt-vc-portal.conf`）：

```apache
<VirtualHost *:80>
    ServerName vc.example.com
    DocumentRoot /var/www/jt-vc-portal

    <Directory /var/www/jt-vc-portal>
        Options -Indexes +FollowSymLinks
        AllowOverride All          # 必須，.htaccess 才會生效
        Require all granted
    </Directory>

    # 隱藏伺服器版本
    ServerTokens Prod
    ServerSignature Off

    ErrorLog  ${APACHE_LOG_DIR}/jt-vc-portal-error.log
    CustomLog ${APACHE_LOG_DIR}/jt-vc-portal-access.log combined
</VirtualHost>
```

啟用站台與必要模組：

```bash
a2enmod rewrite headers
a2ensite jt-vc-portal
systemctl reload apache2
```

> 正式環境建議改用 `*:443` + Let's Encrypt 憑證，或在前面加反向代理處理 HTTPS。
> 若以反向代理轉發，請保留 `X-Real-IP` header（fail2ban / 稽核取真實來源 IP 用），並設定 `JTVC_TRUSTED_PROXIES`（見下方「公開上線資安重點」）。

設定重點（`config.php`）：

- `DATA_DIR`：持久化資料目錄（預設 `/var/jaas-data`）。
- `JWT_PRIVATE_KEY_PATH`：JaaS RS256 私鑰路徑（預設 `keys/private.key` 對應 docroot）。
- 初始管理員：可用環境變數 `JTVC_ADMIN_USERNAME` / `JTVC_ADMIN_EMAIL` / `JTVC_ADMIN_PASSWORD`；未提供則首次啟動自動產生隨機密碼，寫入 `DATA_DIR/INITIAL_ADMIN_PASSWORD.txt`（登入後請刪除）。
- 反向代理信任：`JTVC_TRUSTED_PROXIES`（逗號分隔 IP/CIDR）。設定後只採信來自這些來源的 `X-Real-IP` / `X-Forwarded-*`，避免來源 IP 偽造繞過 fail2ban；留空＝相容模式（盲信標頭，須搭配容器埠隔離）。詳見「公開上線資安重點」。
- Session 逾時：`JTVC_SESSION_IDLE`（閒置秒數，預設 1800）、`JTVC_SESSION_ABSOLUTE`（絕對秒數，預設 43200）。

PHP 安全強化（建議於 `php.ini` 或 conf.d）：`display_errors=Off`、`expose_php=Off`、`session.cookie_httponly=1`、`session.cookie_samesite=Lax`、`session.use_strict_mode=1`。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 安裝方式二：Docker 打包部署

```bash
git clone https://github.com/jasoncheng7115/jt-vc-portal.git
cd jt-vc-portal

# 建置（建議加 --pull 取得最新 base image）
docker build --pull -t jt-vc-portal .

# 主機端準備持久化目錄（www-data UID 預設 33）
mkdir -p /opt/jt-vc-portal/keys /opt/jt-vc-portal/data
chown 33:33 /opt/jt-vc-portal/data
# JaaS 模式：放入 8x8 私鑰
cp /path/to/private.key /opt/jt-vc-portal/keys/private.key

# 啟動（-p 綁 127.0.0.1：只讓本機反向代理可達，不對外直接暴露容器埠）
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="請設定強密碼" \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal
```

容器對外為 `:58189`，建議前面再以 nginx / Apache 反向代理加上 HTTPS：

```nginx
server {
    listen 443 ssl;
    server_name your-domain.com;
    location / {
        proxy_pass http://127.0.0.1:58189/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
    # ssl_certificate / ssl_certificate_key ...
}
```

> **公開上線資安重點（務必做）**
> 系統以 `X-Real-IP`（其次 `X-Forwarded-For`）取得真實來源 IP，供 fail2ban 鎖定與稽核使用。為避免攻擊者偽造此 header 繞過 fail2ban：
> - 設 `JTVC_TRUSTED_PROXIES`（逗號分隔 IP/CIDR，支援 IPv4/IPv6）。**只有**來自清單的來源才採信 `X-Real-IP` / `X-Forwarded-*`；其餘一律以實際連線 IP（`REMOTE_ADDR`）為準。
> - 容器埠以 `-p 127.0.0.1:58189:58189` 綁本機或用防火牆限制，**只讓反向代理連得到**。
> - 兩者至少做一項、建議都做。**若 `JTVC_TRUSTED_PROXIES` 留空＝沿用相容模式（盲信標頭）**：在「埠有隔離」時無妨，但若容器埠對外可直連，攻擊者即可偽造來源 IP 繞過 fail2ban、污染稽核記錄。
> - 經 Cloudflare 時，請讓反代由 `CF-Connecting-IP` 帶入 `X-Real-IP`。

> **反向代理不要再加安全標頭**：CSP、`X-Frame-Options`、`X-Content-Type-Options`、`Referrer-Policy` 由 jt-vc-portal 自己送，反向代理再加一組會變成兩組互相衝突（瀏覽器會同時套用，或只取最後一組）。特別是 **`Permissions-Policy`**：會議是把 Jitsi 從另一個網域嵌進來的，只要 portal 網域送了 `camera=(self)`、`microphone=()` 之類的值，嵌入的會議就拿不到鏡頭和麥克風（Chrome、Edge 會直接拒絕）。真的要加，`camera`、`microphone`、`display-capture` 都要寫上 Jitsi 網域，例如 `microphone=(self "https://meet.example.com")`。
> nginx 注意：`add_header` 放在 `http` 層，或放在會被 `include /etc/nginx/conf.d/*.conf;` 自動載入的檔案裡，會套到**所有**自己沒寫 `add_header` 的站台；給特定站台用的標頭檔請放在 `conf.d` 以外（例如 `/etc/nginx/snippets/`），只在該站台的 `server` / `location` 裡 `include`。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 安裝方式三：從 GitHub Release 載入預建映像

不想自己 build？可直接下載 [Release](https://github.com/jasoncheng7115/jt-vc-portal/releases) 附的打包映像（`linux/amd64`），`docker load` 後即可執行。

```bash
# 1) 從 Release 頁下載映像與校驗檔（請改用最新版本號）
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.19.0/jt-vc-portal-1.19.0-docker-amd64.tar.gz
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.19.0/jt-vc-portal-1.19.0-docker-amd64.tar.gz.sha256

# 2) 驗證完整性（應顯示 OK）
sha256sum -c jt-vc-portal-1.19.0-docker-amd64.tar.gz.sha256

# 3) 載入映像（會建立 jt-vc-portal:1.19.0 與 :latest 標籤）
docker load < jt-vc-portal-1.19.0-docker-amd64.tar.gz

# 4) 主機端準備持久化目錄（www-data UID 預設 33）
mkdir -p /opt/jt-vc-portal/keys /opt/jt-vc-portal/data
chown 33:33 /opt/jt-vc-portal/data
cp /path/to/private.key /opt/jt-vc-portal/keys/private.key   # JaaS 模式才需要

# 5) 啟動（執行參數與方式二相同）
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="請設定強密碼" \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal:latest
```

> 映像僅含程式本體，**不含任何金鑰或設定**；8x8 私鑰於執行階段由 `keys/` 掛載卷提供。
> 僅提供 `linux/amd64`；其他架構（如 arm64）請用[方式二](#安裝方式二docker-打包部署)自行 build。
> HTTPS 反向代理設定同方式二。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 更新 / 升級

> 所有設定與資料（帳號、會議室、稽核記錄、用量等）都存在 `DATA_DIR`（直接安裝）或掛載卷（Docker），**更新不會遺失**；JSON 結構會自動相容升級。建議更新前仍先備份資料目錄與 `keys/`。

### 升級前：各版本需要補的東西

Docker 映像與 Release 映像已經內含所有元件，不必另外安裝。**直接安裝**的站台請看跨過的版本：

| 從哪個版本升級 | 需要補上 |
|---|---|
| v1.10.0 以前 | 單一登入（選用）需要 PHP `curl`、`openssl` 擴充（Debian / Ubuntu：`apt install php-curl`）。 |
| v1.12.0 以前 | 逐字稿與摘要（選用）需要 `curl`，以及**每分鐘執行一次的背景排程**，見「會議逐字稿與摘要」。Docker 安裝也要在主機加排程。 |
| v1.16.0 以前 | 有自建 Jibri 時，請一併更新 Jibri 主機上的 `jibri-recordings-api/server.py`（路徑與標頭安全強化），並 `systemctl restart jibri-recordings-api`。 |
| v1.16.1 以前 | 自建 Jitsi Meet 升到 `stable-11031` 以後：`.env` 改為 `XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass`、加 `JICOFO_ENABLE_AUTH=0`、停用舊的 `GLOBAL_CONFIG=disable_cascading_set = false`，再 `docker compose up -d`。否則來賓會變成主持人（可自行錄影、踢人）。詳見 [JITSI-MEET-SETUP_zh-TW.md](JITSI-MEET-SETUP_zh-TW.md)「主持人權限控制」。 |
| v1.16.2 以前 | 自建 Jibri 升到 `stable-11031` 以後：jibri 服務的 `environment` 加 `SE_AVOID_STATS=true`、`SE_OFFLINE=true` 後重建容器，否則 Jibri 主機連外慢時按錄影會等很久再出現「全部錄製目前忙碌」。詳見 [JIBRI-SETUP_zh-TW.md](JIBRI-SETUP_zh-TW.md) 第七節。 |
| v1.14.0 以前 | 會議記錄匯出 PDF / DOCX / ODT 需要 PHP `zlib` 擴充（Debian / Ubuntu 的 PHP 套件已內建），以及隨附字型 `lib/fonts/NotoSansTC-Regular.ttf`（`git pull` 會一起取得）。 |

可用 `php -m | grep -iE 'curl|mbstring|openssl|zlib|fileinfo|json'` 檢查。升級後打開**系統設定**：缺少的元件會列在最上方；背景排程沒在執行時，「逐字稿與摘要」卡片會警示。

### 方法一：直接安裝更新

```bash
cd /var/www/jt-vc-portal        # 你的安裝目錄

# 1) 備份（建議）
sudo cp -a /var/jaas-data /var/jaas-data.bak-$(date +%Y%m%d)

# 2) 拉取最新程式
git pull

# 3) 若 .htaccess 有更新，重新套用
cp dot.htaccess .htaccess

# 4) 重新載入（清 opcache）
sudo systemctl reload apache2
```

完成後登入確認 topbar 左上（站台名稱旁）版本號已更新。

### 方法二：Docker 更新

```bash
cd /path/to/jt-vc-portal

# 1) 備份資料卷（建議）
cp -a /opt/jt-vc-portal/data /opt/jt-vc-portal/data.bak-$(date +%Y%m%d)

# 2) 取得最新程式並重建映像（--pull 連 base image 一起更新）
git pull
docker build --pull -t jt-vc-portal .

# 3) 換掉容器（資料 / 私鑰在掛載卷，不受影響）
docker stop jt-vc-portal && docker rm jt-vc-portal
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal

# 4) 確認
docker ps --filter name=jt-vc-portal
```

> 初始管理員的環境變數（`JTVC_ADMIN_*`）僅首次建立帳號時用，更新時可省略；但 `JTVC_TRUSTED_PROXIES`（及選用的 `JTVC_SESSION_*`）是每次執行都生效的設定，**每次 `docker run` 都要帶上**。
> 版本號顯示於登入後 topbar 左上、站台名稱旁（點選可前往本專案 GitHub），可用以確認已更新到新版。

### 方法三：Release 映像更新

```bash
# 1) 備份資料卷（建議）
cp -a /opt/jt-vc-portal/data /opt/jt-vc-portal/data.bak-$(date +%Y%m%d)

# 2) 下載新版映像、驗證、載入
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/latest/download/jt-vc-portal-<新版本>-docker-amd64.tar.gz.sha256
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/latest/download/jt-vc-portal-<新版本>-docker-amd64.tar.gz
sha256sum -c jt-vc-portal-<新版本>-docker-amd64.tar.gz.sha256
docker load < jt-vc-portal-<新版本>-docker-amd64.tar.gz

# 3) 換掉容器（資料 / 私鑰在掛載卷，不受影響）
docker stop jt-vc-portal && docker rm jt-vc-portal
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal:latest
```

---

<br>
<br>
<br>
<br>
<br>
<br>

## 首次設定

1. 開啟站台 → `/jt-login` 以初始管理員登入（密碼見上方）。
2. 進入 **系統設定**：
   - **連線模式**：選 8x8 JaaS 或自建 Jitsi Meet，填入對應參數。
   - **站台設定**：站台名稱、logo。
   - **登入頁路徑**（選填）：把登入入口改成祕密路徑（見下方）。
   - **會議室介面**：預設 UI 語言（預設繁體中文）。
   - **錄製設定**（選填）：錄製者顯示名稱、串接自建 Jibri 錄影調閱服務與保留政策。
   - **SMTP**（選填）：寄送 `.ics` 邀請信。
   - **登入記錄外拋**（選填）：syslog / CEF / GELF。
3. 到 **個人設定** 變更密碼並啟用 2FA。
4. 回儀表板即可建立會議室。

### 連線模式

| | 8x8 JaaS | 自建 Jitsi Meet |
|---|---|---|
| 網域 | `8x8.vc` | 你的 Jitsi 網域 |
| 必填 | App ID、Key ID(kid)、RS256 私鑰 | 服務網域；（選）JWT app_id + HS256 密鑰 |
| 計費 | 免費 Dev 方案（25 MAU/月），超過依 8x8 方案計費 | 自行維運 |
| 錄影 | 8x8 內建（依方案） | 搭配自建 Jibri（本系統可調閱、播放、下載） |
| 逐字稿 | 8x8 另計費的即時字幕（依分鐘計費、檔案只保留 24 小時，不會存回本系統）；本系統預設關閉以免意外計費 | 搭配 Jibri 錄影 + 自架 jt-live-whisper：會後產生含發言者的逐字稿，存在本系統，資料不出自家機房 |
| 會議摘要 | 不提供 | 由 jt-live-whisper 以自架語言模型整理：重點、決議與待辦、風險、議題、發言統計，每條附錄影時間點 |
| 會議記錄匯出 | 不提供 | PDF / DOCX / ODT / HTML，另有純文字、SRT、JSON、Markdown |

自建 Jitsi Meet 若採 JWT，需在 prosody 啟用 token 驗證，且 app_id / app_secret 與本系統一致。

> **完整自建整合步驟**（從官方 Docker 版 Jitsi Meet 一路設定到與本系統搭配）見 **[JITSI-MEET-SETUP_zh-TW.md](JITSI-MEET-SETUP_zh-TW.md)**。

> **重要 — 行動裝置存取限制（採 JWT 時）**
> 啟用 JWT 驗證後（**8x8 JaaS 一律需要**；自建 Jitsi Meet 設 `ENABLE_AUTH=1` 時），會議室只接受 jt-vc-portal 簽發的 token。
> **官方 Jitsi Meet 行動 App（iOS / Android）將無法直接加入**——它不經過本入口、取不到 token，會被拒絕。
> 行動裝置使用者請改用**手機瀏覽器**開啟邀請連結，透過本入口加入（會內嵌會議，體驗一致）。
> 自建若採「免 JWT 匿名模式」（`ENABLE_AUTH=0`）則行動 App 可直接加入，但任何人知道會議室名稱即可進入，**安全性較低**。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 登入頁路徑偽裝

預設登入入口為 `/jt-login`。可於 **系統設定 → 登入頁路徑** 改成只有你知道的祕密路徑（僅允許英數與 `. _ -`，長度 1–64），降低被自動掃描 / 暴力嘗試的機會。

- 改掉後，原本的 `/jt-login` 與任何未對應的路徑都會直接回 **404**；只有設定的路徑會顯示登入頁。
- 變更不會把實際路徑寫進稽核 / SIEM（避免外洩）。
- **務必記住新路徑。** 若忘記或被鎖死，從伺服器端用 CLI 還原：

```bash
# Docker 部署
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php show     # 顯示目前路徑
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php reset    # 還原為 /jt-login
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php set xxx  # 直接指定新路徑

# 直接安裝（Apache + PHP）：在專案根目錄執行
sudo -u www-data php login-path.php reset
```

---

<br>
<br>
<br>
<br>
<br>
<br>

## 錄影調閱（自建 Jibri）

自建 Jitsi Meet + Jibri 錄影時，可在 Jibri 主機上跑隨附的 `jibri-recordings-api`（純 Python 標準庫服務），讓 portal 線上**列表 / 播放 / 下載 / 刪除**錄影，並顯示**錄影主機容量**。

- 服務只接受本 portal 來源 IP + Bearer token；portal 端再強制管理者登入後代理。
- 於 **系統設定 → 錄製設定 → Jibri 錄影服務** 填入服務 URL 與 token，偵測到後導覽列即出現「錄影記錄」。
- **保留政策**（預設全部停用）：依時間（保留 N 天）、依容量（保留可用空間 / 錄影總量上限，由舊到新刪）、自動清理殘留 / 未完成錄影。錄製中的檔案永不清理。
- 服務安裝與 systemd 設定見 **[JIBRI-SETUP_zh-TW.md](JIBRI-SETUP_zh-TW.md)**。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 會議逐字稿與摘要（jt-live-whisper）

> **完整設定指南**（JTLW 端金鑰與憑證、portal 設定、排程、權限、匯出、疑難排解）見 **[TRANSCRIPTS-SETUP_zh-TW.md](TRANSCRIPTS-SETUP_zh-TW.md)**。

自建 Jibri 主機上的錄影完成後，portal 可將錄影交給語音服務 **jt-live-whisper（JTLW）**，取回含發言者的逐字稿與會議摘要。結果由 portal 存放在錄影旁。

**需求**

- 已設定錄影調閱（自建 Jibri + `jibri-recordings-api`，見上節）。
- jt-live-whisper REST API（`api_revision` 2.4 以上），以及給本 portal 使用的 API 金鑰，權限範圍為 `jobs:write`、`jobs:read`、`jobs:cancel`、`profiles:read`。
- 選用：JTLW 主機需能連到 `<your site>/jtlw-webhook` 以傳送完成通知。沒有也能運作——背景排程每分鐘會查詢一次進度。

**設定步驟**

1. **系統設定 → 逐字稿與摘要**：填入 JTLW 網址（例如 `https://10.0.0.30:8790`）、API 金鑰；若為自簽憑證，貼上其 PEM（依所貼內容信任——絕不關閉憑證驗證；請核對顯示的 SHA-256 指紋）。選擇會議語言（已知就指定——「自動判斷」只看開頭約 30 秒決定）以及是否產生會議摘要。按 **儲存並測試連線**，再按 **註冊 webhook**。
2. **背景排程**——每分鐘執行一次：
   - Docker：在主機的 crontab 加入 `* * * * * docker exec -u www-data jt-vc-portal php /var/www/html/transcribe-worker.php`
   - 直接安裝：`/etc/cron.d/jtvc-transcribe`，內容為 `* * * * * www-data php /var/www/jt-vc-portal/transcribe-worker.php`（換成你的安裝目錄）
   它會一次上傳一筆錄影、追蹤進度、取回結果，並移除錄影已不存在的結果。同一時間只會執行一個。
3. **權限——帳號管理 → 逐字稿權限**，逐一設定每位主持人：*不可使用*（預設）、*手動*（可在自己的場次按「產生逐字稿」）或 *自動*（錄影完成後自動產生）。建立會議室時，可使用逐字稿的主持人可單場開關。管理員可對任何場次產生逐字稿。自動產生只處理啟用此功能之後錄的會議。

**運作方式與資料保留**

- portal 將錄影串流上傳到 JTLW、送出單一作業（辨識、發言者、標點校正、摘要），等待 webhook 或輪詢，取回逐字稿與摘要（JSON + Markdown）並寫入磁碟後，才通知 JTLW 刪除其副本。JTLW 處理完後即刪除上傳的錄影。
- 結果存於資料目錄（`transcripts/<recording id>/`），跟著錄影走：刪除錄影、或 Jibri 保留政策清除錄影時，其逐字稿與摘要也一併刪除。
- 主持人只看得到自己主持場次的逐字稿；稽核記錄會記下誰產生、檢視、下載或改名——絕不記錄逐字稿內容。
- 會議摘要支援中文與英文會議；日文與韓文只產生逐字稿。發言者代號（S1、S2…）是聲音分群，不是人名——可在逐字稿頁面改名。
- **匯出會議記錄（v1.14.0）**：逐字稿頁可下載整份會議記錄——會議資訊、摘要（決議與待辦含出處、風險、未決問題、議題、發言統計）與套用改名的逐字稿——格式有 **PDF、DOCX、ODT、HTML**（HTML 自 v1.15.0 起，單一檔案，任何瀏覽器都能開、可直接列印）；純文字、SRT 字幕、JSON 與 Markdown 摘要收在「其他格式」。全部由 portal 自己產生（主機不需要 LibreOffice 或瀏覽器引擎）；PDF 只嵌入用到的字（隨附 Noto Sans TC 字型，SIL Open Font License），中日文正常顯示且可搜尋。這個字型不含韓文。
- **發言者建議（v1.13.0）**：主持人的會議頁會記錄 Jitsi 的「目前發言者」時間軸；逐字稿頁會建議每個發言者代號是哪位參與者（附重疊時間比例），按一下即可套用，也可從參與者名單挑選。請讓主持人的會議頁全程開著，時間軸才會完整。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 安全性

遵循 OWASP Top 10:2025，逐項對應：

- **A01 權限控制失效**：未授權頁面一律回 404（不暴露入口）；會議室、錄影、逐字稿依擁有者隔離，主持人只看得到自己主持的場次；逐字稿另有帳號權限（不可使用 / 手動 / 自動）與單場開關；單一登入使用者的角色只由 IdP 群組決定，不在指定群組者一律拒絕；所有狀態變更皆為 POST + CSRF token。
- **A02 安全設定錯誤**：關閉錯誤顯示與版本洩漏；每個回應都帶內容安全政策（預設最嚴格，頁面再換成 nonce 版）與安全標頭；敏感路徑（`lib/`、`keys/`、`*.json`）拒絕存取；只採信白名單反向代理帶來的 `X-Real-IP`；設定頁不回填任何密鑰；系統設定頁會列出缺少的元件與沒有在執行的背景排程。
- **A03 軟體供應鏈失效**：零外部 PHP 套件：OIDC、PDF / DOCX / ODT 產生與 ZIP 都自行實作，不需 LibreOffice 等大型套件；前端 CDN 資源加 SRI 完整性驗證；Jitsi IFrame API（`external_api.js`）內附釘版並加 SRI，不即時從第三方載入；PDF 字型隨附（SIL OFL）；映像建置時套用最新 OS 安全更新；Release 映像由 CI 從 tag 原始碼建置並附 sha256。
- **A04 加密機制失效**：密碼以 bcrypt 雜湊；JWT 以 RS256 / HS256 簽章並設效期；單一登入的 id_token 以 IdP 公開金鑰（JWKS）驗簽，只接受 RS256 / RS384 / RS512，拒絕 `none` 與演算法混淆；連語音服務一律驗證 TLS 憑證（自簽憑證以貼上的 PEM 信任，從不關閉驗證）；webhook 以 HMAC 簽章；session cookie 為 HttpOnly、SameSite、Secure。
- **A05 注入攻擊**：輸出一律跳脫、輸入清洗；Email header injection、CSV 公式注入防護；外拋 syslog 剝除換行防偽造；匯出的 DOCX / ODT / HTML 內容全部跳脫並去除控制字元；全站（含會議頁）以 nonce 為基礎的內容安全政策，不允許 inline script。
- **A06 不安全設計**：閘道式架構、預設安全（來賓須具名、主持人在線上才放行）、角色最小權限；portal 從不經手 AD 密碼——企業帳號一律經 OIDC 身分提供者，MFA 與暴力破解防護在那一層；portal 也不直接連語言模型，逐字稿與摘要交給自架的語音服務，結果存回本系統後即通知對方刪除。
- **A07 身分驗證失效**：OIDC 單一登入：Authorization Code + PKCE（S256）+ state + nonce，以 iss + sub 綁定帳號、絕不以 email 自動合併本地帳號；「僅限單一登入」模式搭配限定 IP 的緊急本地管理員；本地帳號支援 TOTP 雙因素（防重放）、依真實來源 IP **＋依帳號**的登入鎖定（防分散 IP 猜密碼）；session 閒置 / 絕對逾時，改密碼或管理員強制登出立即失效；可選的登入頁路徑偽裝（不會被轉址洩漏）。
- **A08 軟體或資料完整性失效**：所有資料檔採「原子寫入」——先完整寫到暫存檔，再一次換上正式檔名，寫到一半斷電或當機也只會留下舊的完整檔案，不會壞掉；同時「加鎖」讓同一時間的多筆修改依序進行，不會互相蓋掉；webhook（8x8 用量、語音服務）以 HMAC 簽章與時間窗驗證，並以 idempotency key / 事件 ID 去除重複；送給語音服務的作業帶 Idempotency-Key，重送也不會重複處理；結果寫入磁碟後才通知對方刪除；設定匯入採白名單。
- **A09 安全記錄與告警失效**：完整稽核記錄：登入、單一登入成功 / 失敗、會議室、帳號、設定、錄影與逐字稿的每項操作（不記逐字稿內容）；每筆即時外拋 SIEM（syslog / CEF / GELF），並剝除換行防止偽造記錄。
- **A10 例外狀況處理不當**：失敗安全降級：讀取失敗回預設、寄信 / 外拋失敗不阻斷主流程、錯誤不外洩；單一登入任一檢查失敗一律拒絕（fail-closed）；語音服務連不上會退避重試，超過 24 小時標示失敗並說明原因，摘要失敗會自動重做；缺少元件時回應白話訊息而不是錯誤 500；播放失敗會分辨登入逾時、檔案不在或服務中斷。
- 私鑰、設定與執行資料皆存於掛載卷，**不進版控**（見 `.gitignore`）。
- **已知限制（Jitsi 設計使然）**：會議室名稱是邀請連結的一部分，敏感會議請用亂數名稱或大廳模式；自建模式未啟用 JWT 時，知道房名的人可直接連 Jitsi 網域進入（請啟用 JWT，設定頁會提示）；來賓一旦取得會議 token，在效期內（6 小時）可直接重新連入——在意的話請用大廳模式。
- 每次發版都必須通過單元、整合、瀏覽器 e2e 測試與 OWASP ZAP 弱掃（**High / Medium 皆為 0**），詳見 [TEST_CHECKLIST_zh-TW.md](TEST_CHECKLIST_zh-TW.md)。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 授權

本專案以 [GNU 通用公共授權條款第 3 版（GPL-3.0）](LICENSE) 釋出（GPL-3.0-only）。

> v1.7.0（含）以前的版本以 Apache License 2.0 發佈；自 v1.8.0 起改以 GPL-3.0 授權。

隨附字型 `lib/fonts/NotoSansTC-Regular.ttf`（Noto Sans TC，PDF 匯出用）採 SIL Open Font License 1.1 授權，全文見 `lib/fonts/OFL.txt`。

<br>
<br>
<br>
<br>
<br>
<br>

## 免責聲明

本軟體依「現狀」提供，不附任何明示或默示之擔保。使用者須自行負責部署環境之安全性與合規性（含第三方服務如 8x8 JaaS 之條款與費用）。作者不對任何因使用本軟體所生之直接或間接損失負責。

<br>
<br>
<br>
<br>
<br>
<br>

## 作者 / 連結

- 作者：Jason Cheng（[jasoncheng7115](https://github.com/jasoncheng7115)）
- 專案：<https://github.com/jasoncheng7115/jt-vc-portal>
- 問題回報：透過 GitHub Issues
