# jt-vc-portal 發版測試計畫與檢查清單

> English: [TEST_CHECKLIST.md](TEST_CHECKLIST.md) · 日本語: [TEST_CHECKLIST_ja.md](TEST_CHECKLIST_ja.md)

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
- [ ] README / 各 `.md`（英文、`_zh-TW`、`_ja` 三版）/ Pages 與本版功能一致，版本號已更新；新功能在 Pages 有功能卡、比較表（JaaS / 自建）與設定文件；**畫面有改的截圖已用 `pages-shots.sh`（虛構資料）重拍**，截圖不含真實帳號、站名、網址或 IP。

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
- [ ] 本人改密碼：其他裝置的登入全部失效、目前這個 session 保留；管理員重設密碼同樣讓該帳號所有登入失效。
- [ ] 帳號管理勾選「強制登出此帳號所有已登入的裝置」→ 該帳號所有 session 失效，不影響其他帳號。
- [ ] 登出只接受 POST + CSRF：`GET /logout` 不會登出；右上「登出」按鈕與 2FA 頁「取消並重新登入」正常。
- [ ] Session 閒置逾時（預設 30 分）與絕對逾時（預設 12 小時）生效。
- [ ] 角色頁籤：host 只見「會議室管理」＋（有 Jibri 時）「錄影記錄」；admin 見全部。
- [ ] 來源 IP 被鎖定期間，登入頁只做顯示：鎖定訊息與停用的欄位，沒有送往 `/verify` 的表單（v1.12.0）。

### 3.2 會議室（主持人）
- [ ] 建立會議室：自訂名稱 / 亂數名稱；非 ASCII 字元自動移除、空白轉 `-`。
- [ ] 建立表單輸入已存在的名稱 → 擋下並保留表單內容。
- [ ] 近期清單：顯示排程、主持人狀態徽章、受邀人數；複製邀請連結、QR 視窗。
- [ ] 「進入 / 立即主持 / QR 視窗進入」以 POST 表單送出，可正常進入會議；不會清掉原本的大廳設定與受邀名單。
- [ ] 進入他人擁有的會議室被擋（admin 除外）。
- [ ] 刪除會議室：擁有者 / 管理員可刪（自訂確認框）；他人不可；有受邀者且 SMTP 啟用時寄出行事曆取消通知（Google / Outlook / Apple 會移除事件）；稽核有 `room_delete`。
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
- [ ] 同一會議室重寄邀請：`.ics` SEQUENCE 遞增、UID 不變，行事曆以新版取代舊版；中文長標題折行後仍正確顯示。
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
- [ ] Jibri 錄影 API：`tests/test-jibri-api.sh` 全綠（授權、Range 206 / 416、路徑穿越）。

### 3.7 系統設定（admin）
- [ ] 自建模式「不需 JWT」時設定頁顯示警示；建立會議室表單提示好猜的房名風險。
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
- [ ] 系統設定頁目錄（v1.11.0）：每張可見的設定卡片都有對應目錄項目（依連線模式隱藏的卡片，目錄也隱藏）；點選只顯示該卡片並把網址改為 `#id`；重新整理或直接開 `/settings#id` 會保持；儲存後停留在同一張卡片；「全部」顯示所有卡片；無 CSP 錯誤。
- [ ] 登入路徑偽裝：改路徑後舊路徑 404、新路徑可登入；`login-path.php show|reset|set` CLI 可用、網頁存取 404。

### 3.8 稽核記錄
- [ ] 各行為（登入成功 / 失敗 / 鎖定、登出、建立 / 進入會議室、來賓進入、寄邀請、帳號 CRUD、設定變更、密碼 / 2FA、錄影下載 / 刪除 / 清理、匯出）都有記錄。
- [ ] 依行為類型、關鍵字、日期篩選；分頁；CSV 匯出帶目前篩選、UTF-8 BOM、公式注入防護。
- [ ] 每筆即時外拋 SIEM（啟用時）。
- [ ] 稽核記錄保留天數（預設 365）生效：超過天數的記錄於查詢頁自動清除；超長帳號 / 詳情會被截斷。

