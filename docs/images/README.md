# Pages 截圖

GitHub Pages 介紹頁使用的畫面截圖（已去除瀏覽器外框，僅保留網頁內容）。本目錄是繁體中文介面；`en/`、`ja/` 是同一組畫面的英文、日文介面版（檔名相同，Pages 依語言切換；英文 / 日文版的會議內容為英文，因為會議摘要只支援中英文）。畫面中的帳號、會議室、會議內容與統計數字皆為**虛構的示範資料**，網址與 IP 使用保留給文件的範例位址（`example.com`、`10.0.0.30`、`203.0.113.x`）。

| 檔名 | 內容 |
|---|---|
| `hero.png` | 主視覺：來賓等候頁（翻頁時鐘倒數） |
| `01-dashboard.png` | 儀表板：建立 / 進入會議室、近期清單 |
| `02-create.png` | 建立會議室：預約時段、大廳模式、單場逐字稿開關 |
| `03-usage.png` | 會議統計：活動圖、會議時長排行、主持人排行 |
| `04-audit.png` | 稽核記錄：篩選、CSV 匯出、SIEM 外拋 |
| `05-accounts.png` | 帳號管理：角色、2FA、逐字稿權限 |
| `06-settings.png` | 系統設定：左側目錄與各設定卡片 |
| `07-guest.png` | 來賓進入：先輸入名稱 |
| `08-wait.png` | 等候主持人開啟會議室 |
| `09-recordings.png` | 錄影記錄：容量、參與者、逐字稿狀態 |
| `10-room-customize.png` | 會議室自訂（自建 Jitsi Meet） |
| `11-settings-recording.png` | 錄製設定：Jibri 錄影服務、保留政策 |
| `12-transcript-summary.png` | 會議摘要：重點、決議與待辦（附時間點） |
| `13-transcript-speakers.png` | 逐字稿：波形播放器、發言者對應建議 |
| `14-settings-transcribe.png` | 逐字稿與摘要設定（jt-live-whisper） |
| `15-export-pdf.png` | 匯出的會議記錄 PDF（第一頁） |

`logo.png` / `logo-64.png` / `icon.svg` 為專案圖示。

重拍：`LOCALE=zh-TW|en|ja tests/fixtures/meeting-sim/pages-shots.sh`（維護者本機腳本，不在公開 repo）。
