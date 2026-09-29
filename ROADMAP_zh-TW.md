# jt-vc-portal 開發藍圖（Roadmap）

> English: [ROADMAP.md](ROADMAP.md) · 日本語: [ROADMAP_ja.md](ROADMAP_ja.md)

> **作者**：Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　專案 [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

本文記錄 jt-vc-portal 後續規劃中的功能。已上線功能請見 [README_zh-TW.md](README_zh-TW.md)；本藍圖僅列「尚未開始或進行中」的項目，順序不代表優先級，實際排程視需求調整。

> 目前穩定版本：**v1.10.0**。

---

## 規劃中功能

### 一、企業帳號（AD / LDAP）——v1.10.0 以單一登入完成

以 **OIDC 單一登入**實作：Keycloak（獨立主機）透過 LDAPS 聯合 Active Directory，jt-vc-portal 以 OIDC 信任 Keycloak，詳見 [KEYCLOAK-SETUP_zh-TW.md](KEYCLOAK-SETUP_zh-TW.md)。AD 群組對應管理員 / 主持人角色、首次登入自動建立帳號，MFA 與防暴力破解由 Keycloak 負責。

- **portal 不會直接連 AD / LDAP**：portal 的登入頁開放給外部網路使用。如果由 portal 直接拿使用者輸入的帳密去 AD 驗證，任何人都能透過這個登入頁對公司的 AD 帳號猜密碼；故意輸錯幾次，還會觸發 AD 的帳號鎖定，讓員工連電腦、信箱等其他系統都登不進去。所以改由 Keycloak 這類身分提供者（IdP）負責驗證，在那一層做 MFA 與暴力破解防護（門檻設得比 AD 鎖定低），portal 本身完全不經手 AD 密碼。

### 二、整合 jt-live-whisper 會議語音轉錄——v1.12.0 完成逐字稿與摘要

串接 [jt-live-whisper](https://github.com/jasoncheng7115/jt-live-whisper)，為會議提供語音轉文字與後續加值內容。

**v1.12.0 已完成**：錄影完成後由 jt-live-whisper 產生逐字稿（含發言者）與會議摘要，並與錄影對應存放；提供依帳號設定的權限、單場開關與管理員產生，詳見 README「會議逐字稿與摘要」一節。仍在規劃：

- **即時語音轉錄**：會議進行中將語音轉為文字（字幕／即時逐字稿）。
- 第二種後端：改將錄影交給 Jason Tools Doc Tools（jtdt），不直接呼叫 JTLW（由管理員選擇）。

### 三、portal 介面更多語言

v1.7.0 已完成繁體中文 / English，v1.9.0 加入日本語（語系資源、瀏覽器自動判斷、個人語言偏好）。後續：

- 加入更多介面語言（例如简体中文、한국어）：新增 `lang/<code>/*.php` 字典並擴充 `I18n::SUPPORTED`。
- 站台層級的預設語言設定（瀏覽器語言無法判斷時使用）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 已完成（節錄）

下列功能已於 v1.4–v1.10 上線，詳見 README、CHANGELOG 與各設定 SOP：

- **v1.10.0**：OIDC 單一登入（Keycloak + AD）、僅限單一登入模式與緊急用本地管理員、Keycloak 部署 SOP 與腳本。
- **v1.9.0**：日文介面與文件（portal 介面、所有 Markdown 文件 `_ja.md`、GitHub Pages），Pages 文件卡片版面整理。
- **v1.8.0**：刪除 / 取消會議室並寄行事曆取消通知、改密碼後其他裝置全部登出、稽核記錄保留期限、授權改為 GPL-3.0。
- **v1.7.0**：portal 介面中英雙語（依瀏覽器自動判斷、右上選單 / 個人設定切換、Jitsi 會議語言可跟隨介面、Email 預設範本依語言）。
- **v1.6.3**：資安強化（原子寫入 + 鎖、主持人心跳擁有權 + CSRF、帳號層鎖定、nonce CSP、內附 Jitsi API + SRI 等）、全功能測試清單與 ZAP 發版閘門。

- 自建 Jibri 錄影調閱（線上播放／下載／刪除、主機容量、保留政策、主持人可調閱自己場次）。
- 會議參與者統計（尖峰同時人數＋進出時間軸）、會議時長排行 Top 25。
- 登入頁路徑偽裝、稽核記錄 CSV 匯出、錯誤頁套用站台主題。
- 60 種 Jitsi 介面語言、會議室視訊省頻寬開關、自訂確認對話框。

---

> 歡迎以 GitHub Issue 回饋需求或提出新的功能建議。