### 3.9 USAGE webhook（JaaS）
- [ ] 正確 Authorization 或 HMAC 簽章 → 計入本期 unique 裝置；同 idempotencyKey 不重複計算。
- [ ] 非 USAGE 事件回 200 略過；錯誤簽章 / 過期時間戳 → 403。

### 3.10 其他 UI
- [ ] 錯誤頁（400 / 401 / 403 / 404 / 500 / 502 / 503）套站台主題。
- [ ] 自訂確認對話框取代瀏覽器原生 confirm（刪除帳號、刪除錄影等）。
- [ ] 表格欄位點擊排序、卡片收合、右上帳號選單（點外面 / Esc 收合）。
- [ ] 手機寬度版面可用。
- [ ] 語言選單（v1.12.0）：右上角語言切換只顯示目前語言；點擊開啟選單，列出所有語言並標示目前語言；按 Esc 或點外面收合；選擇語言即切換介面。

### 3.11 單一登入（SSO / OIDC，v1.10.0 起）

> 本系統不直接連 AD / LDAP；企業帳號一律經 OIDC IdP（Keycloak 獨立主機，見 [KEYCLOAK-SETUP_zh-TW.md](KEYCLOAK-SETUP_zh-TW.md)）。項目編號對應第 10 節測試。

- [ ] S01 預設停用；停用時 `/sso-login`、`/sso-callback` 回 404、登入頁無 SSO 按鈕。
- [ ] S02 Discovery 回傳的 issuer 必須與設定完全一致。
- [ ] S03 IdP 端點預設必須 HTTPS（內網 IdP 可在設定取消）。
- [ ] S04 擋雲端 metadata 主機；IdP 網址不可含帳密；不跟隨轉址。
- [ ] S05 授權請求帶 PKCE S256、state、nonce、固定 redirect_uri（`/sso-callback`）、scope 含 openid；token 交換帶 code_verifier 與 client 認證。
- [ ] S06 state 一次性：不符、重放同一個回呼網址 → 拒絕並記稽核。
- [ ] S07 登入交易 10 分鐘逾時。
- [ ] S08 id_token 驗簽：只接受 RS256 / RS384 / RS512；他人金鑰、竄改內容、`alg=none`、HS256 混淆一律拒絕；未知 kid 會重新抓 JWKS；驗簽失敗不退回 userinfo。
- [ ] S09 聲明檢查：iss、aud、azp、exp、iat、nonce、sub 任一錯誤即拒絕。
- [ ] S10 id_token 無群組時以 userinfo 補，但 userinfo 的 sub 必須與 id_token 相同。
- [ ] S11 群組 → 角色：管理員群組優先、主持人群組；不在任何群組拒絕登入；不分大小寫，Keycloak 路徑（/A/B）可比對。
- [ ] S12 帳號佈建：以 (issuer, sub) 綁定；與既有帳號同名或同 email → 拒絕（不自動併入）；停用的 SSO 帳號既有 session 立即失效且無法再登入；群組同步不會把最後一位管理員降級。
- [ ] S13 SSO 帳號沒有本地密碼：密碼登入失敗、管理員也無法替它設密碼；個人設定不顯示密碼與 2FA；帳號管理顯示 SSO 徽章。
- [ ] S14 僅限單一登入：非允許 IP 看不到本地密碼表單，直接 POST `/verify`（即使帶有效 CSRF token）也被擋；允許 IP 的緊急用本地管理員可登入；清單空白＝只允許本機。
- [ ] S15 沒有啟用中的本地管理員時不可開啟「僅限單一登入」；IP / CIDR 格式驗證。
- [ ] S16 登出：導向 IdP end_session（帶 id_token_hint），portal session 已清除。
- [ ] S17 啟用 SSO 時 CSP `form-action` 含 IdP 來源（登出可導向 IdP）。
- [ ] S18 稽核有 `sso_login` / `sso_fail`；使用者只看到通用錯誤訊息；原因已去控制字元、限長。
- [ ] S19 SSO 失敗計入來源 IP 限流；被鎖定的來源不能發起 SSO。
- [ ] S20 設定頁：client secret 不回填、留空沿用；「儲存並測試連線」可取得 IdP 設定與簽章金鑰；設定匯出含 `oidc`。
- [ ] S21 緊急 CLI `sso-cli.php show|disable-sso-only|disable` 可用；網頁存取 404。
- [ ] S22 ZAP 掃描涵蓋 `/sso-login`、`/sso-callback`，High / Medium 為 0。
- [ ] S23 Keycloak：首次登入強制設定 OTP；之後登入需 TOTP；同一時間窗內不可重用同一組碼。
- [ ] S24 Keycloak：連續 5 次錯誤暫時鎖定（門檻低於 AD）；client 強制 PKCE S256、只允許授權碼流程、非公開 client；未帶 PKCE 或未註冊 redirect_uri 的請求被拒。
- [ ] S25 `configure-realm.sh` 可重複執行：成功、client secret 不變、AD bind 密碼保留。
- [ ] S26 正式部署：Keycloak 管理介面（`/admin`）從外網存取回 404；discovery 的 issuer 為對外 https 網址；只有 VC-Admins / VC-Hosts 成員能登入；`/realms/master` 從外網存取回 404；從管理網段開 `https://<Keycloak 主機>:8443/admin/` 可正常登入管理介面（不出現「Something went wrong」）。
- [ ] S27 `configure-realm.sh` 設了 `KC_ADMIN_URL`：master realm 的 Frontend URL（與其 issuer）改為內網管理網址，第一次帶此設定執行也能成功（變更後重新登入 kcadm），對外 realm 的 issuer 與 client secret 不變。
- [ ] S28 Keycloak 介面語言：設定 `LOCALES` / `DEFAULT_LOCALE` 後，登入頁依瀏覽器語言顯示（zh-TW → 繁中 `zh-Hant`、ja → 日文、en → 英文、不支援的語言 → 預設），portal realm 與 master（管理員）realm 皆然。
- [ ] S29 正式環境 SSO 冒煙測試（每次發版、部署後）：以目錄服務建立的臨時帳號（管理員群組、主持人群組、無群組）經真實 IdP 登入並綁定 OTP；角色正確、SSO 帳號沒有本地密碼 / 2FA、登出同時結束 portal 與 IdP session、第二次登入只需 OTP、無群組帳號被拒；結束後臨時帳號從目錄服務、IdP 與 portal 全部移除。
- [ ] S30 顯示名稱：顯示名稱 claim 設為 `display_name` 時，portal 帳號的顯示名稱等於目錄服務的 `displayName`（例如「Jason Cheng」，不是只有姓），每次 SSO 登入都會更新；沒有 displayName 的帳號退回帳號名稱。由 `tests/run-sso.sh` 與正式環境冒煙測試（S29）涵蓋。

