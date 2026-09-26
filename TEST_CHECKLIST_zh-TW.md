# jt-vc-portal 發版測試計畫與檢查清單

> English version: [TEST_CHECKLIST.md](TEST_CHECKLIST.md)

> 作者：Jason Cheng · GitHub [@jasoncheng7115](https://github.com/jasoncheng7115) · 專案 [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

> **規則：推進 `app/config.php` 的 `APP_VERSION` 之前，本清單要完整跑一次，全部通過才可發版。**
> 任一項未通過（含 ZAP 出現 High / Medium）＝不發版，先修再重跑。

**發版流程**：跑本清單 → 全綠 → 推進版本號 → 部署 → 同步 `github/` → commit / push → 打 tag → 打包並上傳該版 Docker image 到 Release → 用公開連結驗證下載與 SHA-256。

**每一項都要有對應測試**：新增、變更任何功能時，同時在本清單（中、英兩份）補上對應項目，能自動化的補進 `tests/`。

---

## 目錄

0. [發版前置與機密檢查](#0-發版前置與機密檢查)
1. [自動化測試](#1-自動化測試)
2. [ZAP 弱點掃描（發版閘門）](#2-zap-弱點掃描發版閘門)
3. [功能測試](#3-功能測試)
4. [多語系（i18n）](#4-多語系i18n)
5. [安全測試](#5-安全測試)
6. [部署硬化驗證](#6-部署硬化驗證)
7. [OWASP Top 10:2025 逐項複查](#7-owasp-top-102025-逐項複查)
8. [滲透抽測](#8-滲透抽測)
9. [發版與 Release 資產](#9-發版與-release-資產)
10. [自動化測試對照表](#10-自動化測試對照表)

---

## 0. 發版前置與機密檢查

- [ ] `APP_VERSION` 規則：修正 patch++、功能 minor++。
- [ ] 本版所有變更已逐項做 OWASP Top 10:2025 複查（第 7 節）。
- [ ] **GitHub 公開內容不得含機密 / 隱私 / 帳密**（程式、`.md`、Pages、截圖、測試腳本都算）：
  - [ ] `git -C github ls-files | grep -iE '\.(key|pem|pk|pub|p12|pfx|jsonl)$|private\.|settings\.json|users\.json|auto-allow|INITIAL_ADMIN|logo\.img|^release/'` 為空。
  - [ ] `grep -rIl "BEGIN.*PRIVATE KEY" github/` 為空。
  - [ ] 無真實 JaaS tenant（`vpaas-magic-cookie-` 後接真實 ID）、內部網域、內網 IP、真實 Email、真實密碼 / token（範例值必須是明顯假值）。
  - [ ] 截圖已遮蔽帳號、IP、會議室名稱等可識別資訊。
- [ ] Docker image 內無機密：`docker run --rm --entrypoint sh <image> -c 'find / \( -name private.key -o -name "*.json" -path "*jaas*" -o -name users.json \) 2>/dev/null'` 無結果。
- [ ] README / 各 `.md`（英文與 `_zh-TW`）/ Pages 與本版功能一致，版本號已更新。

## 1. 自動化測試

- [ ] PHP 語法：`docker run --rm -v "$PWD/app":/app -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'`
- [ ] 單元測試：`tests/run-unit.sh` 全綠。
- [ ] 整合測試：`tests/run-integration.sh` 全綠。
- [ ] i18n 檢查：`tests/check-i18n.php` 無未翻譯字串、無缺漏鍵（v1.7.0 起）。
- [ ] 一次跑完以上：`tests/run-all.sh`（lint → i18n → 單元 → 整合）。
- [ ] 瀏覽器 e2e：`tests/run-e2e.sh` 中英兩輪全綠（主持人進會議、來賓加入、無 CSP / SRI 錯誤）。

## 2. ZAP 弱點掃描（發版閘門）

- [ ] 執行 `tests/zap/run-zap.sh <版本>`：對本機拋棄式容器做「未登入」與「已登入」兩輪 spider + 被動 + 主動掃描。
- [ ] 兩份報告（`zap-reports/zap-<版本>-anon.md`、`-authed.md`）的 **High = 0 且 Medium = 0**。
- [ ] Low / Informational 逐項判讀，於發版紀錄寫明「接受 / 誤報」理由（例：測試容器為 HTTP，Secure cookie / HSTS 由正式反向代理提供）。
- [ ] 報告只存本機 `zap-reports/`，不進 GitHub（可能含內部資訊）。

## 3. 功能測試

### 3.1 認證與帳號
- [ ] 首次啟動自動建立管理員：有設 `JTVC_ADMIN_PASSWORD` 用之；沒設則產生隨機密碼寫入 `INITIAL_ADMIN_PASSWORD.txt`。
- [ ] 以帳號或 Email 登入（大小寫不敏感）；錯誤帳密顯示通用訊息與剩餘次數。
- [ ] 2FA：啟用（掃 QR / 手動金鑰）→ 登入需驗證碼 → 停用需密碼；同一組驗證碼不可重用。
- [ ] 個人設定：改顯示名稱；改密碼需舊密碼、新密碼至少 10 字、兩次一致。
- [ ] 帳號管理（admin）：新增 / 編輯 / 停用 / 刪除 / 改角色 / 重設密碼；不能刪除或停用自己；至少保留一位啟用中的管理員。
- [ ] 帳號被停用後，其既有 session 下一個請求即失效。
- [ ] Session 閒置逾時（預設 30 分）與絕對逾時（預設 12 小時）生效。
- [ ] 角色頁籤：host 只見「會議室管理」＋（有 Jibri 時）「錄影記錄」；admin 見全部。

### 3.2 會議室（主持人）
- [ ] 建立會議室：自訂名稱 / 亂數名稱；非 ASCII 字元自動移除、空白轉 `-`。
- [ ] 建立表單輸入已存在的名稱 → 擋下並保留表單內容。
- [ ] 近期清單：顯示排程、主持人狀態徽章、受邀人數；複製邀請連結、QR 視窗。
- [ ] 「進入 / 立即主持 / QR 視窗進入」以 POST 表單送出，可正常進入會議；不會清掉原本的大廳設定與受邀名單。
- [ ] 進入他人擁有的會議室被擋（admin 除外）。
- [ ] Jitsi IFrame API 使用內附的 `assets/vendor/jitsi-external-api.js` 並帶 SRI；會議頁 CSP 的 `frame-src` 只允許 Jitsi 網域。更新 API 用 `tools/update-jitsi-external-api.sh` 後必跑 e2e。
- [ ] 會議頁：Jitsi 正常載入、語言正確、分享按鈕（QR / 複製）可用、錄影開始 / 停止提示。
- [ ] 主持人心跳每 15 秒回報；關閉分頁送出離開通知；45 秒無心跳視為離線。
- [ ] 大廳模式：主持人進場後自動開啟大廳，來賓需逐一允許。
- [ ] 「關閉視訊省頻寬」（預設開）：不因頻寬自動關閉他人視訊。
- [ ] 長會議（超過 1 小時）中途斷線可重新連上（主持人 JWT 12 小時）。

### 3.3 排程、Email 與 .ics
- [ ] 設定開始 / 結束時間；結束早於開始被擋。
- [ ] SMTP 啟用時填寫與會者 Email → 寄出含 `.ics` 的邀請，Google / Outlook / Apple 可加入行事曆。
- [ ] 無效 Email 被擋並提示。
- [ ] SMTP 測試信成功；失敗訊息正確顯示。

### 3.4 來賓
- [ ] 邀請連結 `/room/<名稱>` 與舊式 `/invite?room=` 皆可用。
- [ ] 狀態頁：等候主持人（spinner）、倒數（翻頁時鐘）、已結束；依設定秒數自動檢查、主持人到場自動進入。
- [ ] 可進場時必須先輸入名稱；重新點邀請連結要求重新輸入。
- [ ] 來賓 JWT 關閉錄影 / 直播 / 逐字稿 / 外撥；工具列不顯示逐字稿與直播。

### 3.5 會議記錄與統計
- [ ] 主持人離開時寫入 `meetings.jsonl`（時長、尖峰同時人數、參與者進出時間）。
- [ ] 瀏覽器當掉未送離開通知：下次心跳 / 進場或房間被清除時，以最後心跳時間結算，時長不會暴增。
- [ ] `/usage`：統計卡、近 30 天活動圖、會議時長時間軸、時長排行 Top 25、主持人排行、參與者明細；JaaS 模式另有 MAU 與歷史趨勢。
- [ ] 記錄保留天數設定生效（清理不遺失同時寫入的新記錄）。

### 3.6 錄影（自建 Jibri）
- [ ] Jibri 服務 URL / token 設定、連線狀態、錄製器數量顯示。
- [ ] 錄影列表、搜尋（會議室 / 主持人 / 參與者）、展開參與者、線上播放（Range 拖曳）、下載。
- [ ] 刪除、依政策清理、容量條、保留政策（時間 / 容量 / 殘留，預設停用）僅 admin。
- [ ] host 只看得到、只取得到自己主持場次的錄影；房名被他人重新建立後，新擁有者看不到舊場次。
- [ ] 錄影狀態（ok / recording / incomplete / orphan）判定正確；錄製中不可刪除。

### 3.7 系統設定（admin）
- [ ] 連線模式：JaaS 與自建兩組設定分開保存、切換不互相覆蓋；自建模式顯示需求提示。
- [ ] 密鑰欄位（自建 JWT 共享密鑰、SMTP 密碼、Jibri token）不回填到頁面；留空送出保留原值；SMTP 可勾選清除密碼。
- [ ] 站台名稱 / logo 上傳（PNG / JPEG / WebP / GIF，2MB 內）/ 恢復預設。
- [ ] 外觀主題 22 種切換，深色主題樣式正確。
- [ ] 會議室介面：Jitsi 語言、記錄保留天數、來賓檢查秒數。
- [ ] 會議室自訂（僅自建模式）：靜音 / 關鏡頭、解析度、預設檢視、工具列開關、省頻寬開關。
- [ ] 錄製設定：錄製者顯示名稱。
- [ ] 方案 MAU 上限、計費週期起始日、本期用量手動校正（僅 JaaS）。
- [ ] SIEM 外拋：syslog / CEF / GELF × UDP / TCP，測試送出成功。
- [ ] 設定匯出 / 匯入：白名單鍵、含 logo；來回一致。
- [ ] 登入路徑偽裝：改路徑後舊路徑 404、新路徑可登入；`login-path.php show|reset|set` CLI 可用、網頁存取 404。

### 3.8 稽核記錄
- [ ] 各行為（登入成功 / 失敗 / 鎖定、登出、建立 / 進入會議室、來賓進入、寄邀請、帳號 CRUD、設定變更、密碼 / 2FA、錄影下載 / 刪除 / 清理、匯出）都有記錄。
- [ ] 依行為類型、關鍵字、日期篩選；分頁；CSV 匯出帶目前篩選、UTF-8 BOM、公式注入防護。
- [ ] 每筆即時外拋 SIEM（啟用時）。

### 3.9 USAGE webhook（JaaS）
- [ ] 正確 Authorization 或 HMAC 簽章 → 計入本期 unique 裝置；同 idempotencyKey 不重複計算。
- [ ] 非 USAGE 事件回 200 略過；錯誤簽章 / 過期時間戳 → 403。

### 3.10 其他 UI
- [ ] 錯誤頁（400 / 401 / 403 / 404 / 500 / 502 / 503）套站台主題。
- [ ] 自訂確認對話框取代瀏覽器原生 confirm（刪除帳號、刪除錄影等）。
- [ ] 表格欄位點擊排序、卡片收合、右上帳號選單（點外面 / Esc 收合）。
- [ ] 手機寬度版面可用。

## 4. 多語系（i18n）

（v1.7.0 起）
- [ ] 介面語言偵測優先序：`?lang=` → 登入者個人設定 → cookie → 瀏覽器 Accept-Language → 英文。
- [ ] 右上 / 來賓頁語言切換（繁體中文 / English）立即生效並記住；登入者存到個人設定。
- [ ] 以英文瀏覽器逐頁巡查（登入、2FA、儀表板、會議、來賓各狀態、帳號、個人設定、稽核、統計、錄影、設定、錯誤頁）：無殘留中文（語言名稱「繁體中文」除外）。
- [ ] 以中文瀏覽器逐頁巡查：與改版前文字一致、無英文殘留。
- [ ] JS 動態文字（複製成功、確認框、倒數單位、錄影提示）跟隨語言。
- [ ] 語言切換端點 `/set-lang?l=&r=` 只導回站內相對路徑（`//evil`、`https://…` 一律導回 `/`）；字典目錄 `/lang/` 直接存取 → 403。
- [ ] 個人設定「介面語言」可選自動 / 繁體中文 / English，登入後即套用。
- [ ] 預設站台名稱 / 錄製者名稱 / 寄件人名稱未自訂時依語言顯示；英文介面儲存設定不會把英文預設值存成自訂值。
- [ ] Jitsi 會議語言可設為「跟隨介面語言」。
- [ ] Email / .ics 預設範本依寄送者語言；自訂範本原樣使用。
- [ ] `tests/check-i18n.php`：所有 `t()` 鍵都有英文翻譯、程式中無未包 `t()` 的中文字串。
- [ ] 所有 `.md` 皆有英文（預設）與 `_zh-TW.md`，檔頭互相連結。
- [ ] GitHub Pages：中 / 英切換、依瀏覽器語言自動判斷、`?lang=` 可指定並記住。

## 5. 安全測試

### 5.1 存取控制（A01）
- [ ] 未登入存取 `/dashboard`、`/accounts`、`/settings`、`/recordings`、`/usage`、`/audit-log`、`/profile`、`/meeting` 一律 404。
- [ ] host 打 admin 端點（`/accounts`、`/settings`、`/audit-log`、`/audit-export`、`/usage`、`/account-save`、`/account-delete`、`/save-settings`、`/save-site`、`/set-theme`、`/settings-export`、`/settings-import`、`/recordings-action`）→ 404。
- [ ] host 以他人錄影 id 打 `/recordings-file` → 404。
- [ ] `/host-heartbeat`、`/host-left`：GET → 405；未登入 → 403；缺 CSRF → 403；非擁有者 → 403。
- [ ] `/start`：GET 不做任何變更；POST 缺 CSRF → 403。
- [ ] `/room-status`：未持該房邀請且未登入 → 只回 `unknown`。

### 5.2 CSRF
- [ ] 所有狀態變更 POST 皆驗 `_csrf`（或 `X-CSRF-Token`），缺 / 錯回 403。
- [ ] 跨站頁面以連結 / 表單 / fetch 觸發建立會議室、主持人在線、設定變更均失敗。

### 5.3 XSS / 注入
- [ ] 顯示名稱、來賓名稱、參與者名稱、站台名稱、稽核關鍵字含 `<script>`、`"`、`</script>`、`'` → 所有頁面正確跳脫。
- [ ] 會議頁 / 統計頁 JS 內動態值經 `json_encode`。
- [ ] CSV 匯出 `= + - @` 開頭加前綴。
- [ ] Email 標頭、SIEM 記錄的 CR / LF 被剝除。

### 5.4 認證（A07）
- [ ] 同一 IP 10 分鐘內 5 次失敗 → 鎖 30 分；連續 3 次鎖定 → 24 小時；2FA 失敗也計入。
- [ ] 同一帳號（跨 IP）15 分鐘內 10 次失敗 → 帳號鎖 15 分；不存在的帳號行為一致；鎖定檔不存明文帳號。
- [ ] 帳號是否存在無法由訊息或回應時間分辨。
- [ ] 登入後 session id 更新；Cookie 具 HttpOnly、SameSite=Lax，HTTPS 下具 Secure。
- [ ] 登入路徑偽裝不外洩：未登入時 `/verify`（GET）、`/twofa`、`/twofa-verify` → 404；`/logout` → 導回 `/`。

### 5.5 資料完整性
- [ ] 並發寫入（多位主持人心跳 + 建立會議室 + 設定變更）不遺失資料；讀者不會讀到空檔（單元測試涵蓋）。
- [ ] 設定匯入只接受白名單鍵與正確型別。
- [ ] Webhook 簽章 constant-time 比對、±5 分鐘重放視窗。

### 5.6 檔案與路徑
- [ ] logo 上傳：MIME 白名單、2MB、固定檔名；偽裝副檔名被拒；以圖片 Content-Type + nosniff 提供。
- [ ] 直接存取 `/lib/`、`/keys/`、`*.key|pem|pk|pub|json` → 403。
- [ ] 錄影 id 正規表示式驗證，無法路徑穿越（portal 與 Jibri API 兩端）。

## 6. 部署硬化驗證

- [ ] 容器埠僅反向代理可達（同主機綁 `127.0.0.1`，異主機靠 `JTVC_TRUSTED_PROXIES` + 防火牆）。
- [ ] `JTVC_TRUSTED_PROXIES` 已設定；從非可信來源偽造 `X-Real-IP` 不影響限流與稽核 IP。
- [ ] 全程 HTTPS；反向代理送 HSTS；`X-Frame-Options`、`X-Content-Type-Options`、`Referrer-Policy`、CSP 生效。
- [ ] 主機權限：`keys/` 750（群組 www-data）、`private.key` 600（www-data）；`data/` 750、檔案 640；build context 內無 `private.key`。
- [ ] 容器內 `www-data` 可讀私鑰、可寫資料目錄。
- [ ] `INITIAL_ADMIN_PASSWORD.txt` 首次登入後已刪除。
- [ ] `docker build --pull`；Trivy 掃描 `--ignore-unfixed` HIGH / CRITICAL 為 0。

## 7. OWASP Top 10:2025 逐項複查

針對本版每一項變更：
- [ ] **A01 存取控制失效**：新端點有 requireLogin / requireAdmin 與資源擁有權檢查；狀態變更僅 POST + CSRF。
- [ ] **A02 安全設定錯誤**：錯誤不外顯、版本不外露、檔案權限最小化、密鑰不回填頁面。
- [ ] **A03 軟體供應鏈**：無新增 PHP 相依；CDN 資源有 SRI；base image 已 `--pull`。
- [ ] **A04 加密失效**：密碼 bcrypt；JWT 簽章與效期合理；傳輸 HTTPS。
- [ ] **A05 注入**：輸出跳脫、`json_encode`、房名 ASCII、郵件 / 日誌 / CSV 注入處理。
- [ ] **A06 不安全設計**：限流（IP + 帳號）、預設安全、最小權限。
- [ ] **A07 認證失效**：2FA、session 逾時、通用錯誤訊息、登入路徑不外洩。
- [ ] **A08 軟體或資料完整性失效**：原子寫入 + 鎖、匯入白名單、webhook 驗簽。
- [ ] **A09 記錄與警示失效**：新行為有稽核記錄並外拋 SIEM；不記錄機密。
- [ ] **A10 例外狀況處理不當**：失敗回預設值 / 明確錯誤頁，不中斷主流程、不洩漏堆疊。

## 8. 滲透抽測

每次發版抽測，重大改動全測：
- [ ] 未授權列舉所有管理端點與錄影檔。
- [ ] host 水平 / 垂直越權（他人會議室、心跳、錄影、帳號、設定）。
- [ ] CSRF PoC（跨站連結、表單、fetch）。
- [ ] XSS PoC（各名稱欄位、設定欄位、稽核搜尋）。
- [ ] 偽造 `X-Real-IP` / `X-Forwarded-For` 繞過限流。
- [ ] 暴力登入與 2FA 暴力（IP 與帳號層鎖定）。
- [ ] Webhook 偽造 / 重放；SIEM / 郵件 / CSV 注入。
- [ ] 上傳非圖片 / 偽裝圖片 / 過大檔案；錄影 id 路徑穿越。

## 9. 發版與 Release 資產

- [ ] 部署正式機：lint → build（`--pull`）→ 換容器 → 健康檢查（首頁 200、登入頁、進入會議）。
- [ ] 同步 `github/`（只複製變動檔，勿用 `rsync --delete`），再跑一次第 0 節機密檢查。
- [ ] commit / push；建立並推送 `vX.Y.Z` tag。
- [ ] **打包該版 Docker image**：`jt-vc-portal-X.Y.Z-docker-amd64.tar.gz` + `.sha256`，驗證 image 內無機密。
- [ ] README 與 Pages 的版本號、下載連結同步更新。
- [ ] 建立 GitHub Release 並上傳 image 與 `.sha256`；以公開連結下載後 `sha256sum -c` 驗證。

## 10. 自動化測試對照表

| 項目 | 測試 |
|---|---|
| 原子寫入、並發讀改寫、讀者不見空檔、JSONL 清理不遺失 | `tests/unit/test_store.php` |
| 房間擁有者、canHost、心跳 / 離開結算、當掉 session 結算、並發建房、清除前結算、sanitize、evaluate | `tests/unit/test_rooms.php` |
| IP 限流、帳號層鎖定、錄影擁有權、JWT（來賓 features / 效期 / HS256）、設定並發 | `tests/unit/test_security.php` |
| 安全標頭、敏感路徑 403、登入路徑不外洩、登入、`/start` 僅 POST、心跳 / 離開 CSRF + 擁有權、設定頁不回填密鑰、帳號層鎖定、來賓流程 | `tests/run-integration.sh` |
| 語言偵測 / Accept-Language / t() / 英文字典完整性 / Email 預設範本 / 會議語言跟隨介面 | `tests/unit/test_i18n.php` |
| 英文 / 中文逐頁無殘留、html lang、?lang=、cookie、/set-lang 防開放重導、個人語言設定、lang/ 403 | `tests/run-integration.sh`（多語系段） |
| 未翻譯字串 / 缺漏鍵 / 佔位一致 | `tests/check-i18n.php` |
| 真瀏覽器：主持人進會議、來賓加入、iframe、SRI、CSP（中英） | `tests/run-e2e.sh` |
| 弱點掃描 | `tests/zap/run-zap.sh` |
