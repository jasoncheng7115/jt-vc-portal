# 自建 Jitsi Meet × jt-vc-portal 整合設定

本文說明如何把一套**官方 Docker 版 Jitsi Meet**，設定成可與 jt-vc-portal「認證入口」搭配使用。

> 適用版本：[docker-jitsi-meet](https://github.com/jitsi/docker-jitsi-meet) **`stable-10888`**（2026-03-30 發佈）。其他穩定版步驟相同，只需替換版本號。

---

## 架構概念

```
使用者 ──▶ jt-vc-portal（vc.example.com）──── 內嵌 IFrame ────▶ 自建 Jitsi Meet（meet.example.com）
            ‧登入 / 角色 / 大廳 / 稽核                              ‧實際音視訊會議
            ‧簽發 JWT（HS256，選用）                               ‧媒體由瀏覽器直連此網域
```

- **jt-vc-portal**：負責入口（登入、權限、預約、大廳、稽核），並用 IFrame API 內嵌 Jitsi。
- **Jitsi Meet**：負責會議本身。瀏覽器會直接連到 `meet.example.com` 載入 `external_api.js` 與媒體。
- 驗證有兩種：**免 JWT（開放）** 或 **HS256 JWT（建議）**——只有 jt-vc-portal 簽發的 token 能進會議室。

---

## 前置需求

- 一台對外可連、有 DNS 的主機，例 `meet.example.com`（需與 jt-vc-portal 不同網域或子網域）。
- 對外開放：`443/tcp`（網頁 / 信令）、`10000/udp`（JVB 媒體）。
- 有效 HTTPS 憑證（可用 Jitsi 內建 Let's Encrypt）。
- 已安裝 Docker 與 Docker Compose。

---

## 連接埠與 NAT 設定

### 需開放的連接埠

| 埠 | 協定 | 用途 | 必要性 |
|---|---|---|---|
| 443 | TCP | HTTPS 網頁 + 會議信令（BOSH / WebSocket） | 必須 |
| 80 | TCP | HTTP→HTTPS 轉址 + Let's Encrypt 簽發憑證 | 用內建 LE 時必須 |
| 10000 | UDP | JVB 媒體（音視訊 RTP），**主要媒體通道** | 必須 |
| 4443 | TCP | JVB 媒體 TCP 後援（使用者環境擋 UDP 時用） | 選用 |

- 媒體幾乎都走 **UDP/10000**；少數網路擋 UDP 時才靠 **TCP/4443** 後援，建議兩個都開以求穩定。
- 防火牆 / 雲端 Security Group 需放行上述 **inbound**。
- 信令（join、聊天）走 443/TCP；媒體（聲音畫面）走 10000/UDP——兩者缺一都會「進得去但黑畫面 / 沒聲音」。

> **注意（與舊版不同）**：現代 Jitsi（含 `stable-10888`）的 JVB 採「**單一 UDP 埠 10000**」多工，所有與會者媒體都共用這一個埠——**不需要**再開「10000–20000 一整段範圍」。那是多年前舊版（`org.ice4j.ice.harvest.MIN/MAX_PORT` 動態埠範圍）的做法，docker 版單埠模式已淘汰。只要放行 `UDP 10000`（+ 選用 `TCP 4443`）即可。

### 位於 NAT / 防火牆後（主機是私有 IP）

JVB 預設會把「自己看到的 IP」告訴瀏覽器；若主機是私有 IP（NAT 後），來賓會拿到私有 IP 而連不到媒體。必須讓 JVB **對外宣告公網 IP**。

**(1) 編輯 `.env`**，讓 JVB 宣告公網 IP（多個以逗號分隔；同時列公網 + 私有 IP，可讓外網與內網都連得到）：

```ini
JVB_ADVERTISE_IPS=<公網IP>,<主機私有IP>
```

**(2) 路由器 / 防火牆做 port forward 到 Jitsi 主機：**

- `UDP 10000` → 主機:10000（**最關鍵**）
- `TCP 443` → 主機:443
- `TCP 80` → 主機:80（Let's Encrypt 簽發 / 續約期間）
- （選）`TCP 4443` → 主機:4443

**(3) 雲端主機**（GCP / AWS / Azure 等）：在 VPC 防火牆 / Security Group 放行 `UDP 10000`、`TCP 443`、`TCP 80`（、`TCP 4443`），並把對外公網 IP 填入 `JVB_ADVERTISE_IPS`。

> **最常見故障**：能進會議室但黑畫面 / 沒聲音 → 八成是 `UDP 10000` 未放行 / 未轉發，或 `JVB_ADVERTISE_IPS` 沒設成公網 IP。

---

## 一、取得官方 docker-jitsi-meet

```bash
git clone https://github.com/jitsi/docker-jitsi-meet.git
cd docker-jitsi-meet
git checkout stable-10888

cp env.example .env
./gen-passwords.sh         # 產生各內部元件的隨機密碼（寫回 .env）

mkdir -p ~/.jitsi-meet-cfg/{web,transcripts,prosody/config,prosody/prosody-plugins-custom,jicofo,jvb,jigasi,jibri}
```

> `.env` 內的 `JITSI_IMAGE_VERSION` 應為 `stable-10888`（與 checkout 的 tag 一致），確保拉到對應映像。

---

## 二、基本對外設定（編輯 `.env`）

```ini
# 對外網址（= jt-vc-portal「服務網域」要填的位址）
PUBLIC_URL=https://meet.example.com

# Let's Encrypt 自動憑證
ENABLE_LETSENCRYPT=1
LETSENCRYPT_DOMAIN=meet.example.com
LETSENCRYPT_EMAIL=you@example.com

# 對外埠
HTTP_PORT=80
HTTPS_PORT=443
JVB_PORT=10000

TZ=Asia/Taipei

# 大廳：讓 jt-vc-portal 的「大廳模式」(toggleLobby) 能生效
ENABLE_LOBBY=1
```

### TLS 憑證選項（擇一）

**(A) Let's Encrypt 自動憑證**（上面範例即是）：`ENABLE_LETSENCRYPT=1` + `LETSENCRYPT_DOMAIN` + `LETSENCRYPT_EMAIL`，容器會自動申請與續約。需 `TCP 80` 對外可達。

**(B) 自有 SSL 憑證**（你已有憑證 / 公司 CA / 萬用憑證）：關閉 Let's Encrypt，把憑證放進 web 容器的 keys 目錄即可——

```ini
ENABLE_LETSENCRYPT=0
```

```bash
# 憑證放到 web 設定卷的 keys/（CONFIG 預設 ~/.jitsi-meet-cfg）
mkdir -p ~/.jitsi-meet-cfg/web/keys
cp your-fullchain.pem ~/.jitsi-meet-cfg/web/keys/cert.crt   # 含中繼鏈的完整憑證
cp your-private.key   ~/.jitsi-meet-cfg/web/keys/cert.key   # 對應私鑰
# 重新啟動讓 web 容器套用
docker compose up -d
```

> 檔名固定為 **`cert.crt`（完整鏈）** 與 **`cert.key`（私鑰）**，放在 `~/.jitsi-meet-cfg/web/keys/`（容器內 `/config/keys/`）。`HTTPS_PORT=443` 維持不變。憑證到期前換檔再 `docker compose restart web` 即可。

**(C) 由前端反向代理處理 TLS**（憑證在 nginx / Traefik / HAProxy 上）：容器只出 HTTP，TLS 交給反代——

```ini
ENABLE_LETSENCRYPT=0
DISABLE_HTTPS=1
HTTP_PORT=8000
```

反代把 `https://meet.example.com` 轉到容器 `HTTP_PORT`，並務必轉發 **WebSocket**（會議信令需要）與 `X-Forwarded-*` / `Host` 標頭。

---

## 三、啟用 JWT 驗證（建議）

讓 Jitsi **只接受 jt-vc-portal 簽發的 token**，避免任何人猜到房名就闖入。jt-vc-portal 自建模式使用 **HS256 共享密鑰**簽 token。

在 `.env` 設定：

```ini
ENABLE_AUTH=1
AUTH_TYPE=jwt
ENABLE_GUESTS=0                       # 只有持 token 者可進（入口由 jt-vc-portal 把關）

JWT_APP_ID=jt-vc-portal               # ← 對應 jt-vc-portal 的「App ID」
JWT_APP_SECRET=<請產生一段長亂數>      # ← 對應 jt-vc-portal 的「app_secret（HS256 共享密鑰）」
JWT_ACCEPTED_ISSUERS=jt-vc-portal     # 與 App ID 相同
JWT_ACCEPTED_AUDIENCES=jt-vc-portal   # 與 App ID 相同
```

對應關係（**三邊必須一致**）：

| jt-vc-portal（/設定 → 連線模式） | Jitsi `.env` | 說明 |
|---|---|---|
| App ID | `JWT_APP_ID` / `JWT_ACCEPTED_ISSUERS` / `JWT_ACCEPTED_AUDIENCES` | jt-vc-portal 簽的 token `iss` = `aud` = App ID |
| app_secret | `JWT_APP_SECRET` | HS256 共享密鑰，兩邊完全相同 |
| JWT sub | （prosody 驗證的 subject） | **留空即可**（預設送 `*`，適用單網域非租戶）；多租戶才填租戶名 |

> **產生密鑰**：`openssl rand -hex 32`，兩邊貼一樣的值。
>
> 為何 sub 預設 `*`：標準單網域 docker-jitsi-meet（非租戶）的 prosody token 驗證只接受 `sub` 為 `*` 或租戶名；本系統已預設送 `*`，直接可用。

### 不想用 JWT（開放模式）

只要 `ENABLE_AUTH=0`，並在 jt-vc-portal「自建是否需 JWT」選「否」即可——前端不帶 token，任何人有房名就能進。**安全性較低，僅適合內網 / 測試。**

---

## 四、啟動 Jitsi

```bash
docker compose up -d
docker compose ps
```

驗證：瀏覽器開 `https://meet.example.com/external_api.js` 應可取得 JS（jt-vc-portal 內嵌時會載入它）。

---

## 五、jt-vc-portal 端設定

登入 jt-vc-portal → **系統設定 → 連線模式設定**，切到「自建 Jitsi Meet」，填：

| 欄位 | 範例值 |
|---|---|
| 模式 | 自建 Jitsi Meet |
| 服務網域 | `meet.example.com`（不含 `https://`） |
| 本系統對外網址 | `https://vc.example.com` |
| 自建是否需 JWT | 是（對應 `ENABLE_AUTH=1`）/ 否（`ENABLE_AUTH=0`） |
| App ID | `jt-vc-portal`（= `JWT_APP_ID`） |
| app_secret（HS256） | （= `JWT_APP_SECRET`） |
| JWT sub | 留空即可（預設送 `*`）；多租戶才填租戶名 |

存檔後，從 jt-vc-portal 建立會議室、開始主持，即會內嵌自建 Jitsi 並（若啟用）自動帶入 token。

> **會議室左上 logo**：jt-vc-portal 進會議時會以 IFrame API 帶入「站台 logo」（`/logo`）作為會議室左上 logo（`defaultLogoUrl` / `DEFAULT_LOGO_URL`），無需改 Jitsi。要換 logo 到 **系統設定 → 站台設定** 上傳即可。註：少數 Jitsi 版本會限制 interfaceConfig 覆寫白名單，若沒生效，需在自建 Jitsi 的 `config.js` 允許該覆寫（自建可自行調整）。

---

## 六、疑難排解

| 症狀 | 可能原因 / 處理 |
|---|---|
| `external_api.js` 404 | `PUBLIC_URL` / HTTPS 未設好；確認容器與憑證正常 |
| 進會議顯示 token / authentication 錯誤 | App ID、`JWT_APP_SECRET`、`iss`、`aud` 不一致；或多租戶環境需在「JWT sub」填租戶名（單網域留空即送 `*`） |
| 大廳沒作用 | `.env` 要 `ENABLE_LOBBY=1`，且建立會議室時勾「大廳模式」 |
| 黑畫面 / 媒體不通 | `10000/udp` 未開放，或 NAT；於 `.env` 設 `JVB_ADVERTISE_IPS=<主機公網IP>` |
| 想統一網域體感 | jt-vc-portal 網址列恆為 `vc.example.com`；`meet.example.com` 只在 F12 / 連線中可見（正常） |

---

## 七、錄影（Jibri，選用）

會議錄影需另外部署 **Jibri**（獨立資源、一台同時錄一場）。完整步驟——含 **同時多會議室錄製** 與 **錄影中文顯示（CJK 字型）** 的處理——見 **[JIBRI-SETUP.md](JIBRI-SETUP.md)**。

---

## 八、升級 Jitsi

```bash
cd docker-jitsi-meet
git fetch --tags
git checkout stable-<新版本>
docker compose pull
docker compose up -d
```

JWT 與整合設定不需更動。本文撰寫時最新穩定版為 `stable-10888`。