### 3.12 會議逐字稿與摘要（jt-live-whisper，v1.12.0 起）

- [ ] T01 逐字稿各層以 seq 對齊：時間取自 raw、文字取自 final（缺 final 時用 raw）、發言者取自 speakers 層；完全沒有 final 層時標示為未校正。
- [ ] T02 Webhook 簽章：對「timestamp.body」計算的 hex HMAC-SHA256 接受；錯誤密鑰、竄改內容、超過 300 秒的時間戳、非數字時間戳與 base64（8x8 形式）簽章一律拒絕；密鑰輪替期間新舊簽章皆接受。
- [ ] T03 權限等級：不可使用者無法使用逐字稿；手動、自動與管理員可以；未知值視為不可使用。
- [ ] T04 手動產生僅限主持人自己主持的場次；管理員可對任何場次；權限為不可使用的主持人既不能產生也不能檢視。
- [ ] T05 自動產生只針對設為「自動」的帳號。
- [ ] T06 單場開關優先於帳號預設（關閉勝過自動、開啟對手動有效），但不能超過帳號權限（不可使用仍為不可使用）；停用的帳號永遠不會自動處理。
- [ ] T07 排隊：待處理項目只建立一次；處理中或已完成的錄影不會再次排入；無效的錄影 id 與語言會被拒絕 / 改用預設值。
- [ ] T08 送給語音服務的會議提示：會議室、主持人顯示名稱、開始 / 結束時間與參與者，時間為 UTC ISO 8601；不帶標題。
- [ ] T09 Webhook 事件：只有本 portal 自己作業（system jtvc、job id 相符）的終止事件才會把結果標為完成；每個 event id 只處理一次。
- [ ] T10 發言者改名：只接受 S 代號與數字段落編號，剝除控制字元，上限 40 字。
- [ ] T11 刪除結果會移除檔案與索引項目。
- [ ] T12 錯誤訊息將已知錯誤碼對應為易讀文字；設定留空時保留 API 金鑰與 webhook 密鑰、去除 `/api/v1`、記錄啟用自動產生的時間，且納入設定匯出。
- [ ] T13 註冊 webhook 會儲存 endpoint id 與密鑰。
- [ ] T14 手動產生端對端：上傳、作業、完成；逐字稿、摘要 JSON 與 Markdown 皆已儲存；之後向 JTLW 確認並清除其內容；段落帶有時間、發言者與文字；external_ref 帶 system jtvc 與錄影 id。
- [ ] T15 語音服務送來的 webhook 通過簽章驗證並被記錄；簽章錯誤回 401、GET 回 405。
- [ ] T16 摘要失敗：部分結果保留逐字稿且尚未確認；按「重做摘要」可補完。
- [ ] T17 辨識失敗以失敗狀態結束並帶錯誤碼，不會自動重試。
- [ ] T18 佇列已滿（429）：回到待處理並延後重試時間（退避）。
- [ ] T19 取消處理中的作業後，狀態為已取消。
- [ ] T20 自動產生會處理「自動」帳號在啟用後錄的錄影；較舊的錄影與「手動」帳號略過。
- [ ] T21 重複的 webhook 事件不會破壞流程。
- [ ] T22 錄影消失時，其逐字稿、摘要與索引項目一併刪除（以及語音服務的作業記錄）。
- [ ] T23 權限為不可使用的主持人看不到逐字稿開關與欄位，逐字稿頁與下載回 404。
- [ ] T24 手動權限主持人：建立表單有開關（預設關閉）、有逐字稿欄位、已完成錄影有連結、已取消錄影有「重新產生」，不能存取其他主持人的場次（404）、不能刪除（404），缺 CSRF 回 403。
- [ ] T25 逐字稿頁顯示每一條都附引用的摘要與逐字稿；點引用會高亮被引用的段落；無 CSP 錯誤。
- [ ] T26 發言者全部一起改名後已儲存，重新整理後仍保留。
- [ ] T27 下載：含 [mm:ss] 與發言者名稱的純文字、SRT、摘要 Markdown、含發言者名稱的 JSON。
- [ ] T28 其他主持人存取非自己主持的場次回 404。
- [ ] T29 管理員：任何場次皆可；可從目錄進入設定卡片；API 金鑰不回填；帳號管理顯示權限選單與徽章。
- [ ] T30 稽核記錄會記下逐字稿事件，但絕不記錄逐字稿內容。
- [ ] T31 ZAP 涵蓋 /transcript、/transcript-download、/transcript-action 與 /jtlw-webhook（High 0、Medium 0）。
- [ ] T32 主要發言者時間軸清理：去控制字元、名稱長度上限，沒有名稱、結束早於開始或未來時間的項目丟棄，依開始時間排序。
- [ ] T33 心跳帶時間軸時存進房間暫存；主持人離開時寫進會議紀錄，剪進 session 範圍，最後一段未結束的算到散會，房間暫存清掉。會議頁監聽 Jitsi 的 dominantSpeakerChanged。
- [ ] T34 發言者建議：每個代號對到重疊時間最多的參與者；錄影開始時間差幾秒也對得上（在 ±10 秒內找偏移）。
- [ ] T35 分不清時列出多人（較多的在前）；沒有時間軸或重疊太少時不建議；參與者名單去重。
- [ ] T36 逐字稿頁顯示建議區塊（例如「Amy 100%」），改名輸入框可挑參與者，「全部套用最可能的人」會改掉每位發言者且重新整理後仍在，已套用的建議有標示。
- [ ] T37 沒有時間軸的場次不顯示建議區塊，改顯示可手動挑選名稱的提示。
- [ ] T38 送件後連不上語音服務時最多等 24 小時，超過就標為失敗（jtlw_unreachable）並附白話原因。
- [ ] T39 摘要因語言模型暫時故障而失敗時，會排定自動重做，重做後完成，並記錄自動重做次數。
- [ ] T40 錄影記錄頁：失敗狀態是與按鈕同高的標籤，原因在滑過提示與展開列中，同一列各欄垂直置中。
- [ ] T41 錄製中的錄影：列表中的播放、下載、刪除反灰，串流 / 下載請求回 409，刪除請求被拒並顯示原因；每個操作按鈕有自己的提示文字。
- [ ] T42 正式環境模擬會議（維護者，每次發版）：兩位參與者在真實會議中對話並由 Jibri 錄影，錄影經真實語音服務產生逐字稿與摘要；逐字稿分出兩位發言者並含關鍵內容，摘要列出決議與待辦，發言者建議正確指出兩位參與者。
- [ ] T43 錄影記錄頁：已完成（綠底「查看逐字稿與摘要」）與尚未產生（虛線框「產生逐字稿」）外觀明顯不同；處理中的作業用狀態標籤右端的 ✕ 取消，✕ 有提示文字。
- [ ] T44 會議記錄匯出：逐字稿頁的 PDF、DOCX、ODT、HTML（v1.15.0）可下載整份會議記錄（檔名 `<會議室>-<日期>-meeting.<副檔名>`），可檢視逐字稿的人才能下載，其他人 404。
- [ ] T45 匯出內容：會議資訊、重點摘要、決議與待辦（負責人 / 期限、出處時間與發言者）、事件與影響、風險、未決問題、議題時間軸、發言統計，以及完整逐字稿（套用改名、發言者配色）。PDF 中日文正常顯示、文字可選取與搜尋、只嵌入用到的字形（檔案小）；標題左側藍條在 Word 與 LibreOffice 都不超出左邊界；.docx 用 Microsoft Word、.odt 用 LibreOffice 開啟不會出現修復提示；HTML 為單一檔案（樣式內嵌、沒有 script、不連外部資源、內容全部跳脫），手機閱讀與列印都正常。
- [ ] T46 匯出語言：標籤與分隔符號跟隨介面語言；XML 不允許的控制字元會被去掉，檔案不會損毀。
- [ ] T47 環境檢查：系統設定頁列出缺少的 PHP 擴充（openssl、curl、mbstring、fileinfo、json、zlib）或隨附字型，以及影響哪些功能；沒有 zlib 的主機匯出時顯示看得懂的訊息，而不是錯誤 500。
- [ ] T48 逐字稿頁：PDF / DOCX / ODT / HTML 四個按鈕；其他格式（純文字、SRT、JSON、Markdown）收在「其他格式」選單，點了才展開，點外面或按 Esc 收起。
- [ ] T49 播放失敗訊息：登入逾時後按播放，要明確說「登入已逾時」並附重新登入連結，播放器保留；檔案已刪除、瀏覽器無法播放、連不到錄影服務各有對應訊息。`/session-check` 只回是否登入。
- [ ] T50 背景排程檢查：啟用逐字稿後，若排程超過 10 分鐘沒有執行，系統設定頁會警示並列出 Docker 與直接安裝的排程指令。
- [ ] T51 依語言決定語音服務的做法：台語（閩南語）用台語專用模式 `transcribe.taiwanese`、不做發言者分離；其他語言用設定的模式並分發言者。
- [ ] T52 送件語言優先順序：明確指定 > 建立會議室時選的主要語言 > 系統預設；非法值不採用。
- [ ] T53 會議室記下主要語言並寫進會議紀錄；取消勾選逐字稿會清掉語言。
- [ ] T54 建立會議室表單：勾選「錄影完成後產生逐字稿與摘要」才出現必填的「會議主要語言」（中文 / 英文 / 日文 / 韓文 / 台語（閩南語）為主，不用帶政治意涵的稱呼），取消勾選即收起。
- [ ] T55 勾了逐字稿卻沒選有效語言，伺服器端擋下；錄影記錄頁有「主要語言」欄。
- [ ] T56 會議沒選語言的錄影，按「產生逐字稿」會先問主要語言；選台語後送台語模式、不含發言者分離，結果能正常取回（不會一直停在處理中）。
- [ ] T57 台語逐字稿頁：摘要標示「僅供參考」；不顯示發言者欄、發言統計與發言者建議；顯示主要語言；匯出檔也有同樣的提醒。
- [ ] T58 會議統計頁：「近 30 天活動」為堆疊長條圖；會議時長排行最長在上、長條尾端標時長；「本期會議時長時間軸」預設收起、點標題展開（收起時標題仍顯示場次與總時長）；圖表跟著深色主題。
- [ ] T59 Jitsi Meet 故障時進會議室：主持人與來賓看到友善的「視訊服務暫時無法使用」頁（HTTP 503、每 30 秒自動重試、不顯示內部位址），不載入打不開的會議；會議畫面 20 秒內沒有回應或 Jitsi 回報致命錯誤時，顯示「無法連線到會議」遮罩與重試按鈕；Jitsi 正常時不會出現遮罩。
- [ ] T60 管理員儀表板「系統狀態」：Jitsi Meet（網頁與 XMPP / BOSH 端點）與 Jibri（服務連得到、每台錄製器自己的健康狀態、磁碟空間）以綠 / 橘 / 紅顯示並附原因；開頁後才檢查、快取 30 秒、有重新檢查按鈕；`/health` 限管理員、不洩漏內部細節。另：自建 Jitsi 不需 JWT 時，主持人可進入會議（修正）。
- [ ] T61 可重試的辨識失敗（例如語音服務的 GPU 伺服器暫時不能用）在 10 / 30 / 60 分鐘後自動用 `retry` 重試，不重新上傳錄影；錄影記錄顯示「等待重試」與原因；重試次數用完或按「重新產生」時，刪掉 JTLW 端的作業（連同它保留的錄影）。
- [ ] T62 `meeting.detailed`（JTLW 已停用）自動改用 `meeting.balanced`。

