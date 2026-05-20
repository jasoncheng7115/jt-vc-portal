# Jibri 錄影 × 自建 Jitsi Meet 設定

接續 [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md)，本文在同一套 docker-jitsi-meet 上加上 **Jibri 錄影**，並特別處理兩個重點：**同時多會議室錄製**與**錄影中文顯示**。

> 適用版本：docker-jitsi-meet / `jitsi/jibri` **`stable-10888`**。

---

## 一、Jibri 是什麼、有什麼限制

- Jibri（Jitsi Broadcasting Infrastructure）以一個 **headless Chrome** 加入會議，再用 **ffmpeg** 把畫面與聲音擷取成 `.mp4`（或推 RTMP 直播）。
- **一個 Jibri 實例同時只能錄一場**。要同時錄 N 間會議室，就要 N 個 Jibri 實例。
- 需要 ALSA loopback（`snd-aloop`）虛擬音效裝置擷取聲音；**每個並行的 Jibri 各需一個獨立 loopback 裝置**。
- Jibri 很吃資源：每場約 1～2 vCPU + 1～2 GB RAM（headless Chrome + ffmpeg）。並行數越高，主機規格要越大。

---

## 二、主機前置：載入 snd-aloop（決定可並行的場數）

`snd-aloop` 必須在**主機核心**載入（不是容器內），且要依「想同時錄幾場」開出對應數量的 loopback 裝置。

```bash
# 範例：要同時錄 4 場 → 開 4 個 loopback 裝置
sudo modprobe snd-aloop enable=1,1,1,1 index=0,1,2,3
cat /proc/asound/cards          # 應看到 4 張 Loopback 卡

# 開機自動載入並固定裝置數
echo 'snd-aloop' | sudo tee /etc/modules-load.d/snd-aloop.conf
printf 'options snd-aloop enable=1,1,1,1 index=0,1,2,3\n' | sudo tee /etc/modprobe.d/snd-aloop.conf
```

> 想同時錄幾場，`enable=` / `index=` 就列幾組。例：同時 2 場 → `enable=1,1 index=0,1`。

---

## 三、啟用錄影（docker-jitsi-meet `.env`）

```ini
ENABLE_RECORDING=1
# 以下帳密 gen-passwords.sh 已產生，確認存在：
# JIBRI_RECORDER_USER / JIBRI_RECORDER_PASSWORD
# JIBRI_XMPP_USER / JIBRI_XMPP_PASSWORD
```

Jibri 服務以額外的 `jibri.yml` 疊加啟動（docker-jitsi-meet 慣例）：

```bash
docker compose -f docker-compose.yml -f jibri.yml up -d
```

Jibri 容器需存取主機 `/dev/snd`（jibri.yml 已設 `devices: /dev/snd`），並依賴上一步載入的 snd-aloop。

---

## 四、同時多會議室錄製（並行）

1. 主機 snd-aloop 已開 **N** 個裝置（見第二節）。
2. 把 jibri 服務 scale 到 N：
   ```bash
   docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=N
   ```
   每個 jibri 實例會佔用一張 loopback 卡；jicofo 會把每個錄影請求派給「空閒」的 jibri。
3. 確認：`docker compose -f docker-compose.yml -f jibri.yml ps` 應有 N 個 jibri 容器，且能在 N 間會議室同時開始錄影。

> 並行數 = `snd-aloop` 裝置數 = jibri 實例數，三者要一致；任一不足就只能錄到較少場數。

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
| 只能同時錄一場 | `snd-aloop` 只開 1 個裝置 / 只有 1 個 jibri 實例 → 依第二、四節擴充 |
| 黑畫面 / 無聲的錄影檔 | `/dev/snd` 未掛進容器、snd-aloop 異常，或主機資源不足 |

---

## 八、升級

升級 Jitsi / Jibri 時，jibri 與 jibri-cjk 映像都要換成新的 `stable-<版本>` 並重 build CJK 映像。本文以 `stable-10888` 為準。
