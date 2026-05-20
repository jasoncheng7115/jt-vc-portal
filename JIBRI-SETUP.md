# Jibri 錄影 × 自建 Jitsi Meet 設定

接續 [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md)，本文在同一套 docker-jitsi-meet 上加上 **Jibri 錄影**，並特別處理兩個重點：**同時多會議室錄製**與**錄影中文顯示**。

> 適用版本：docker-jitsi-meet / `jitsi/jibri` **`stable-10888`**。
> 本文以 **同時 2 場錄製（2 個 Jibri 實例）** 為目標撰寫；要增減場數時，把文中所有「2」一起調整即可。

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

## 二、主機前置：載入 snd-aloop（決定可並行的場數）

在 **Jibri VM**（見第一節）裡，`snd-aloop` 必須在 **VM 的核心**載入；2 場並行就開 **2 個** loopback 裝置。

```bash
# 目標 2 場 → 開 2 個 loopback 裝置
sudo modprobe snd-aloop enable=1,1 index=0,1
cat /proc/asound/cards          # 應看到 2 張 Loopback 卡

# 開機自動載入並固定裝置數
echo 'snd-aloop' | sudo tee /etc/modules-load.d/snd-aloop.conf
printf 'options snd-aloop enable=1,1 index=0,1\n' | sudo tee /etc/modprobe.d/snd-aloop.conf
```

> 要增減場數，`enable=` / `index=` 就列對應數量。例：3 場 → `enable=1,1,1 index=0,1,2`。
> 若 `modprobe` 失敗（找不到模組 / 權限），多半是這台不是 VM 而是 LXC 容器——見第一節，必須改用 VM。

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
