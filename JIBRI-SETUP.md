# Jibri 錄影 × 自建 Jitsi Meet 設定

接續 [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md)，本文在同一套 docker-jitsi-meet 上加上 **Jibri 錄影**，並特別處理兩個重點：**同時多會議室錄製**與**錄影中文顯示**。

> 適用版本：docker-jitsi-meet / `jitsi/jibri` **`stable-10888`**。
> 本文以 **同時 2 場錄製（2 個 Jibri 實例）** 為目標撰寫；要增減場數時，把文中所有「2」一起調整即可。

---

## 部署拓撲（建議 Jibri 獨立一台 VM）

| 規模 | 建議 |
|---|---|
| 測試 / 小規模、偶爾錄 1 場 | Jibri 與 Jitsi **同一台 VM**（同一套 docker-compose，最省事，即本文預設步驟） |
| 正式 / 多實例（本文 2 場以上） | **Jibri 獨立一台（或多台）VM**，與 Jitsi 主機分開 |

**為什麼正式環境要分開：**

1. **資源隔離（最重要）**：Jibri = headless Chrome + ffmpeg，CPU/RAM 又重又突發。和 prosody / jicofo / JVB 擠同一台，錄影一忙會拖垮**所有**會議品質。分開後 Jibri 爆 CPU 也只影響錄影、不影響會議。
2. **核心需求只落在 Jibri VM**：只有 Jibri 需要 `snd-aloop` 與「必須是 VM、不可 LXC」；Jitsi 核心服務沒這限制。分開後就只有 Jibri 那台要處理核心模組。
3. **獨立擴充**：要更多並行錄影，加 Jibri VM 即可，不動 Jitsi 主機。

**怎麼分開：** Jibri 透過 **XMPP 連到主 stack 的 prosody**（網路可達即可，不必同機）。獨立 Jibri VM 上仍用本文步驟（snd-aloop + jibri 容器），但 `.env` 的 `XMPP_SERVER` / `XMPP_*_DOMAIN` / `JIBRI_*` 要指向**主 Jitsi 主機**並與其一致（即 docker-jitsi-meet 的「standalone Jibri」做法）。

