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

> 本文後續步驟以「同一台」寫，最容易上手；若要「獨立 VM」拓撲，把 Jibri 相關步驟搬到獨立 VM、並把 XMPP 連線指向主機即可。需要我另寫一份「獨立 Jibri VM」版的詳細設定再告訴我。

---

## 一、Jibri 是什麼、有什麼限制

- Jibri（Jitsi Broadcasting Infrastructure）以一個 **headless Chrome** 加入會議，再用 **ffmpeg** 把畫面與聲音擷取成 `.mp4`（或推 RTMP 直播）。
- **一個 Jibri 實例同時只能錄一場**。本文目標 **2 場並行 = 2 個 Jibri 實例**。
- 需要 ALSA loopback（`snd-aloop`）虛擬音效裝置擷取聲音；**每個並行的 Jibri 各需一個獨立 loopback 裝置**（2 場 → 2 個）。
- Jibri 很吃資源：每場約 1～2 vCPU + 1～2 GB RAM（headless Chrome + ffmpeg）。**2 場並行請預留約 3～4 vCPU + 3～4 GB RAM**（含系統餘裕）。

### 必須裝在「VM」、不能裝在容器（LXC）內

Jibri 需要在主機核心 **載入 `snd-aloop` 模組** 並存取 **`/dev/snd`**——這在共用宿主核心的容器（如 Proxmox LXC、其他 OS 級容器）裡**做不到**。因此：

- **請把「跑 Docker 的這台 Jibri 主機」開成一台 VM（KVM／完整虛擬機，有自己的核心）**，再在 VM 內用 Docker 起 2 個 Jibri 容器。
- 例：Proxmox → 開 **VM**（不是 LXC）→ 裝 Linux + Docker → 於 VM 內 `modprobe snd-aloop`。
- 「不能裝在容器裡」指的是不要把 Jibri 主機本身做成 LXC；Jibri 服務本身仍是在 VM 內以 Docker 容器執行（這是 OK 的，因為 VM 有獨立核心可載入模組、可給容器 `/dev/snd`）。

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

1. VM 的 snd-aloop 已開 **2** 個裝置（見第二節）。
2. 把 jibri 服務 scale 到 **2**：
   ```bash
   docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=2
   ```
   每個 jibri 實例會佔用一張 loopback 卡；jicofo 會把每個錄影請求派給「空閒」的 jibri，最多同時 2 場。
3. 確認：`docker compose -f docker-compose.yml -f jibri.yml ps` 應有 **2** 個 jibri 容器，且能在 2 間會議室同時開始錄影。

> 三者必須一致：`snd-aloop` 裝置數 = jibri 實例數 = 想並行的場數（本文皆為 2）。任一不足，超出的錄影請求會排不到 Jibri 而失敗 / pending。
> 要更多場：把第二節裝置數與此處 `--scale` 一起加大，並確認 VM 資源足夠。

---

## 五、錄影中文顯示（必做）

Jibri 是用 headless Chrome「把會議畫面錄下來」。**官方 `jitsi/jibri` 映像不含 CJK 字型**，因此中文姓名 / 聊天 / 字幕在錄影裡會變成「□□」豆腐字。需在 jibri 映像加裝中文字型。

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
USER jibri
```

build 並讓 compose 改用它：

```bash
docker build -t jibri-cjk:stable-10888 ./jibri-cjk
```

在 `jibri.yml`（或 `docker-compose.override.yml`）把 jibri 服務的 `image:` 改成 `jibri-cjk:stable-10888`，再重啟。

> 驗證：錄一段含中文姓名的會議，播放確認中文正常（非豆腐字）。Noto CJK 對繁體中文覆蓋最完整。

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
| 錄影中文變豆腐字 | jibri 映像缺 CJK 字型 → 改用第五節的 `jibri-cjk` 映像 |
| 錄不到 2 場 / 第 2 場排不到 | `snd-aloop` 裝置數或 jibri 實例數不足 2 → 依第二、四節把兩者都補到 2（資源也要夠） |
| `modprobe snd-aloop` 失敗 | 這台是 LXC 容器、非 VM → 改用 VM（見第一節） |
| 黑畫面 / 無聲的錄影檔 | `/dev/snd` 未掛進容器、snd-aloop 異常，或主機資源不足 |

---

## 八、升級

升級 Jitsi / Jibri 時，jibri 與 jibri-cjk 映像都要換成新的 `stable-<版本>` 並重 build CJK 映像。本文以 `stable-10888` 為準。
