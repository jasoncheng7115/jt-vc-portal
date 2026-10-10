# 自建 Jitsi Meet × jt-vc-portal 整合設定

> English: [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md) · 日本語: [JITSI-MEET-SETUP_ja.md](JITSI-MEET-SETUP_ja.md)

> **作者**：Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　專案 [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

本文說明如何把一套**官方 Docker 版 Jitsi Meet**，設定成可與 jt-vc-portal「認證入口」搭配使用。

> 適用版本：[docker-jitsi-meet](https://github.com/jitsi/docker-jitsi-meet) **`stable-11031`**（2026-06-08 發佈）。其他穩定版步驟相同，只需替換版本號。

---

## 目錄

**基礎概念**

- [架構概念](#架構概念)
- [前置需求](#前置需求)
- [連接埠與 NAT 設定（含媒體後援 / TURN）](#連接埠與-nat-設定)

**安裝步驟**

- [一、取得官方 docker-jitsi-meet](#一取得官方-docker-jitsi-meet)
- [二、基本對外設定（`.env`）](#二基本對外設定編輯-env)
- [三、啟用 JWT 驗證（建議）](#三啟用-jwt-驗證建議)
- [四、啟動 Jitsi](#四啟動-jitsi)
- [五、jt-vc-portal 端設定](#五jt-vc-portal-端設定)
  - [會議頻寬參考（電腦、伺服器、手機）](#會議頻寬參考電腦伺服器手機)

**進階 / 維運**

- [六、疑難排解](#六疑難排解)
- [七、錄影（Jibri）](#七錄影jibri選用)
- [八、品牌 logo 與隱藏錄製者](#八品牌-logo-與隱藏錄製者伺服器-configjs)
- [九、升級 Jitsi](#九升級-jitsi)

---

<br>
<br>
<br>
<br>
<br>
<br>

## 架構概念

```
使用者 ──▶ jt-vc-portal（vc.example.com）──── 內嵌 IFrame ────▶ 自建 Jitsi Meet（meet.example.com）
            ‧登入 / 角色 / 大廳 / 稽核                              ‧實際音視訊會議
            ‧簽發 JWT（HS256，選用）                               ‧媒體由瀏覽器直連此網域
```

- **jt-vc-portal**：負責入口（登入、權限、預約、大廳、稽核），並用 IFrame API 內嵌 Jitsi。
- **Jitsi Meet**：負責會議本身。瀏覽器會直接連到 `meet.example.com` 載入 `external_api.js` 與媒體。
- 驗證有兩種：**免 JWT（開放）** 或 **HS256 JWT（建議）**——只有 jt-vc-portal 簽發的 token 能進會議室。

> **維運邊界（自建 vs JaaS）**：本文的連接埠、NAT、媒體穿牆 / TURN（含 `turns/443`）等**只在自建模式需要你自己處理**。改用 **8x8 JaaS** 時，媒體與穿牆全由 8x8 雲端負責，你不必開這些埠或架 coturn——jt-vc-portal 在 JaaS 模式只做認證（簽 JWT），媒體不經過你的主機。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 前置需求

- 一台對外可連、有 DNS 的主機，例 `meet.example.com`（需與 jt-vc-portal 不同網域或子網域）。
- 對外開放：`443/tcp`（網頁 / 信令）、`10000/udp`（JVB 媒體）。
- 有效 HTTPS 憑證（可用 Jitsi 內建 Let's Encrypt）。
- 已安裝 Docker 與 Docker Compose。

---

<br>
<br>
<br>
<br>
<br>
<br>

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

> **注意（與舊版不同）**：現代 Jitsi（含 `stable-11031`）的 JVB 採「**單一 UDP 埠 10000**」多工，所有與會者媒體都共用這一個埠——**不需要**再開「10000–20000 一整段範圍」。那是多年前舊版（`org.ice4j.ice.harvest.MIN/MAX_PORT` 動態埠範圍）的做法，docker 版單埠模式已淘汰。只要放行 `UDP 10000`（+ 選用 `TCP 4443`）即可。

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

### 媒體傳輸的自動後援（UDP 10000 →（選用 TCP 4443）→ TURN，含 turns/443）

來賓的影音媒體由瀏覽器的 **ICE** 機制**自動**「從最快到最能穿牆」依序嘗試，挑第一個通且優先序最高的通道——**你不需要寫任何判斷邏輯，只要把通道準備好**：

| 順位 | 通道 | 適用情境 | 難度 |
|---|---|---|---|
| 1 | **UDP 10000** → JVB 直連 | 一般網路（品質最佳） | 預設即有 |
| 2 | **TCP 4443** → JVB 直連 | 擋 UDP、放行任意對外 TCP | 選用 / legacy |
| 3 | **TURN**：`turn`(udp/tcp 3478) +`turns`(tls 443) → 經 coturn 轉送 | 擋 UDP、甚至**只**放行 443 | 需另架 coturn |

> 大多數使用者光靠 **第 1 層 UDP 10000** 就能用。只有「來賓端網路很嚴格」才需要後援；其中 **TURN（第 3 層）一個元件就同時涵蓋「UDP 不通」與「只剩 443」**，所以建議直接做 TURN，不必再弄第 2 層。
>
> 效能取捨：越往後越能穿牆、但越慢。`turns/443` 是 TCP + 中繼 + 多一層 TLS，延遲最高、coturn 會成集中瓶頸，只當「最後保命」用——能走 UDP 10000 就別靠它（Google Meet 亦同：優先 UDP，UDP 全擋才退 TCP/443，官方明言 TCP 會降品質）。

#### 第 1 層：UDP 10000（預設、品質最佳）

即前述：放行 `UDP 10000`、設好 `JVB_ADVERTISE_IPS` 即可。

#### 第 2 層：TCP 4443（JVB 直連，選用 / legacy）

現代 Jitsi **預設停用** JVB 內建 TCP harvester，官方已改用 TURN 統一處理後援；`stable-11031` 的 docker `.env` **沒有**對應開關。要硬開需以 custom config 疊加重啟 TCP harvester 並開放 `TCP 4443`——**多數情境不需要，直接做第 3 層 TURN 即可**。

#### 第 3 層：TURN（coturn，含 turns/443）

原理：架一台 **TURN 伺服器（coturn）**，同時提供 `turn`（udp/tcp 3478）與 `turns`（TLS 443）。ICE 會自動「先試 UDP relay、再 TCP relay、最後 turns/443」，逐級退到能通為止；coturn 收到後再以 UDP 把媒體轉給 JVB。`docker-jitsi-meet` **不內建 coturn**，但用官方 Docker image 很好起。

**(1) coturn 設定** `coturn/turnserver.conf`：

```ini
listening-port=3478
tls-listening-port=5349           # 拓撲 A 可直接設 443（見下）
fingerprint
use-auth-secret
static-auth-secret=<一段長亂數，與 prosody 共用>
realm=meet.example.com
cert=/etc/coturn/certs/turn.crt
pkey=/etc/coturn/certs/turn.key
min-port=49152
max-port=65535
external-ip=<本機公網IP>
no-multicast-peers
no-cli
```

**(2) 用 Docker 起 coturn**（官方 image `coturn/coturn`）。coturn 需要一大段 UDP relay 埠，用 **host 網路**最省事；附一個 compose 檔，與 Jitsi 的 compose 並存：

```yaml
# docker-compose.coturn.yml
services:
  coturn:
    image: coturn/coturn:4.6          # 建議釘版本，勿用 latest
    restart: unless-stopped
    network_mode: host                # relay 埠很多，host 網路最簡單
    volumes:
      - ./coturn/turnserver.conf:/etc/coturn/turnserver.conf:ro
      - ./coturn/certs:/etc/coturn/certs:ro
    command: ["-c", "/etc/coturn/turnserver.conf"]
```

```bash
docker compose -f docker-compose.coturn.yml up -d
```

**(3) 443 怎麼擺——看拓撲二選一：**

- **拓撲 A（簡單，建議）：coturn 有自己的主機名 + IP**（另一台小主機，或同機第二個公網 IP）。turnserver.conf 直接 `tls-listening-port=443`，coturn 自己獨佔 443，**完全不需要 nginx 分流**。DNS 把 `turn.meet.example.com` 指到該 IP 即可。
- **拓撲 B（與 Jitsi 共用同一個 IP 的 443）**：才需要在最前緣用 nginx `stream` + `ssl_preread` 依 **SNI** 分流（不終結 TLS、原樣轉走）：

```nginx
stream {
  map $ssl_preread_server_name $upstream {
    turn.meet.example.com  127.0.0.1:5349;   # TURN/TLS → coturn
    default                127.0.0.1:8443;    # 其餘 → Jitsi web 容器
  }
  server {
    listen 443;
    listen [::]:443;
    ssl_preread on;
    proxy_pass $upstream;
  }
}
```

> 拓撲 B 因 nginx 占用 443，Jitsi web 容器要改別的埠（`.env` 設 `HTTPS_PORT=8443`）由它反代；`turn.meet.example.com` 與 `meet.example.com` 都指到這台。

**(4) 讓 prosody 把 TURN 廣告給瀏覽器**（XEP-0215 `external_services`），瀏覽器才知道有 TURN 可用。docker 版以 prosody 設定疊加，內容相當於：

```lua
external_services = {
  { type = "turn",  host = "turn.meet.example.com", port = 3478, transport = "udp", secret = "<同 coturn static-auth-secret>" };
  { type = "turns", host = "turn.meet.example.com", port = 443,  transport = "tcp", secret = "<同 coturn static-auth-secret>" };
};
```

**(5) 驗證**：開 `https://webrtc.github.io/samples/src/content/peerconnection/trickle-ice/`，填 `turns:turn.meet.example.com:443?transport=tcp` 應出現 `relay` 候選；再把測試端網路限制到只剩 443，確認會議仍可通（畫面 / 聲音正常）。

> 這層牽涉憑證、（拓撲 B 的）SNI 分流、coturn 與 prosody 密鑰一致，環境差異大。若需要，我可以依你的實際拓撲（同機 / 分機、憑證來源）另寫一份逐步版。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 一、取得官方 docker-jitsi-meet

```bash
git clone https://github.com/jitsi/docker-jitsi-meet.git
cd docker-jitsi-meet
git checkout stable-11031

cp env.example .env
./gen-passwords.sh         # 產生各內部元件的隨機密碼（寫回 .env）

mkdir -p ~/.jitsi-meet-cfg/{web,transcripts,prosody/config,prosody/prosody-plugins-custom,jicofo,jvb,jigasi,jibri}
```

> `.env` 內的 `JITSI_IMAGE_VERSION` 應為 `stable-11031`（與 checkout 的 tag 一致），確保拉到對應映像。

---

<br>
<br>
<br>
<br>
<br>
<br>

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

<br>
<br>
<br>
<br>
<br>
<br>

## 三、啟用 JWT 驗證（建議）

讓 Jitsi **只接受 jt-vc-portal 簽發的 token**，避免任何人猜到會議室名稱就闖入。jt-vc-portal 自建模式使用 **HS256 共享密鑰**簽 token。

> **重要 — 啟用 JWT 後，官方行動 App 無法直接加入**
> 開啟 `ENABLE_AUTH=1`（JWT）後，會議室只接受 jt-vc-portal 簽發的 token。**官方 Jitsi Meet 行動 App（iOS / Android）不經過本入口、取不到 token，將無法加入**（會出現驗證 / token 錯誤）。
> 行動裝置請改用**手機瀏覽器**開啟邀請連結，透過 jt-vc-portal 加入。若必須讓原生 App 直接進房，只能維持「免 JWT 匿名模式」（下節），但安全性較低。

在 `.env` 設定：

```ini
ENABLE_AUTH=1
AUTH_TYPE=jwt
ENABLE_GUESTS=0                       # 只有持 token 者可進（入口由 jt-vc-portal 把關）

JWT_APP_ID=jt-vc-portal               # ← 對應 jt-vc-portal 的「App ID」
JWT_APP_SECRET=<請產生一段長亂數>      # ← 對應 jt-vc-portal 的「app_secret（HS256 共享密鑰）」
JWT_ACCEPTED_ISSUERS=jt-vc-portal     # 與 App ID 相同
JWT_ACCEPTED_AUDIENCES=jt-vc-portal   # 與 App ID 相同

# === 主持人權限控制（很重要，見下方說明，三者缺一不可）===
ENABLE_AUTO_OWNER=0                            # 不讓「第一個進房者」自動變 moderator
XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass   # 依 token 設主持人；主持人可略過大廳
JICOFO_ENABLE_AUTH=0                           # 主持人身分改由上面的模組決定，jicofo 不再授予
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

### 主持人權限控制（重要：否則所有人都是主持人）

**docker-jitsi-meet 用 JWT 時，預設「只要持有效 token 就是 moderator」**——即使 token 帶 `context.user.moderator: false` 也不會被執行。後果是**來賓也是主持人**：可踢人、結束所有人會議、且**繞過大廳**。要讓「只有指定主持人是 moderator」，上方 `.env` 那三行**缺一不可**：

| 設定 | 作用 |
|---|---|
| `ENABLE_AUTO_OWNER=0` | 關掉「第一個進房者自動變 owner」。 |
| `XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass` | 啟用 prosody 模組，依 token 的 `moderator` 旗標把使用者設為 `owner`（主持人）或 `member`（一般與會者）。`stable-11031` 起已收進 Jitsi 主程式（`/prosody-plugins/mod_token_affiliation.lua`）。`token_lobby_bypass`（映像內附的社群模組）讓 token 帶 `lobby_bypass: true` 的人略過大廳——**少了它，開大廳的會議室裡主持人斷線重進會被擋在大廳，而房內只剩來賓時沒人能放行**。jt-vc-portal v1.16.1 起只替主持人帶這個旗標，來賓仍要在大廳敲門。 |
| `JICOFO_ENABLE_AUTH=0` | 關掉 jicofo 的驗證授權。jicofo 開驗證時，會把**所有持有效 token 的人**都升成主持人，蓋過上面模組的設定——**少這行，來賓仍是主持人，可自行錄影、踢人、結束會議**。這是 Jitsi 維護者建議的做法（[#16297](https://github.com/jitsi/jitsi-meet/issues/16297)、[#16905](https://github.com/jitsi/jitsi-meet/issues/16905)）。關掉後，進會議仍一律要有效 token（prosody 驗證不受影響）。 |

> **從 stable-10888 以前升級的注意事項**：舊版文件用的是社群版模組（`/prosody-plugins-contrib/token_affiliation`）加 `GLOBAL_CONFIG=disable_cascading_set = false`。`stable-11031` 起社群版已移除、改用內建模組，那一行不再有作用，**升級後要改成上面的 `JICOFO_ENABLE_AUTH=0`**，否則來賓會變回主持人。升級後請用一個來賓帳號確認：沒有「開始錄影」、不能踢人。

設定後 `docker compose up -d`（會重建 prosody / jicofo）。對應 jt-vc-portal：主持人 token 帶 `moderator: true`、來賓帶 `moderator: false`（本系統自動處理），於是**主持人 = owner / moderator、來賓 = member**（不能踢人 / 結束會議、會被大廳擋）。

> **不需要把主持人與來賓分到不同 domain / 租戶**——同一個入口、同一個 token，靠 `moderator` 旗標區分即可。
>
> 驗證：以來賓身分進會議，其 participants 面板 / 「⋯」選單**不該**出現「全部靜音 / 結束會議 / 踢人」；只有主持人有。

### 啟用 JWT 後的存取行為（預設已擋匿名）

開啟 JWT 後，`https://meet.example.com/` 的前端仍會載入，但**沒有 jt-vc-portal 簽發的 token 就無法建立或加入任何會議室**（會出現驗證失敗）——已經**擋掉匿名開房**，也擋掉直接打網域進來的官方手機 App。只有經 jt-vc-portal（帶 token）進來的人才進得去，**安全性無虞**。

> **以下純屬「觀感」美化，可選，不做也不影響安全。** 只有當你在意「直接打 `meet.example.com` 還看得到 Jitsi 介面 / 隨機房 / App 安裝提示」、想把 meet 當純後端時，再改這段：
>
> **(1) 隱藏歡迎頁**（`.env`）：`ENABLE_WELCOME_PAGE=0`。注意關掉後直接打 `/` 會自動產生隨機房名，手機仍會跳「在應用程式中加入」深層連結頁（點了也會被 JWT 擋）。
>
> **(2) 更乾淨——根目錄導回 portal**：利用 web 容器既有的 `include /config/nginx-custom/*.conf;`，丟一個只對 `/` 轉址的設定（房間網址、`external_api.js`、IFrame 內嵌都不受影響）：
>
> ```bash
> mkdir -p ~/.jitsi-meet-cfg/web/nginx-custom
> cat > ~/.jitsi-meet-cfg/web/nginx-custom/redirect-root.conf <<'EOF'
> location = / {
>     return 302 https://vc.example.com/;   # 換成你的 jt-vc-portal 網址
> }
> EOF
> docker exec docker-jitsi-meet-web-1 nginx -s reload
> ```
>
> 這樣直接打 `meet.example.com` 會轉到入口 portal；只有經 portal 內嵌（`/<房間>` + `external_api.js`）的請求照常服務。

### 不想用 JWT（開放模式）

只要 `ENABLE_AUTH=0`，並在 jt-vc-portal「自建是否需 JWT」選「否」即可——前端不帶 token，任何人有會議室名稱就能進。**安全性較低，僅適合內網 / 測試。**

---

<br>
<br>
<br>
<br>
<br>
<br>

## 四、啟動 Jitsi

```bash
docker compose up -d
docker compose ps
```

驗證：瀏覽器開 `https://meet.example.com/external_api.js` 應可取得 JS（jt-vc-portal 內嵌時會載入它）。

---

<br>
<br>
<br>
<br>
<br>
<br>

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

> **會議室左上 logo**：改由 Jitsi 伺服器 `config.js` 統一設定（現場與錄影一致），見[第八節](#八品牌-logo-與隱藏錄製者伺服器-configjs)。jt-vc-portal 不再以 IFrame 覆寫 logo，「會議室自訂」也已移除 logo 選項。

> **以下都由 jt-vc-portal 在進會議時帶入，無需改 Jitsi：**
> - **停用「用 App 加入」深層連結**（`configOverwrite.disableDeepLinking = true`）：手機經 portal 進會議直接在瀏覽器開啟，不會跳官方 App 安裝/開啟頁（該 App 因 JWT 無法連入）。
> - **會議室自訂**（系統設定 → 會議室自訂，自建模式）：進入靜音/關鏡頭、畫質上限、**預設檢視（演講者／畫廊）**、小畫面的接收畫質（[流量參考見下方](#小畫面的接收畫質與流量參考)）、工具列功能逐項開關——皆於進會議時帶入。
> - **大廳模式**：建立會議室時勾選，主持人進場（取得 moderator 後）自動開啟，來賓需逐一核准才能進入。
> - **編碼偏好**（`videoQuality.codecPreferenceOrder = VP9, H264, VP8, AV1`）：純桌機會議用 VP9（低頻寬畫質佳）；**含 iPhone/iPad 的會議自動改用硬體 H.264**（iOS Safari 不支援 VP9，否則會落到畫質最差的 VP8）。

> **行動端收視畫質**：手機在行動網路看對方視訊偏糊，主因是行動下行頻寬 + 自適應碼率（LAN 端頻寬大所以清楚）。VP9/H.264 已盡量改善；要更好需原生 App（但本架構因 JWT 無法用 App），或確保媒體走 UDP 10000 直連而非 TCP 中繼。

### 會議頻寬參考（電腦、伺服器、手機）

以下是 2026-10 在自建 Jitsi Meet stable-11031 實際開會量到的數字。測試條件：每人送出 1280×720、每秒 15 格且一直變化的鏡頭畫面（讓編碼器跑到上限，估線路偏保守）；電腦是 1440×900 的瀏覽器視窗，編碼 VP9；一人開麥克風，其他人靜音。單位都是 **Mbps（每秒百萬位元）**，跟線路規格（例如 300M / 100M）同一個單位，可以直接比。

> **線上頻寬計算機**：[Jitsi Meet 頻寬計算機](https://it.jason.tools/tools/jitsi-bandwidth-calc.html) 依這份實測數據，輸入人數、場次、顯示方式與畫質，算出每台電腦與 Jitsi 伺服器需要的頻寬，並檢查線路夠不夠。

#### 每台電腦需要的頻寬

| 人數 | 上傳 | 下載：並排（預設畫質） | 下載：並排（較高 / 高） | 下載：演講者畫面 |
|---|---|---|---|---|
| 2 人（點對點直連） | 約 1.3 Mbps | 約 1.3 Mbps | 約 1.3 Mbps | 約 1.3 Mbps |
| 3 人 | 約 1.3 Mbps | 約 0.4 Mbps | 約 1.3 Mbps | 約 0.8 Mbps |
| 4 人 | 約 1.3 Mbps | 約 0.6 Mbps | 約 2.0 Mbps | 約 0.9 Mbps |
| 5 人 | 約 1.3 Mbps | 約 0.8 Mbps | 約 2.5 Mbps | 約 1.0 Mbps |
| 6 人 | 約 1.3 Mbps | 約 1.0 Mbps | 約 3.4 Mbps ※ | 約 1.2 Mbps |

- **上傳**：被別人放大看（演講者畫面）的人要送 720p，約 1.3 Mbps；誰都可能被放大，所以每台電腦都以 1.3 Mbps 規劃。只用並排、畫質「預設」時約 0.4 Mbps。
- **下載**：看的是幾個人、每格多大。「較高 / 高」是系統設定 → 會議室自訂 →「小畫面的接收畫質」，電腦並排兩者都收 720p，所以數字相同。演講者畫面是一個大畫面（720p）＋其他人的小畫面（180p），不受這個設定影響。
- **2 人會議**走點對點直連，不經伺服器；連不通時才改走伺服器。

#### Jitsi 伺服器需要的頻寬

伺服器（JVB）**收到的是所有人上傳的總和、送出的是所有人下載的總和**，人一多，伺服器的「上傳」增加最快，申請線路時要特別看上傳。

| 人數 | 並排（預設畫質） | 並排（較高 / 高） | 演講者畫面 |
|---|---|---|---|
| 2 人 | 幾乎 0 Mbps（點對點直連） | 幾乎 0 Mbps | 幾乎 0 Mbps |
| 3 人 | 收 約 1.2 Mbps<br>送 約 1.3 Mbps | 收 約 3.9 Mbps<br>送 約 4.2 Mbps | 收 約 2.8 Mbps<br>送 約 2.5 Mbps |
| 4 人 | 收 約 1.6 Mbps<br>送 約 2.6 Mbps | 收 約 5.3 Mbps<br>送 約 8.3 Mbps | 收 約 2.9 Mbps<br>送 約 3.9 Mbps |
| 5 人 | 收 約 2.0 Mbps<br>送 約 4.4 Mbps | 收 約 6.3 Mbps<br>送 約 13.2 Mbps | 收 約 3.1 Mbps<br>送 約 5.4 Mbps |
| 6 人 | 收 約 2.4 Mbps<br>送 約 6.6 Mbps | 收 約 7.5 Mbps<br>送 約 22 Mbps ※ | 收 約 3.2 Mbps<br>送 約 7.2 Mbps |

※ 6 人且畫質「較高 / 高」時，測試用的單一台電腦 CPU 不夠、編碼器自動降到 540p，下載與伺服器「收」由每路速率推算、伺服器「送」取實測較大值。實際每人用自己的電腦不會有這個問題。

#### 例：4 個人都用電腦開會

- **每台電腦**：上傳約 1.3 Mbps，下載約 2 Mbps（並排、較高 / 高；預設畫質約 0.6、演講者畫面約 0.9）。
- **伺服器**：上傳約 8.3 Mbps，下載約 5.3 Mbps（並排、較高 / 高；預設畫質約 2.6 / 1.6）。
- **線路要留餘裕**：同一條線還有其他人上網、Wi-Fi 不穩、畫面動作多都會更高，建議以表中數字的 1.5～2 倍規劃，例如每台電腦有 5 Mbps 以上、伺服器上傳 20 Mbps 以上就很寬裕。

#### 小畫面的接收畫質與流量參考

**系統設定 → 會議室自訂 →「小畫面的接收畫質（手機、3 人以上並排）」**決定 3 人以上的會議裡，每個小方格向 Jitsi 要求的畫質。Jitsi 依方格大小決定收哪一層畫質（只有 180p / 360p / 720p 三層，所以沒有 480p 可選）；手機螢幕小、方格也小，預設只收 180p。2 人會議（點對點直連，本來就收 720p）與演講者大畫面不受這個設定影響。

手機 4 人並排（手機＝Chromium 模擬 Pixel 7）：

| 等級 | 手機每格收到 | 電腦每格收到 | 手機下載 | 每秒資料量 | 開會 1 小時約用 |
|---|---|---|---|---|---|
| 預設（Jitsi 自動） | 180p | 360p | 約 0.3 Mbps | 約 38 KB/s | 約 135 MB |
| 較高 | 360p | 720p | 約 1.2 Mbps | 約 150 KB/s | 約 540 MB |
| 高 | 720p | 720p | 約 2 Mbps | 約 250 KB/s | 約 900 MB |

**怎麼選**

- 大多用電腦開會，或在意手機流量：維持**預設**。
- 常有人用手機參加 3～4 人會議、覺得對方畫面糊：選**較高**。
- 手機在 Wi-Fi 下、想要最清楚：選**高**（手機耗電與發熱會增加，伺服器上傳頻寬也會增加）。

#### 數字怎麼算

- **Mbps 是每秒百萬「位元」（bit）**，不是位元組（byte）；1 byte = 8 bit。線路規格用 bit，檔案大小與行動數據用量用 byte。
- **每台電腦**：讀瀏覽器 WebRTC 統計（`RTCPeerConnection.getStats()`）裡 `transport` 的 `bytesSent` / `bytesReceived`（聲音、畫面、控制封包全部算進去），間隔 10 秒相減：`(後 − 前) × 8 ÷ 秒數`＝每秒位元數。手機那張表只算收到的視訊（`inbound-rtp` 的 `bytesReceived`，間隔 4 秒）。
- **伺服器**：讀 Jitsi 主機網卡的收送位元組（`/sys/class/net/<網卡>/statistics/rx_bytes`、`tx_bytes`），同樣 10 秒相減，含 IP / UDP 標頭，就是線路上真正跑的量。
- **每秒資料量**＝速率 ÷ 8（1.2 Mbps → 150 KB）；**1 小時用量**＝每秒資料量 × 3,600（150 KB → 約 540 MB，1 MB 以 1,000 KB 計）。
- **人更多時怎麼估**：每路畫面約 720p 0.65、360p 0.2、180p 0.1 Mbps。每台電腦下載 ≈ 每路速率 × (人數 − 1)（演講者畫面＝0.65 ＋ 0.1 × (人數 − 2)）；伺服器送 ≈ 每台電腦下載 × 人數，伺服器收 ≈ 每台電腦上傳 × 人數。7 人以上沒有實測；人多時方格變小、Jitsi 會改收較低畫質，實際通常比估算低。
- **實際數字會變動**：畫面動作多寡、網路狀況、人數與 Jitsi 的頻寬估計都會影響，這些是參考值，不是保證值。Jitsi 對每種解析度都有位元率上限，一般視訊鏡頭通常不會超過表中的數字。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 六、疑難排解

| 症狀 | 可能原因 / 處理 |
|---|---|
| `external_api.js` 404 | `PUBLIC_URL` / HTTPS 未設好；確認容器與憑證正常 |
| 進會議顯示 token / authentication 錯誤 | App ID、`JWT_APP_SECRET`、`iss`、`aud` 不一致；或多租戶環境需在「JWT sub」填租戶名（單網域留空即送 `*`） |
| 大廳沒作用 | `.env` 要 `ENABLE_LOBBY=1`，且建立會議室時勾「大廳模式」 |
| 黑畫面 / 媒體不通 | `10000/udp` 未開放，或 NAT；於 `.env` 設 `JVB_ADVERTISE_IPS=<主機公網IP>` |
| 部分來賓（嚴格網路）連不上媒體 | 該來賓端擋 UDP / 只放行 443 → 見「媒體傳輸的自動後援」架 TURN（coturn，含 turns/443） |
| 會議中鏡頭 / 麥克風打不開（Chrome 顯示權限被拒），但直接開 Jitsi 網址正常 | jt-vc-portal 網域被加了 `Permissions-Policy`（常見是反向代理的安全標頭），嵌入的 Jitsi 因此拿不到鏡頭和麥克風。用 `curl -sI https://vc.example.com/ \| grep -i permissions-policy` 檢查：拿掉它，或在 `camera`、`microphone`、`display-capture` 寫上 Jitsi 網域，詳見 README「公開上線資安重點」後的說明 |
| 想統一網域體感 | jt-vc-portal 網址列恆為 `vc.example.com`；`meet.example.com` 只在 F12 / 連線中可見（正常） |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 七、錄影（Jibri，選用）

會議錄影需另外部署 **Jibri**（獨立資源、一台同時錄一場）。完整步驟——含 **同時多會議室錄製** 與 **錄影中文顯示（CJK 字型）** 的處理——見 **[JIBRI-SETUP_zh-TW.md](JIBRI-SETUP_zh-TW.md)**。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 八、品牌 logo 與隱藏錄製者（伺服器 config.js）

這兩項都要設在 **Jitsi 伺服器自身的 `config.js`**，原因相同：

- **Jibri 錄影**用它自己的瀏覽器**直接連 Jitsi 伺服器**，**不經過 portal 的 IFrame**——所以 portal 帶入的設定對錄影無效，只有伺服器 `config.js` 才會同時影響「現場 + 錄影」。
- 隱藏錄製者（`hiddenDomain`）用 IFrame `configOverwrite` 覆寫不一定生效，設在伺服器端最可靠。

docker-jitsi-meet 每次容器啟動時，會把 `~/.jitsi-meet-cfg/web/custom-config.js`、`custom-interface_config.js` 自動**附加**到產生的設定後（見 web 容器 `/etc/cont-init.d/10-config`），放這兩個檔即可持久化。

**設定（兩個檔）：**

```bash
# (1) config.js 覆寫：左上 logo + 隱藏錄製者
cat > ~/.jitsi-meet-cfg/web/custom-config.js <<'JS'
config.defaultLogoUrl = "https://vc.example.com/logo";   // 換成你的 portal 網址 + /logo
config.hiddenDomain   = "hidden.meet.jitsi";             // 錄製者登入網域，從與會者清單/人數隱藏
JS

# (2) interface_config.js 覆寫：浮水印 logo
cat > ~/.jitsi-meet-cfg/web/custom-interface_config.js <<'JS'
interfaceConfig.DEFAULT_LOGO_URL     = "https://vc.example.com/logo";
interfaceConfig.JITSI_WATERMARK_LINK = "https://vc.example.com";
interfaceConfig.SHOW_JITSI_WATERMARK = true;
JS
```

**套用（會重啟 web 容器，現場服務中斷數秒）：**

```bash
docker restart docker-jitsi-meet-web-1
```

**重點：**

- `https://vc.example.com/logo` 換成你的 jt-vc-portal 對外網址 + `/logo`（portal 把「系統設定 → 站台設定」上傳的 logo 服務在此，回傳 PNG；Jibri 主機也要連得到此網址）。
- 之後在 portal 換 logo 圖檔即自動生效（網址不變，**不必再重啟 Jitsi**）。
- `hidden.meet.jitsi` 是 docker-jitsi-meet 的錄製者網域（`XMPP_RECORDER_DOMAIN`），通常即此值；可用 `docker exec docker-jitsi-meet-prosody-1 grep -i VirtualHost /config/conf.d/*.lua` 確認。

**驗證：**

```bash
docker exec docker-jitsi-meet-web-1 grep -E "defaultLogoUrl|hiddenDomain" /config/config.js
```

再錄一段測試：播放確認左上是自訂 logo（非 jitsi 預設浮水印），且與會者清單/人數**不含**錄製者。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 九、升級 Jitsi

升級前先確認**沒有人在開會、沒有錄影中**（升級會中斷所有會議）：

```bash
docker exec docker-jitsi-meet-jicofo-1 curl -s http://127.0.0.1:8888/stats   # conferences、participants 都要是 0
```

備份設定（出問題時可回到原版本）：

```bash
cd docker-jitsi-meet
tar czf ~/jitsi-backup-$(date +%Y%m%d).tgz .env docker-compose*.yml -C ~ .jitsi-meet-cfg
git describe --tags > ~/jitsi-backup-$(date +%Y%m%d).version     # 記下目前版本，回滾用
```

升級：

```bash
git fetch --tags
git checkout stable-<新版本>
docker compose pull
docker compose up -d
```

JWT 與整合設定不需更動（`custom-config.js` / `custom-interface_config.js` 會保留並自動再附加）。

- **Jitsi 主機與 Jibri 主機要升到同一個版本**（見 [JIBRI-SETUP_zh-TW.md](JIBRI-SETUP_zh-TW.md) 的升級一節），版本差太多時錄影可能連不上。
- 回滾：`git checkout <原版本>` 後再 `docker compose up -d`（舊映像仍在本機）。
- 升級後開一場測試會議、兩人互相聽得到、錄一段影片確認可播放。
- 本文以 `stable-11031` 實際驗證。**`stable-11146` 起是結構性改版**（基底改 Debian 13、容器改非 root 執行、映像改放 GitHub Container Registry、web 容器內部埠與 WebSocket 設定變更），不是單純換版號，請先讀官方 release notes 並在測試環境演練後再升級；自建的 `jibri-cjk` 映像也要重新檢查。