> 本文第三～四節以「同一台」寫，最容易上手。**若要「獨立 Jibri VM」(與 Jitsi 分機，建議的正式做法)，完整實作步驟見[第九節](#九獨立-jibri-vm與-jitsi-分機實作步驟)。** 第二節(snd-aloop)、第五節(CJK 字型)兩台都需要。

---

## 一、Jibri 是什麼、有什麼限制

- Jibri（Jitsi Broadcasting Infrastructure）以一個 **headless Chrome** 加入會議，再用 **ffmpeg** 把畫面與聲音擷取成 `.mp4`（或推 RTMP 直播）。
- **一個 Jibri 實例同時只能錄一場**。本文目標 **2 場並行 = 2 個 Jibri 實例**。
- 需要 ALSA loopback（`snd-aloop`）虛擬音效裝置擷取聲音；**每個並行的 Jibri 各需一個獨立 loopback 裝置**（2 場 → 2 個）。
- Jibri 很吃資源：每路約 1～2 vCPU + 1～2 GB RAM（headless Chrome + ffmpeg）。**2 路並行建議 4 vCPU / 8 GB**（詳見下方「VM 資源建議」表）。

### 必須裝在「VM」、不能裝在容器（LXC）內

Jibri 需要在主機核心 **載入 `snd-aloop` 模組** 並存取 **`/dev/snd`**——這在共用宿主核心的容器（如 Proxmox LXC、其他 OS 級容器）裡**做不到**。因此：

- **請把「跑 Docker 的這台 Jibri 主機」開成一台 VM（KVM/完整虛擬機，有自己的核心）**，再在 VM 內用 Docker 起 2 個 Jibri 容器。
- 例：Proxmox → 開 **VM**（不是 LXC）→ 裝 Linux + Docker → 於 VM 內 `modprobe snd-aloop`。
- 「不能裝在容器裡」指的是不要把 Jibri 主機本身做成 LXC；Jibri 服務本身仍是在 VM 內以 Docker 容器執行（這是 OK 的，因為 VM 有獨立核心可載入模組、可給容器 `/dev/snd`）。

### VM 資源建議（以本文目標「2 路同時錄製」為準）

| 項目 | 建議 | 備註 |
|---|---|---|
| vCPU | **4 核**（最少 3、舒適 6） | Jibri = headless Chrome + ffmpeg 編碼，很吃 CPU；每路約 1～2 vCPU |
| RAM | **8 GB**（最少 4） | 每路 Chrome + ffmpeg 約 1～2 GB，加系統餘裕 |
| 系統碟 | **20–30 GB** | OS + Docker + Jibri/Chrome 映像約 10～15 GB |
| 錄影空間 | **另計，建議獨立碟 / NFS（100 GB 起）** | 見下方容量估算；可用 `finalize.sh` 自動搬走後刪本地 |
| 音效 | **snd-aloop ×2**（每路一張 loopback） | 必須是 VM、不可 LXC（見上） |

**錄影容量估算（容易被忽略）**：1080p30 H.264 約 **0.5～1 GB / 小時 / 路**；估「同時路數 × 單場時長 × 保留份數」。例：2 路各錄 2 小時 ≈ 2～4 GB。長期保留請把錄影放獨立大碟或 NAS / 物件儲存。

**其他**：
- **網路**：Jibri 要把整場會議「下載」進來再錄，需穩定頻寬到 Jitsi / JVB，最好同網段。
- **CPU 類型（Proxmox）**：Jibri 綁 `snd-aloop` 核心模組，live migration 意義不大；用 `host` 或 `x86-64-v2-AES` 皆可，離線搬移沒問題。
- **要更多路**：vCPU / RAM、`snd-aloop` 裝置數（第二節）、`--scale jibri=N`（第四節）一起等比放大（例：3 路 ≈ 6 vCPU / 12 GB）。
- **最小可跑**（偶爾 1 路）：2 vCPU / 4 GB / 系統 20 GB + 錄影空間。

---

## 二、主機（VM）前置：載入 snd-aloop（決定可並行的場數）

Jibri 用 ALSA 的 **loopback 虛擬音效卡**（`snd-aloop`）把會議聲音導給 ffmpeg；**每個並行錄影各需一張 loopback 卡**，所以 2 場要 2 張。本步驟在 **Jibri VM 的作業系統層**做（不是容器內）。

### 1) 確認核心有 snd-aloop 模組

```bash
modinfo snd-aloop >/dev/null 2>&1 && echo "OK：有模組" || echo "缺模組，需補裝"
```

精簡版的雲端 / 伺服器映像常缺這個模組，補裝後再繼續（Debian / Ubuntu）：

```bash
sudo apt-get update
sudo apt-get install -y "linux-modules-extra-$(uname -r)" alsa-utils
```

### 2) 設定開機自動載入 + 固定卡數（持久化）

```bash
# (a) 開機自動載入 snd-aloop
echo 'snd-aloop' | sudo tee /etc/modules-load.d/snd-aloop.conf

# (b) 固定 2 張 loopback 卡（要幾場就列幾組「1」與 index）
echo 'options snd-aloop enable=1,1 index=0,1' | sudo tee /etc/modprobe.d/snd-aloop.conf
```

- `enable=1,1`：啟用 2 張卡；`index=0,1`：分別給編號 0、1。
- 3 場就 `enable=1,1,1 index=0,1,2`，依此類推。

### 3) 重新開機（建議做法）

> **改完上面兩個檔，請重開機一次。**
> 原因：`snd-aloop` 很可能在你動手前就已被系統載入過（且沒帶 options / 卡數不對）。模組「已在記憶體」時，再下 `modprobe ... enable=...` 的 options 會被忽略。重開機能保證以 `/etc/modprobe.d` 的設定**乾淨**載入正確卡數。

```bash
sudo reboot
```

#### 不想重開、要當下生效（替代做法）

先卸載再帶 options 重新載入即可（不必重開機）：

```bash
sudo modprobe -r snd-aloop 2>/dev/null   # 卸載；若顯示 in use 代表有程式占用 → 那就改用重開機
sudo modprobe snd-aloop enable=1,1 index=0,1
```

### 4) 驗證（重開或重載後執行）

```bash
lsmod | grep snd_aloop        # 應看到 snd_aloop 已載入
cat /proc/asound/cards        # 應看到 2 張 "Loopback" 卡（index 0、1）
```

看到 **2 張 Loopback 卡**就成功。

> 增減場數：把這裡的 `enable=` / `index=` 與第四節的 `--scale jibri=` 一起調整，並重開機（或用上面替代做法重載）。
> 若 `modprobe` 顯示找不到模組或權限不足且補裝後仍失敗，多半是這台是 **LXC 容器而非 VM**——見第一節，必須改用 VM。

---

## 三、啟用錄影（docker-jitsi-meet `.env`）

> **不用再手改 prosody / jicofo / jibri 設定檔了。** 舊的「套件版（apt 安裝）」要手動改 `prosody` 的 recorder vhost、jibri brewery MUC、`jicofo` 屬性、`jibri.conf`、`prosodyctl register` 等——**docker 版這些全部由容器啟動時依環境變數自動產生**。你只要設 `.env` 變數即可，主機端真正要做的只剩 `snd-aloop`（第二節，因為那是核心層、容器產不出來）。

```ini
ENABLE_RECORDING=1

# 帳號名沿用 env.example 預設即可（recorder / jibri）；密碼由 gen-passwords.sh 產生：
#   JIBRI_RECORDER_PASSWORD、JIBRI_XMPP_PASSWORD（執行 ./gen-passwords.sh 後已寫入 .env）
# JIBRI_RECORDER_USER=recorder
# JIBRI_XMPP_USER=jibri

# 時區（影響錄影檔名 / 內嵌時間 / log 時間；全部容器共用此值）
TZ=Asia/Taipei

# 錄影輸出目錄（容器內路徑，對應 host volume）
JIBRI_RECORDING_DIR=/config/recordings
# 錄完後處理腳本（選用：上傳物件儲存 / 通知；不設則只留檔）
# JIBRI_FINALIZE_RECORDING_SCRIPT_PATH=/config/finalize.sh
```

Jibri 服務以額外的 `jibri.yml` 疊加啟動（docker-jitsi-meet 慣例）：

```bash
docker compose -f docker-compose.yml -f jibri.yml up -d
```

Jibri 容器需存取主機 `/dev/snd`（jibri.yml 已設 `devices: /dev/snd`），並依賴上一步載入的 snd-aloop。

### 錄影輸出位置（host 端）

- jibri 的設定 / 錄影 volume 預設掛在主機 **`~/.jitsi-meet-cfg/jibri`** → 容器 `/config`；錄影檔即在主機 **`~/.jitsi-meet-cfg/jibri/recordings/`** 下。
- 每場會議自成一個子資料夾（含 `.mp4` 與 metadata），檔名 / 時間依 `TZ` 設定。
- 想改放別處：在 `jibri.yml` 把該 volume 對應的主機路徑改掉（例如指到大容量磁碟 / NFS 掛載點），`JIBRI_RECORDING_DIR` 維持容器內 `/config/recordings` 即可。
- `CONFIG` 變數（docker-jitsi-meet `.env`，預設 `~/.jitsi-meet-cfg`）決定所有元件設定 / 資料的主機根目錄；要整批換位置改它。

---

## 四、同時 2 場錄製（並行）

**(1)** VM 的 snd-aloop 已開 **2** 個裝置（見第二節）。

**(2)** 把 jibri 服務 scale 到 **2**：

```bash
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=2
```

每個 jibri 實例會佔用一張 loopback 卡；jicofo 會把每個錄影請求派給「空閒」的 jibri，最多同時 2 場。

**(3)** 確認：`docker compose -f docker-compose.yml -f jibri.yml ps` 應有 **2** 個 jibri 容器，且能在 2 間會議室同時開始錄影。

> 三者必須一致：`snd-aloop` 裝置數 = jibri 實例數 = 想並行的場數（本文皆為 2）。任一不足，超出的錄影請求會排不到 Jibri 而失敗 / pending。
> 要更多場：把第二節裝置數與此處 `--scale` 一起加大，並確認 VM 資源足夠。

---

## 五、錄影中文顯示（必做）

Jibri 是用 headless Chrome「把會議畫面錄下來」。**官方 `jitsi/jibri` 映像不含 CJK 字型**，因此中文姓名 / 聊天 / 字幕在錄影裡會變成空白方框（□，俗稱缺字）。需在 jibri 映像加裝中文字型。

建一個延伸映像：

```dockerfile
# jibri-cjk/Dockerfile
FROM jitsi/jibri:stable-10888
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      fonts-noto-cjk fonts-noto-cjk-extra fonts-noto-color-emoji \
 && fc-cache -f \
 && rm -rf /var/lib/apt/lists/*
# 重要：不要切回 USER jibri。jibri 映像以 root 啟動 s6（會自行降權到 jibri 跑服務）；
# 若結尾加 USER jibri，容器啟動會出現 "s6-mkdir: /var/run/s6: Permission denied" 並不斷重啟。
```

build 並讓 compose 改用它：

```bash
docker build -t jibri-cjk:stable-10888 ./jibri-cjk
```

在 `jibri.yml`（或 `docker-compose.override.yml`）把 jibri 服務的 `image:` 改成 `jibri-cjk:stable-10888`，再重啟。

> 驗證：錄一段含中文姓名的會議，播放確認中文正常（非缺字方框）。Noto CJK 對繁體中文覆蓋最完整。

---

## 六、錄影檔與調閱

- 錄影 `.mp4` 存在 jibri 的錄影目錄（對應 host volume，預設約 `~/.jitsi-meet-cfg/jibri`）。
- 錄完會執行 `finalize.sh`（若有設定）：可在此上傳到物件儲存 / 通知系統。
- 與 jt-vc-portal 的整合（會議頁錄影按鈕、錄影入庫、權限調閱）屬**規劃中功能**，目前 Jibri 僅負責產出檔案。

---

## 七、疑難排解

| 症狀 | 處理 |
|---|---|
| 按錄影沒反應 / 一直 pending | jibri 沒起來、`snd-aloop` 未載入、或無空閒 jibri（都在錄）→ 加開 loopback 並 scale jibri |
| 錄影中文變方框 / 缺字 | jibri 映像缺 CJK 字型 → 改用第五節的 `jibri-cjk` 映像 |
| 錄不到 2 場 / 第 2 場排不到 | `snd-aloop` 裝置數或 jibri 實例數不足 2 → 依第二、四節把兩者都補到 2（資源也要夠） |
| `modprobe snd-aloop` 失敗 | 這台是 LXC 容器、非 VM → 改用 VM（見第一節） |
| 黑畫面 / 無聲的錄影檔 | `/dev/snd` 未掛進容器、snd-aloop 異常，或主機資源不足 |

---

## 八、升級 SOP

升級時 Jibri 要跟著 Jitsi 走同一個 `stable-<版本>`；若用了第五節的 `jibri-cjk` 自訂映像，**記得用新版本號重 build**（否則 CJK 字型映像還停在舊版）。`snd-aloop`（第二節）與版本無關，不必重做。

```bash
cd docker-jitsi-meet

# (1) 備份設定與既有錄影
cp -a ~/.jitsi-meet-cfg ~/.jitsi-meet-cfg.bak-$(date +%Y%m%d)

# (2) 取得新版（換成目標 tag）
git fetch --tags
git checkout stable-<新版本>
#   .env 內若有 JITSI_IMAGE_VERSION，確認＝stable-<新版本>

# (3) 重 build CJK 版 jibri 映像（用新版號）
sed -i 's/stable-[0-9]*/stable-<新版本>/' jibri-cjk/Dockerfile
docker build -t jibri-cjk:stable-<新版本> ./jibri-cjk
#   jibri.yml / override 內 jibri 服務的 image: 也改成 jibri-cjk:stable-<新版本>

# (4) 拉取其餘官方映像並重啟（維持 2 個 jibri 實例）
docker compose -f docker-compose.yml -f jibri.yml pull
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=2
```

升級後驗證：

```bash
docker compose -f docker-compose.yml -f jibri.yml ps     # web/prosody/jicofo/jvb + 2 個 jibri 都 Up
```

- 開一場會議按錄影 → 確認能錄、且**中文非缺字方框**（第五節）。
- 同時開 2 間會議室都能錄 → 確認並行（第四節）未受升級影響。

> 本文以 `stable-10888` 為準；把上面 `<新版本>` 換成要升的 tag 即可。
> `snd-aloop` 是核心模組、與映像版本無關，升級時不用重設（除非你重灌了 VM）。

---

## 九、獨立 Jibri VM（與 Jitsi 分機）實作步驟

正式環境建議 Jibri 獨立一台 VM（見「部署拓撲」）。以下為實機驗證過的完整步驟，假設：

- 主 Jitsi 主機：`192.168.1.134`（docker-jitsi-meet 在 `/opt/docker-jitsi-meet`，對外 `meet.example.com`）。
- Jibri VM：另一台、**KVM 非 LXC**、同網段可連到主機。

### 9-1 主 Jitsi 主機（`192.168.1.134`）端

```bash
cd /opt/docker-jitsi-meet
# (1) 啟用錄影
sed -i 's/^#\?ENABLE_RECORDING=.*/ENABLE_RECORDING=1/' .env || echo "ENABLE_RECORDING=1" >> .env

# (2) 對 Jibri VM 開放 prosody 的 c2s 埠 5222（standalone Jibri 要連它）
#     用 docker-compose.override.yml 發佈，綁定主機 LAN IP（不對外）
cat >> docker-compose.override.yml <<'EOF'
  prosody:
    ports:
      - "192.168.1.134:5222:5222"
EOF
#     注意：override 的 services: 區塊只能有一個，已有其他服務時把 prosody 併進去

docker compose up -d prosody jicofo     # 套用錄影 + 5222
```

取出 Jibri VM 要用的值（**密碼勿外流**）：

```bash
grep -E '^JIBRI_XMPP_PASSWORD=|^JIBRI_RECORDER_PASSWORD=' .env   # 兩個密碼，等下複製到 Jibri VM
# XMPP domains（用映像預設即可，本版為）：
#   XMPP_DOMAIN=meet.jitsi  AUTH=auth.meet.jitsi  INTERNAL_MUC=internal-muc.meet.jitsi
#   RECORDER(hidden)=hidden.meet.jitsi   brewery=jibribrewery
# 可從 prosody 確認：docker exec docker-jitsi-meet-prosody-1 sh -c 'grep -E "^VirtualHost|^Component" /config/conf.d/jitsi-meet.cfg.lua'
```

### 9-2 Jibri VM 端

1) 先完成**第二節（snd-aloop ×N）**與 Docker 安裝。

