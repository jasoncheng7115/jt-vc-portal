# 變更紀錄

> English: [CHANGELOG.md](CHANGELOG.md)

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
