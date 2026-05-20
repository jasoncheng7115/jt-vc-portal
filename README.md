# jt-vc-portal v1.2.0 — 視訊會議入口閘道

> 搭配 Jitsi Meet 基底的會議入口系統，**雙模式**支援 [8x8 JaaS](https://jaas.8x8.vc/)[^8x8]（雲端託管）與 **[自建 Jitsi Meet](https://github.com/jitsi/jitsi-meet)**。
> 主持人登入後即可建立會議室、產生邀請連結（含 QR / `.ics` 行事曆邀請），來賓憑連結加入。
> 內建多帳號 / 角色 / 2FA、完整稽核記錄與 SIEM 外拋、fail2ban、預約時段等企業功能。

[^8x8]: **8x8** 自 2018 年起為 Jitsi / Jitsi Meet 的開發與維護公司；**8x8 JaaS（Jitsi as a Service）** 即其官方雲端託管的 Jitsi 服務。

**介紹頁 / Demo：** <https://jasoncheng7115.github.io/jt-vc-portal/>

![License](https://img.shields.io/badge/License-Apache_2.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4.svg)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED.svg)
![OWASP](https://img.shields.io/badge/OWASP-Top_10_2025-success.svg)
![Dependencies](https://img.shields.io/badge/PHP_deps-zero-brightgreen.svg)

---

## 功能特色

- **雙連線模式**：8x8 JaaS（雲端託管，RS256 + kid）或自建 Jitsi Meet（HS256 或免 JWT），於管理介面一鍵切換，不動程式碼。
- **會議室管理**：建立 / 進入會議室、亂數命名、近期清單、一鍵複製邀請、QR Code 彈窗。
- **預約時段**：可設定開放時段；時段未到顯示翻頁時鐘倒數，主持人提前進入即自動放行。
- **來賓流程**：來賓必填顯示名稱才進場；非開放時段顯示等候 / 倒數 / 已結束頁。
- **大廳模式**：建立會議室時可選；主持人進場自動開啟，來賓需經主持人逐一允許才能進入會議室。
- **Email 邀請**：填寫與會者 email，寄送含 `.ics`（METHOD:REQUEST）的邀請信，可一鍵加入行事曆。
- **多帳號 / 角色 / 2FA**：admin 看全部、host 只看自己建立的會議室；支援 TOTP 兩步驟驗證。
- **稽核與安全**：完整行為稽核記錄（登入、建室、邀請、設定變更…）+ 即時外拋 syslog / CEF / GELF；fail2ban 登入鎖定；CSRF；對齊 OWASP Top 10:2025。
- **可自訂外觀**：22 種主題、可換站台名稱與 logo、會議室預設 UI 語言。
- **用量統計**：JaaS 模式可接 USAGE webhook，依訂閱週期統計 MAU。
- **零外部 PHP 套件**：核心全部手寫，無 composer 相依（前端僅用 CDN 的 qrcodejs / flatpickr）。

---

## 系統需求

| 項目 | 最低 | 建議 |
|---|---|---|
| PHP | 8.2 | **8.4** |
| Web 伺服器 | Apache + `mod_rewrite`（`AllowOverride All`） | 同左 |
| PHP 擴充 | `openssl`、`fileinfo`、`json`、`mbstring` | 同左 |
| 其他 | 可寫入的資料目錄；JaaS 模式需 8x8 私鑰 | Docker 24+ |

> 自建 Jitsi Meet 模式另需一台可用的 Jitsi Meet 伺服器（詳見「連線模式」）。

---

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
> 若以反向代理轉發，請保留 `X-Real-IP` header（fail2ban / 稽核取真實來源 IP 用）。

設定重點（`config.php`）：

- `DATA_DIR`：持久化資料目錄（預設 `/var/jaas-data`）。
- `JWT_PRIVATE_KEY_PATH`：JaaS RS256 私鑰路徑（預設 `keys/private.key` 對應 docroot）。
- 初始管理員：可用環境變數 `JTVC_ADMIN_USERNAME` / `JTVC_ADMIN_EMAIL` / `JTVC_ADMIN_PASSWORD`；未提供則首次啟動自動產生隨機密碼，寫入 `DATA_DIR/INITIAL_ADMIN_PASSWORD.txt`（登入後請刪除）。

PHP 安全強化（建議於 `php.ini` 或 conf.d）：`display_errors=Off`、`expose_php=Off`、`session.cookie_httponly=1`、`session.cookie_samesite=Lax`、`session.use_strict_mode=1`。

---

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

# 啟動
docker run -d --restart unless-stopped \
  -p 58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="請設定強密碼" \
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

> 系統以 `X-Real-IP` 取得真實來源 IP（fail2ban / 稽核用），請確保反向代理有帶此 header。

---

## 安裝方式三：從 GitHub Release 載入預建映像

不想自己 build？可直接下載 [Release](https://github.com/jasoncheng7115/jt-vc-portal/releases) 附的打包映像（`linux/amd64`），`docker load` 後即可執行。

```bash
# 1) 從 Release 頁下載映像與校驗檔（請改用最新版本號）
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.2.0/jt-vc-portal-1.2.0-docker-amd64.tar.gz
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.2.0/jt-vc-portal-1.2.0-docker-amd64.tar.gz.sha256

# 2) 驗證完整性（應顯示 OK）
sha256sum -c jt-vc-portal-1.2.0-docker-amd64.tar.gz.sha256

# 3) 載入映像（會建立 jt-vc-portal:1.2.0 與 :latest 標籤）
docker load < jt-vc-portal-1.2.0-docker-amd64.tar.gz

# 4) 主機端準備持久化目錄（www-data UID 預設 33）
mkdir -p /opt/jt-vc-portal/keys /opt/jt-vc-portal/data
chown 33:33 /opt/jt-vc-portal/data
cp /path/to/private.key /opt/jt-vc-portal/keys/private.key   # JaaS 模式才需要

# 5) 啟動（執行參數與方式二相同）
docker run -d --restart unless-stopped \
  -p 58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="請設定強密碼" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal:latest
```

> 映像僅含程式本體，**不含任何金鑰或設定**；8x8 私鑰於執行階段由 `keys/` 掛載卷提供。
> 僅提供 `linux/amd64`；其他架構（如 arm64）請用[方式二](#安裝方式二docker-打包部署)自行 build。
> HTTPS 反向代理設定同方式二。

---

## 更新 / 升級

> 所有設定與資料（帳號、會議室、稽核記錄、用量等）都存在 `DATA_DIR`（直接安裝）或掛載卷（Docker），**更新不會遺失**；JSON 結構會自動相容升級。建議更新前仍先備份資料目錄與 `keys/`。

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

完成後登入確認 topbar 中央版本號已更新。

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
  -p 58189:58189 \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal

# 4) 確認
docker ps --filter name=jt-vc-portal
```

> 初始管理員的環境變數（`JTVC_ADMIN_*`）僅首次建立帳號時用；更新時可省略。
> 版本號顯示於登入後 topbar 中央（點擊可前往本專案 GitHub），可用以確認已更新到新版。

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
  -p 58189:58189 \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal:latest
```

---

## 首次設定

1. 開啟站台 → `/jt-login` 以初始管理員登入（密碼見上方）。
2. 進入 **系統設定**：
   - **連線模式**：選 8x8 JaaS 或自建 Jitsi Meet，填入對應參數。
   - **站台設定**：站台名稱、logo。
   - **會議室介面**：預設 UI 語言（預設繁體中文）。
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

自建 Jitsi Meet 若採 JWT，需在 prosody 啟用 token 驗證，且 app_id / app_secret 與本系統一致。

---

## 安全性

- **存取控制**（OWASP A01）：未授權頁面回 404、會議室依擁有者隔離、CSRF token。
- **認證**（A07）：bcrypt 密碼、TOTP 2FA、fail2ban 登入鎖定（依真實來源 IP）。
- **加密**（A04）：JWT 簽章、webhook HMAC 驗簽、安全 session cookie。
- **記錄與告警**（A09）：完整稽核記錄 + 即時 SIEM 外拋。
- **設定強化**（A02）：關閉錯誤顯示與版本洩漏、安全標頭、敏感路徑拒絕存取。
- **供應鏈**（A03）：零外部 PHP 套件；Dockerfile 建置時套用最新 OS 安全更新。
- 私鑰、設定與執行資料皆存於掛載卷，**不進版控**（見 `.gitignore`）。

---

## 授權

本專案以 [Apache License 2.0](LICENSE) 釋出。

## 免責聲明

本軟體依「現狀」提供，不附任何明示或默示之擔保。使用者須自行負責部署環境之安全性與合規性（含第三方服務如 8x8 JaaS 之條款與費用）。作者不對任何因使用本軟體所生之直接或間接損失負責。

## 作者 / 連結

- 作者：Jason Cheng（[jasoncheng7115](https://github.com/jasoncheng7115)）
- 專案：<https://github.com/jasoncheng7115/jt-vc-portal>
- 問題回報：透過 GitHub Issues