2) 取 repo、建 `.env`（XMPP 指向主機、密碼與主機一致）：

```bash
cd /opt && git clone https://github.com/jitsi/docker-jitsi-meet.git
cd docker-jitsi-meet && git checkout stable-10888
cp env.example .env && ./gen-passwords.sh        # 先填齊欄位
mkdir -p ~/.jitsi-meet-cfg/jibri/recordings

# 設定（換成你的主機 IP / 網域；JIBRI_*_PASSWORD 填主機那兩個值）
cat >> .env <<'EOF'
PUBLIC_URL=https://meet.example.com
TZ=Asia/Taipei
ENABLE_RECORDING=1
XMPP_SERVER=192.168.1.134
XMPP_PORT=5222
XMPP_TRUST_ALL_CERTS=1
XMPP_DOMAIN=meet.jitsi
XMPP_AUTH_DOMAIN=auth.meet.jitsi
XMPP_INTERNAL_MUC_DOMAIN=internal-muc.meet.jitsi
XMPP_MUC_DOMAIN=muc.meet.jitsi
XMPP_RECORDER_DOMAIN=hidden.meet.jitsi
JIBRI_BREWERY_MUC=jibribrewery
JIBRI_XMPP_USER=jibri
JIBRI_RECORDER_USER=recorder
JIBRI_RECORDING_DIR=/config/recordings
JIBRI_XMPP_PASSWORD=<貼主機的 JIBRI_XMPP_PASSWORD>
JIBRI_RECORDER_PASSWORD=<貼主機的 JIBRI_RECORDER_PASSWORD>
EOF
```

