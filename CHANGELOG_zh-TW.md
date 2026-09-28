# 變更紀錄

> English: [CHANGELOG.md](CHANGELOG.md) · 日本語: [CHANGELOG_ja.md](CHANGELOG_ja.md)

## v1.11.0 — 系統設定頁目錄；Keycloak 部署範本改進

- **系統設定頁目錄**：頁面上方列出所有設定卡片，點選即只顯示該卡片（網址變成 `/settings#id`，可加書籤或分享），「全部」顯示全部；重新整理與儲存後仍停留在同一張。依連線模式隱藏的卡片，目錄中也會隱藏。
- **Keycloak 部署範本 / SOP**：Keycloak 26 管理介面需要瀏覽器安全環境，用 `http://<IP>:8080` 開會顯示「Something went wrong」。改為 **HTTPS 8443**（自簽憑證放 `keycloak/certs/`）；`realm.env` 設 `KC_ADMIN_URL` 後，`configure-realm.sh` 會把 **master realm 的 Frontend URL** 設為內網管理網址，nginx 範例也拒絕 `/realms/master`。新增測試 S27。
- **Keycloak 介面語言**：`configure-realm.sh` 啟用多語系（`LOCALES`，預設 `en,zh-Hant,ja`；`DEFAULT_LOCALE`），套用到 portal realm 與管理介面，登入頁依瀏覽器語言顯示，語言選單顯示「繁體中文」而不是代碼 `zh-Hant`。新增測試 S28。

## v1.10.0 — OIDC 單一登入（Keycloak）

- **OIDC 單一登入**（主持人與管理員；Keycloak、Microsoft Entra ID 或任何 OIDC IdP）：授權碼流程 + PKCE S256、state 與 nonce，id_token 以 IdP 的 JWKS 驗簽（僅 RS256/384/512），檢查 iss / aud / azp / exp / nonce。設計參考同系列專案 jt-doc-tools 與 jt-ipam。
- **本系統不直接連 AD / LDAP**。地端 AD 由獨立主機上的 Keycloak 聯合；附完整部署 SOP（`KEYCLOAK-SETUP.md`，英 / 繁中 / 日）與現成腳本（`keycloak/`：docker compose、可重複執行的 `configure-realm.sh`、nginx 範例）。
- **群組 → 角色**：依 IdP 的管理員 / 主持人群組；不在任何群組者拒絕。帳號以 (issuer, sub) 綁定，絕不與同名或同 email 的本地帳號自動合併。
- **僅限單一登入模式**：本地密碼登入可限定為指定 IP 範圍的緊急用管理員；開啟前必須有啟用中的本地管理員。緊急 CLI `sso-cli.php` 可還原密碼登入。
- **登出**同時登出 IdP（帶 id_token_hint）。SSO 帳號沒有本地密碼與 portal 2FA（MFA 由 IdP 強制）。
- **Keycloak 加固（附的 realm 腳本）**：所有人強制 OTP、暴力破解偵測門檻低於 AD 鎖定門檻、只看得到兩個 portal 群組的成員、管理介面不對外。
- **測試**：24 項單元測試（偽造 / 竄改 / `alg=none` / HS256 混淆 token、聲明、state 重放、迷你 IdP 流程）、45 項真實 Keycloak 瀏覽器測試（OTP 設定與 TOTP 登入、群組對應、衝突、停用帳號、僅限 SSO、暴力破解鎖定、PKCE 與 redirect URI 強制、登出）、啟用 SSO 的 ZAP 掃描。清單項目 S01–S26。

## v1.9.0 — 日文

- **日文介面**：portal 介面支援繁體中文、English 與**日本語**（`lang/ja/*.php`，約 675 條）。瀏覽器偏好 `ja*` 時自動顯示日文；可從右上帳號選單、登入頁 / 來賓頁頂列或「個人設定 → 介面語言」切換。
- **Jitsi 會議語言**「跟隨介面語言」會讓日文使用者看到日文會議介面；Email 預設範本與預設名稱也跟著日文。
- **文件**：所有 Markdown 文件新增 `_ja.md` 日文版，各檔語言列互相連結另外兩種語言；系統內的文件連結對日文使用者開啟日文指南。
- **GitHub Pages**：中 / 英 / 日切換並依瀏覽器自動判斷；附錄文件卡片改為只顯示目前語言的一個按鈕，兩卡等高、按鈕底部對齊。
- **測試**：`check-i18n.php` 檢查每種語言（缺鍵、佔位、日文不可含繁中專用字）；單元、整合與瀏覽器 e2e 都涵蓋日文。

## v1.8.0 — 刪除會議室、強制登出、授權改為 GPL-3.0