## 4. 多語系（i18n）

（v1.7.0 起）
- [ ] 介面語言偵測優先序：`?lang=` → 登入者個人設定 → cookie → 瀏覽器 Accept-Language → 英文。
- [ ] 右上 / 來賓頁語言切換（繁體中文 / English / 日本語）立即生效並記住；登入者存到個人設定。
- [ ] 以英文瀏覽器逐頁巡查（登入、2FA、儀表板、會議、來賓各狀態、帳號、個人設定、稽核、統計、錄影、設定、錯誤頁）：無殘留中文（語言名稱「繁體中文」除外）。
- [ ] 以中文瀏覽器逐頁巡查：與改版前文字一致、無英文殘留。
- [ ] 以日文瀏覽器逐頁巡查：無殘留中文 / 英文介面文字（v1.9.0 起；語言名稱如「繁體中文」/「English」除外）。
- [ ] JS 動態文字（複製成功、確認框、倒數單位、錄影提示）跟隨語言。
- [ ] 語言切換端點 `/set-lang?l=&r=` 只導回站內相對路徑（`//evil`、`https://…` 一律導回 `/`）；字典目錄 `/lang/` 直接存取 → 403。
- [ ] 個人設定「介面語言」可選自動 / 繁體中文 / English / 日本語，登入後即套用。
- [ ] 預設站台名稱 / 錄製者名稱 / 寄件人名稱未自訂時依語言顯示；英文介面儲存設定不會把英文預設值存成自訂值。
- [ ] Jitsi 會議語言可設為「跟隨介面語言」。
- [ ] Email / .ics 預設範本依寄送者語言；自訂範本原樣使用。
- [ ] `tests/check-i18n.php`：所有 `t()` 鍵在每種語言（英文、日文）都有翻譯、佔位一致、日文不含繁中專用字；程式中無未包 `t()` 的中文字串。
- [ ] 所有 `.md` 皆有英文（預設）、`_zh-TW.md` 與 `_ja.md`，檔頭語言列互相連結另外兩種語言。
- [ ] GitHub Pages：中 / 英 / 日切換、依瀏覽器語言自動判斷、`?lang=` 可指定並記住；附錄文件卡片只顯示目前語言的一個按鈕、2×2 排列、同一列等高、按鈕底部對齊。

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
- [ ] 推送 `vX.Y.Z` tag 後，**GitHub Actions（`.github/workflows/release.yml`）自動**建立該版 Docker image（驗證 image 內無機密）、打包 `jt-vc-portal-X.Y.Z-docker-amd64.tar.gz` + `.sha256`，並建立 Release（說明取自 CHANGELOG）。確認 Actions 執行成功。
- [ ] README 與 Pages 的版本號、下載連結同步更新。
- [ ] 以公開連結下載 Release 的 image 與 `.sha256`，`sha256sum -c` 驗證並 `docker load` 可啟動。

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
| 系統設定頁目錄（單張顯示、網址 #、重新整理、全部） | `tests/run-e2e.sh`（`tests/e2e/meeting.cjs`） |
| .ics 折行 / REQUEST / CANCEL / SEQUENCE、刪除會議室結算、稽核長度上限與保留清理 | `tests/unit/test_ical_audit.php` |
| GET 登出無效、改密碼 / 強制登出讓 session 失效、刪除會議室權限、稽核 | `tests/run-integration.sh`（v1.8.0 段） |
| Jibri 錄影 API 授權 / Range / 路徑穿越 | `tests/test-jibri-api.sh` |
| SSO S02–S20（驗簽、聲明、state、PKCE URL、群組、佈建、僅限 SSO、登出網址、設定） | `tests/unit/test_oidc.php` |
| SSO S02 / S05 / S08 / S10（迷你 IdP：token 交換、userinfo、fail-closed） | `tests/unit/test_oidc_flow.php` |
| SSO S01、S05–S07、S11–S21、S23–S25、S27、S28、S30（真實 Keycloak + 瀏覽器，含 OTP） | `tests/run-sso.sh`（`tests/e2e/sso.cjs`） |
| SSO S22 | `tests/zap/run-zap.sh`（啟用 SSO 掃描） |
| SSO S26 | 手動：KEYCLOAK-SETUP 第 7 節驗證指令 |
| 逐字稿 T01–T12、T32–T35、T38、T51–T53、T62（分層、webhook 簽章、權限、單場開關、排隊、提示、事件、改名、設定） | `tests/unit/test_transcripts.php` |
| 會議記錄匯出 T44–T47（PDF 結構、交叉索引、字型子集、ToUnicode、斷行；.docx / .odt 的 ZIP 與 XML、改名、語言、環境檢查） | `tests/unit/test_txexport.php` |
| 逐字稿 T13–T30、T36–T37、T39–T41、T43–T45、T48–T50、T54–T61（JTLW 模擬 + stub Jibri + 真實瀏覽器） | `tests/run-transcribe.sh`（`tests/e2e/transcribe.cjs`） |
| 逐字稿 T31 | `tests/zap/run-zap.sh` |
| 逐字稿 T42 | `tests/prod-meeting-sim.sh`（維護者，正式環境） |
| 語言選單（收合、點擊開啟、Esc） | `tests/run-e2e.sh`（`tests/e2e/meeting.cjs`） |
| SSO S29 | 維護者對正式環境執行的冒煙測試腳本（臨時帳號，結束後清除） |
| 弱點掃描 | `tests/zap/run-zap.sh` |