> **關鍵**：`XMPP_SERVER` 指向主機、`XMPP_TRUST_ALL_CERTS=1`（prosody 內部憑證為自簽）、`JIBRI_*_PASSWORD` 與主機**完全一致**（jibri/recorder 帳號是註冊在主機 prosody 上）；XMPP domains 與主機相同。

3) 建 CJK 映像（第五節，**注意不要 `USER jibri`**）。

4) 建獨立 compose（只跑 jibri、自帶 `/dev/snd` 與 `extra_hosts`）：

```yaml
# docker-compose.jibri-standalone.yml
services:
  jibri:
    image: jibri-cjk:stable-10888
    restart: unless-stopped
    volumes:
      - ${CONFIG}/jibri:/config:Z
    shm_size: "2gb"
    cap_add: [ SYS_ADMIN ]
    devices: [ "/dev/snd:/dev/snd" ]
    extra_hosts:
      - "meet.example.com:192.168.1.134"   # 讓錄影 Chrome 解析到主機內網 IP
    environment:
      - PUBLIC_URL
      - TZ
      - XMPP_SERVER
      - XMPP_PORT
      - XMPP_TRUST_ALL_CERTS
      - XMPP_DOMAIN
      - XMPP_AUTH_DOMAIN
      - XMPP_INTERNAL_MUC_DOMAIN
      - XMPP_MUC_DOMAIN
      - XMPP_RECORDER_DOMAIN
      - JIBRI_XMPP_USER
      - JIBRI_XMPP_PASSWORD
      - JIBRI_RECORDER_USER
      - JIBRI_RECORDER_PASSWORD
      - JIBRI_BREWERY_MUC
      - JIBRI_RECORDING_DIR
      - DISPLAY=:0
```

5) 起 2 路：

```bash
docker compose -f docker-compose.jibri-standalone.yml up -d --scale jibri=2
```

### 9-3 驗證

```bash
# Jibri VM：兩個容器都 running，且 log 出現 "Joined MUC: jibribrewery@internal-muc.meet.jitsi"
docker compose -f docker-compose.jibri-standalone.yml ps
docker logs <jibri容器> 2>&1 | grep -E "Authenticated|Joined MUC"

# 主機 jicofo：應看到 2 個 brewery 實例 available = true
docker logs docker-jitsi-meet-jicofo-1 2>&1 | grep -i "brewery instance"
```

最後開一場會議按錄影實測（中文不缺字、可同時錄 2 間）。

> 常見坑：① CJK Dockerfile 結尾誤加 `USER jibri` → 容器一直重啟（s6 權限）。② `JIBRI_*_PASSWORD` 與主機不一致 → log 顯示 authentication 失敗。③ 主機 prosody 5222 未對 Jibri VM 開放 → 連不上。④ Jibri VM 解析不到 `meet.example.com` 內網 IP → 用 `extra_hosts` 或內部 DNS 解決。