- **授權**：本專案改以 **GNU GPL v3.0** 授權（v1.7.0 含以前的版本仍為 Apache-2.0）。
- **刪除 / 取消會議室**：儀表板可刪除（擁有者或管理員，需確認）。有受邀者且 SMTP 啟用時，寄出行事曆**取消通知**（`METHOD:CANCEL`），對方行事曆會自動移除；進行中的主持 session 會先結算。新增稽核行為 `room_delete`。
- **行事曆邀請**：每次重寄 `SEQUENCE` 遞增，行事曆會以新版取代舊版；UID 納入房間建立時間（日後同名房間不再撞到舊事件）；折行不再切斷 UTF-8 字元。
- **Session**：改密碼（本人或管理員重設）後，該帳號其他裝置全部登出；管理員也可「強制登出此帳號所有已登入的裝置」。
- **登出**改為只接受 POST + CSRF（跨站連結無法再把使用者登出）。
- **稽核記錄**：可設定保留天數（預設 365，範圍 30–3650）並自動清理；欄位長度上限，防超長輸入灌爆記錄。
- **錄影列表**每個請求只讀一次會議記錄 / 房間資料（錄影多時較快）。
- **Jibri 錄影 API**：超出範圍的 `Range` 回 416；文件建議以防火牆 / TLS 反向代理保護以 token 驗證的 HTTP 服務。
- **提醒**：自建模式未啟用 JWT 時設定頁會警示；建立會議室表單說明好猜的房名可能被猜中。README 列出 Jitsi 的已知限制。

## v1.7.0 — 中英雙語介面

- **portal 介面多語系**：所有頁面、訊息、Email 預設範本與稽核標籤皆可翻譯（`t()` / `th()`，鍵為繁中原文；英文在 `lang/en/*.php`），約 660 條字串。
- **語言判斷**：`?lang=` → 個人偏好 → cookie → 瀏覽器 `Accept-Language` → 英文。可從右上帳號選單、登入頁 / 來賓頁頂列，或「個人設定 → 介面語言」（自動 / 繁體中文 / English）切換。
- **Jitsi 會議語言**：新增預設「跟隨介面語言」（主持人與來賓各自看到自己語言的會議介面）；指定語言照舊可用。
- **Email 邀請**：預設主旨 / 內文依寄件者語言；自訂範本原樣使用。站台 / 錄製者 / 寄件人預設名稱未自訂時也依語言顯示。
- **文件連結**依介面語言指向英文或 `_zh-TW` 文件。
- **測試**：`tests/check-i18n.php` 閘門（無未翻譯字串、無缺漏鍵、佔位一致）、i18n 單元測試、中英雙語整合與瀏覽器 e2e。

## v1.6.3 — 資安強化

- **資料完整性**：所有資料檔改為原子寫入並加鎖；多位主持人心跳、建立會議室、變更設定同時發生時，不再互相洗掉或覆蓋。
- **存取控制**：主持人心跳 / 離開端點僅接受 POST + CSRF，且只有會議室擁有者（或管理員）可操作；`/start` 僅接受 POST（儀表板「進入」改為表單），跨站連結無法再把主持人標成在線。
- **錄影**：主持人不再能看到「同名會議室較早場次」的錄影。
- **認證**：除依 IP 鎖定外，新增依帳號鎖定（15 分鐘內跨 IP 累計 10 次失敗）；未登入存取 `/verify`、`/twofa`、`/twofa-verify` 回 404，`/logout` 不再洩漏偽裝後的登入路徑。
- **密鑰**：自建 JWT 共享密鑰與 SMTP 密碼不再回填到設定頁（留空＝不變更）。
- **JWT**：來賓 token 明確關閉錄影 / 直播 / 逐字稿 / 外撥；主持人 token 12 小時、來賓 6 小時（長會議可重連），並帶 `nbf`。
- **會議統計**：瀏覽器當掉不再讓下一場的時長暴增（以最後心跳結算舊 session）。
- **內容安全政策**：改為 nonce 制，script 與 style 元素不再允許 `'unsafe-inline'`；會議頁新增 CSP，iframe 只允許 Jitsi 網域。
- **供應鏈**：Jitsi IFrame API（`external_api.js`）改為內附釘版並加 SRI，不再即時從第三方網域載入（用 `tools/update-jitsi-external-api.sh` 更新）。
- **測試**：`tests/` 新增單元、整合、瀏覽器 e2e（Playwright）與 OWASP ZAP 腳本；中英雙語發版清單 `TEST_CHECKLIST.md`。
- **文件**：所有 Markdown 文件改為英文預設、另有 `_zh-TW.md` 中文版；GitHub Pages 介紹頁可中英切換並自動判斷語言。

## v1.6.2 — 資安強化

反向代理信任白名單（`JTVC_TRUSTED_PROXIES`）、Session 閒置 / 絕對逾時、管理頁 CSP、CDN 腳本 SRI、SIEM 日誌注入防護、TOTP 重放保護、不存在帳號的等時登入、`room-status` 需持邀請、密碼下限 10 字，以及 Apache `headers` 模組修正。

## v1.6.1 及更早

請見 [GitHub Releases](https://github.com/jasoncheng7115/jt-vc-portal/releases) 頁面。
