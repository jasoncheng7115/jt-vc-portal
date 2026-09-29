# Keycloak（OIDC 單一登入）× jt-vc-portal 設定

> **作者**：Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　專案 [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)
>
> English: [KEYCLOAK-SETUP.md](KEYCLOAK-SETUP.md) · 日本語: [KEYCLOAK-SETUP_ja.md](KEYCLOAK-SETUP_ja.md)

本文說明如何在**獨立主機**上部署 **Keycloak**，作為 jt-vc-portal 的 OIDC 身分驗證服務（IdP），並透過 **LDAPS** 聯合地端 **Active Directory**（Windows AD 或 Univention UCS / Samba AD）。部署完成後，主持人與管理員用公司帳號加上強制的一次性密碼（OTP）登入 portal；來賓不受影響，仍經由邀請連結加入。

> 適用版本：Keycloak **26.4**（`quay.io/keycloak/keycloak`）、PostgreSQL 17、Docker Compose v2、具備「單一登入（SSO / OIDC）」設定卡片的 jt-vc-portal。
> 所有現成檔案都在 repo 的 [`keycloak/`](keycloak/) 資料夾。本文所有主機名稱、IP 與 DN 皆為**範例**（`example.com`、`10.0.0.x`），請換成您自己的值。

---

## 目錄

**規劃**

- [一、概觀與架構](#一概觀與架構)
- [二、需求](#二需求)

**安裝步驟**

- [三、準備 Active Directory](#三準備-active-directory)
- [四、安裝 Docker 並部署 Keycloak](#四安裝-docker-並部署-keycloak)
- [五、替換初始（bootstrap）管理員](#五替換初始bootstrap管理員)
- [六、設定 realm（configure-realm.sh）](#六設定-realmconfigure-realmsh)
- [七、反向代理](#七反向代理)
- [八、串接 jt-vc-portal](#八串接-jt-vc-portal)

**維運**

- [九、日常維運](#九日常維運)
- [十、資安檢查清單](#十資安檢查清單)
- [十一、疑難排解](#十一疑難排解)
- [十二、測試方式](#十二測試方式)

---

<br>
<br>
<br>
<br>
<br>
<br>

## 一、概觀與架構

```
                        網際網路 / 使用者瀏覽器
                                   │  HTTPS 443
                                   ▼
                ┌──────────────────────────────────────┐
                │ 反向代理（終結 TLS）                 │
                │ sso.example.com → 只轉送 /realms/ 與 │
                │ /resources/；其餘一律 404            │
                │ vc.example.com  → jt-vc-portal       │
                └───────┬──────────────────────┬───────┘
          HTTP 8080     │                      │  HTTP（portal 埠）
                        ▼                      ▼
┌────────────────────────────────┐   ┌──────────────────────────┐
│ Keycloak 主機（VM / LXC）      │   │ jt-vc-portal 主機        │
│ 10.0.0.20                      │   │（容器 jt-vc-portal）     │
│  keycloak :8080（僅限代理）    │◄──┤ OIDC：discovery、token、 │
│  keycloak :8443（管理 HTTPS）  │   │ JWKS，一律經由           │
│  keycloak :9000（健康檢查，    │   │ https://sso.example.com  │
│                  僅本機）      │   └──────────────────────────┘
│  postgres（內部網路）          │
└───────────────┬────────────────┘
                │ LDAPS 636（唯讀 bind：svc-keycloak）
                ▼
┌────────────────────────────────┐
│ AD 網域控制站                  │
│ dc1.example.com                │
│ 群組 VC-Admins / VC-Hosts      │
└────────────────────────────────┘

管理網段（例：10.0.1.0/24）──HTTPS 8443──► Keycloak 管理介面（絕不經反向代理）
```

**設計決策**

| 決策 | 理由 |
|---|---|
| **jt-vc-portal 絕不直接連 AD / LDAP** | portal 只講 OIDC。目錄帳密、LDAP bind、密碼驗證全部留在 Keycloak 內；portal 只收到經簽章的 ID token。 |
| **Keycloak 以 LDAPS（636 埠）唯讀聯合 AD** | 密碼由 LDAP bind 向 AD 驗證；Keycloak 絕不寫入 AD。 |
| **Keycloak 跑在自己的主機（VM / LXC）**，不放在 portal 主機上 | 影響範圍、更新與備份各自獨立；持有目錄帳密的 IdP 不該與對外網站共用主機。 |
| **反向代理只公開 `/realms/` 與 `/resources/`** | 管理介面（`/admin`）、metrics、健康檢查都只留在內網（`KC_HOSTNAME_ADMIN`）。 |
| **授權碼流程 + PKCE（S256）**，搭配 confidential client | portal 另帶 `state` 與 `nonce`；Keycloak 強制 PKCE 與完全相符的 redirect URI。 |
| **Keycloak 強制 OTP（TOTP）** | 每位使用者首次登入都必須綁定驗證器 App；之後每次登入都要輸入驗證碼。 |
| **Keycloak 暴力破解門檻 < AD 帳號鎖定門檻** | Keycloak 會在 AD 鎖定**之前**先（暫時）鎖住帳號，攻擊者無法透過 Keycloak 把 AD 帳號鎖死。若 AD 完全沒設鎖定，Keycloak 就是防猜密碼的主要防線。 |
| **Keycloak 只看得到 `VC-Admins` / `VC-Hosts` 成員**（LDAP 自訂篩選） | 其他任何 AD 帳號都無法透過 Keycloak 嘗試登入，也就無法被鎖定。 |

**各層防護**

| 層 | 防護 |
|---|---|
| 反向代理 | TLS、HSTS、登入 POST 限速、不轉送管理介面 |
| Keycloak | AD 密碼 + 強制 OTP、暴力破解偵測、僅群組成員可見、PKCE、固定 redirect URI、session 閒置 30 分 / 最長 12 小時 |
| jt-vc-portal | 驗證 ID token 簽章（僅允許 RS256/384/512）與 `iss` / `aud` / `azp` / `exp` / `nonce`；一次性 `state`；群組 → 角色（不在允許群組即拒絕）；以（issuer、subject）綁定帳號，絕不併入既有本地帳號；來源 IP 的 fail2ban；全部寫入稽核記錄 / SIEM |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 二、需求

### 主機

| 項目 | 建議 |
|---|---|
| 作業系統 | Ubuntu Server 24.04 LTS |
| CPU / 記憶體 | 2 vCPU / 4 GB RAM |
| 磁碟 | 20 GB 以上（PostgreSQL 資料、映像檔、日誌） |
| 類型 | VM 或 LXC 容器，專供 Keycloak 使用 |

**Proxmox LXC**：在 LXC 內跑 Docker 需啟用 `nesting` 與 `keyctl` 功能：

```bash
# 在 Proxmox 節點上執行（120 換成您的 CT ID），然後重新啟動容器
pct set 120 --features nesting=1,keyctl=1
pct reboot 120
```

### DNS 與 TLS

- 為 Keycloak 準備一個 DNS 名稱，例如 **`sso.example.com`**，指向反向代理。
- 該名稱的 TLS 憑證放在**反向代理上**（例如 Let's Encrypt）。Keycloak 本身在內網跑純 HTTP。
- portal 主機必須能解析 `sso.example.com`，並以 HTTPS 連線，且憑證須為**公開信任**（portal 以容器內的 CA bundle 驗證憑證）。若 portal 與反向代理在同一個區網，可能需要 split DNS / hairpin NAT。

### 網路

| 來源 | 目的 | 埠 | 用途 |
|---|---|---|---|
| 反向代理（例：10.0.0.10） | Keycloak 10.0.0.20 | TCP 8080 | 登入頁 / OIDC |
| 管理網段（例：10.0.1.0/24） | Keycloak 10.0.0.20 | TCP 8443 | 管理介面（HTTPS） |
| Keycloak 10.0.0.20 | 網域控制站 `dc1.example.com` | TCP 636 | LDAPS |
| jt-vc-portal | `sso.example.com`（反向代理） | TCP 443 | Discovery、token、JWKS |

### 防火牆建議

- **8080**（HTTP）：只允許反向代理。
- **8443**（HTTPS，管理介面）：只允許管理網段。
- **9000**（健康檢查 / 管理）：`docker-compose.yml` 已綁 `127.0.0.1`；絕不對外開放。
- **PostgreSQL**：完全不對外發佈（只在 Docker 內部網路）。
- Keycloak 主機對外連線：只需到網域控制站的 636，以及 DNS / NTP / 套件更新。

> **Docker 會繞過 `ufw`**：Docker 發佈的埠由 `DOCKER-USER` 鏈處理，不經 `ufw` 的 INPUT 規則。請改用網路層防火牆（例如 Proxmox 的 VM/CT 防火牆）、在 `.env` 把 `KC_BIND_ADDR` 設成內網 IP，並（或）加上 `DOCKER-USER` 規則：
>
> ```bash
> # 規則會插在最上面，所以先插 DROP，再插 ACCEPT
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8080 --ctdir ORIGINAL -j DROP
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8443 --ctdir ORIGINAL -j DROP
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8080 --ctdir ORIGINAL -s 10.0.0.10 -j ACCEPT
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8443 --ctdir ORIGINAL -s 10.0.1.0/24 -j ACCEPT
> apt install -y iptables-persistent && netfilter-persistent save
> ```

---

<br>
<br>
<br>
<br>
<br>
<br>

## 三、準備 Active Directory

需要準備：

1. 兩個安全性群組：**`VC-Admins`**（portal 管理員）與 **`VC-Hosts`**（portal 主持人）。
2. 一個**唯讀查詢用服務帳號**，例如 **`svc-keycloak`**，Keycloak 只用它查詢使用者與群組。它不需要任何特殊權限（任何已驗證的網域使用者都能讀取目錄），**不要**加入任何管理群組。
3. 簽發網域控制站 LDAPS 憑證的 **CA 憑證**。

> 群組必須直接放在您要設為 `LDAP_GROUPS_DN` 的容器內（Windows AD 預設為 `CN=Users,...`；UCS 預設為 `CN=Groups,...`），且使用者必須是**直接**成員（`memberOf` 篩選不會展開巢狀群組）。

### （a）Windows AD（PowerShell，在網域控制站或裝有 RSAT 的主機上）

```powershell
Import-Module ActiveDirectory

# 群組（-Path 改成您要當作 LDAP_GROUPS_DN 的容器）
New-ADGroup -Name "VC-Admins" -GroupScope Global -GroupCategory Security -Path "CN=Users,DC=example,DC=com" -Description "jt-vc-portal administrators"
New-ADGroup -Name "VC-Hosts"  -GroupScope Global -GroupCategory Security -Path "CN=Users,DC=example,DC=com" -Description "jt-vc-portal hosts"

# 服務帳號：先建成停用狀態，設定密碼後再啟用
New-ADUser -Name "svc-keycloak" -SamAccountName "svc-keycloak" -UserPrincipalName "svc-keycloak@example.com" `
  -Path "CN=Users,DC=example,DC=com" -Description "Keycloak LDAP lookup (read-only)" -Enabled $false
Set-ADAccountPassword -Identity "svc-keycloak" -Reset -NewPassword (Read-Host -AsSecureString "svc-keycloak password")
Set-ADUser -Identity "svc-keycloak" -PasswordNeverExpires $true -CannotChangePassword $true   # 選用
Enable-ADAccount -Identity "svc-keycloak"

# 成員
Add-ADGroupMember -Identity "VC-Admins" -Members alice
Add-ADGroupMember -Identity "VC-Hosts"  -Members bob,carol

# realm.env 需要的 DN
Get-ADDomain | Select-Object DistinguishedName                 # LDAP_BASE_DN
Get-ADGroup "VC-Admins" | Select-Object DistinguishedName      # → LDAP_GROUPS_DN＝「CN=VC-Admins,」之後的部分
Get-ADUser "svc-keycloak" | Select-Object DistinguishedName    # LDAP_BIND_DN

# 帳號鎖定原則
Get-ADDefaultDomainPasswordPolicy | Select-Object LockoutThreshold,LockoutDuration,LockoutObservationWindow
```

**禁止互動式登入（建議）**：在連結到網域（或電腦）的 GPO 中，把 `svc-keycloak` 加入「拒絕本機登入」與「拒絕透過遠端桌面服務登入」。這個帳號只需要 LDAP bind。

### （b）Univention UCS（在 Primary Directory Node 上）

UCS 同時跑**兩套目錄**：**Samba AD**（389 / 636 埠）與 UCS 自己的 **OpenLDAP**（7389 / 7636 埠）。請讓 Keycloak 連 **636 埠的 Samba AD**——本文的設定（`vendor=ad`、`sAMAccountName`、`objectGUID`、`memberOf`）都是針對 AD。

```bash
BASE=$(ucr get ldap/base)

# 群組
udm groups/group create --position "cn=groups,$BASE" --set name=VC-Admins --set description="jt-vc-portal administrators"
udm groups/group create --position "cn=groups,$BASE" --set name=VC-Hosts  --set description="jt-vc-portal hosts"

# 服務帳號：建成停用、無登入 shell（初始密碼用隨機值，之後即換掉）
udm users/user create --position "cn=users,$BASE" \
  --set username=svc-keycloak --set lastname=keycloak \
  --set password="$(openssl rand -base64 24)" \
  --set disabled=1 --set shell=/bin/false

# 設定正式密碼並啟用
read -rsp 'svc-keycloak password: ' PW; echo
udm users/user modify --dn "uid=svc-keycloak,cn=users,$BASE" --set password="$PW" --set disabled=0
unset PW

# 成員
udm groups/group modify --dn "cn=VC-Admins,cn=groups,$BASE" --append users="uid=alice,cn=users,$BASE"
udm groups/group modify --dn "cn=VC-Hosts,cn=groups,$BASE"  --append users="uid=bob,cn=users,$BASE"

# Samba AD 的 DN（Keycloak 在 636 埠看到的是這些）
samba-tool user show svc-keycloak | grep '^dn:'     # LDAP_BIND_DN，例：CN=svc-keycloak,CN=Users,DC=example,DC=com
samba-tool group show VC-Admins   | grep '^dn:'     # 例：CN=VC-Admins,CN=Groups,DC=example,DC=com

# 帳號鎖定原則
samba-tool domain passwordsettings show
```

### 帳號鎖定門檻

查看 AD 的鎖定門檻（`LockoutThreshold` / 「Account lockout threshold」）。`realm.env` 的 **`LOCKOUT_FAILURES` 必須比它小**（預設 5）。

若門檻為 **0（不鎖定）**，建議啟用，例如 10 次、30 分鐘：

```powershell
# Windows AD
Set-ADDefaultDomainPasswordPolicy -Identity example.com -LockoutThreshold 10 -LockoutDuration 00:30:00 -LockoutObservationWindow 00:30:00
```

```bash
# UCS / Samba AD
samba-tool domain passwordsettings set --account-lockout-threshold=10 --account-lockout-duration=30 --reset-account-lockout-after=30
```

若 AD 維持不鎖定，Keycloak 的暴力破解偵測就是這些帳號的主要防護。

### 匯出 CA 憑證（LDAPS 用）

Keycloak 必須信任簽發網域控制站 LDAPS 憑證的 CA，且該憑證必須包含 `LDAP_URL` 使用的主機名稱（例如 `dc1.example.com`）。

| 目錄服務 | CA 憑證位置 |
|---|---|
| UCS | Primary Directory Node 上的 `/etc/univention/ssl/ucsCA/CAcert.pem`（已是 PEM） |
| Windows（AD CS 企業 CA） | 在 CA 上：`certutil -ca.cert ad-ca.cer`，再轉檔：`openssl x509 -inform der -in ad-ca.cer -out ad-ca.pem` |

下一節會把它放成 `truststores/ad-ca.pem`。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 四、安裝 Docker 並部署 Keycloak

### `keycloak/` 內的檔案

| 檔案 | 用途 |
|---|---|
| `docker-compose.yml` | PostgreSQL 17 + Keycloak（`start`，正式模式）。Keycloak 聽 8080（HTTP，給反向代理）、8443（HTTPS，內網管理介面，憑證取自 `./certs`）與 9000（健康檢查，綁 `127.0.0.1`）。以唯讀掛載 `./truststores` 與 `./certs` 並信任其中所有 PEM（`KC_TRUSTSTORE_PATHS`）。資料庫在 `./data/postgres`。 |
| `docker-compose.bootstrap.yml` | 加上暫時管理員變數——只在第一次啟動時使用（第四、五節）。 |
| `.env.example` | `.env` 範本（版本、網址、密碼）。 |
| `realm.env.example` | `realm.env` 範本（`configure-realm.sh` 的參數）。 |
| `configure-realm.sh` | 可重複執行的腳本，建立 / 更新 realm、AD 聯合與 OIDC client（第六節）。 |
| `nginx-sso.conf.example` | 反向代理範例（第七節）。 |
| `.gitignore` | 不讓 `.env`、`realm.env`、`client-secret.txt`、`data/`、`certs/`、`truststores/*.pem` 進版控。 |

### 安裝

```bash
apt update && apt install -y docker.io docker-compose-v2 git curl jq
systemctl enable --now docker

# 部署目錄（僅 root 可存取）
install -d -m 700 /opt/keycloak
git clone --depth 1 https://github.com/jasoncheng7115/jt-vc-portal.git /tmp/jtvc
cp -a /tmp/jtvc/keycloak/. /opt/keycloak/
rm -rf /tmp/jtvc

# LDAPS 用的 CA 憑證（Keycloak 容器內的使用者要能讀）
install -d -m 755 /opt/keycloak/truststores
install -m 644 ad-ca.pem /opt/keycloak/truststores/ad-ca.pem

# 用這張 CA 從本機測 LDAPS（應出現 "Verify return code: 0 (ok)"）
openssl s_client -connect dc1.example.com:636 -CAfile /opt/keycloak/truststores/ad-ca.pem </dev/null 2>/dev/null | grep 'Verify return code'

# 內網管理介面（8443 埠）用的 HTTPS 憑證。自簽即可；
# subjectAltName 請填您會在瀏覽器輸入的 IP / 名稱。
install -d -m 750 /opt/keycloak/certs
openssl req -x509 -newkey rsa:3072 -nodes -days 3650 -subj "/CN=keycloak admin" \
  -addext "subjectAltName=IP:10.0.0.20,DNS:keycloak1" \
  -keyout /opt/keycloak/certs/tls.key -out /opt/keycloak/certs/tls.crt
chown -R 1000:0 /opt/keycloak/certs && chmod 600 /opt/keycloak/certs/tls.key   # 容器以 uid 1000 執行
```

> **為什麼管理介面要走 HTTPS？** Keycloak 26 的管理介面需要瀏覽器的 *secure context*（PKCE 要用 Web Crypto）。以純 `http://<IP>:8080` 開啟只會顯示 **「Something went wrong」**。`http://localhost` 可以，但 IP 或主機名稱不行——所以管理介面改由 8443 搭配這張憑證提供。瀏覽器會對自簽憑證警告一次（或在管理用電腦把 `tls.crt` 匯入為信任憑證）。

### `.env`

```bash
cd /opt/keycloak
cp .env.example .env && chmod 600 .env
openssl rand -base64 32    # → KC_DB_PASSWORD
openssl rand -base64 24    # → KC_BOOTSTRAP_ADMIN_PASSWORD
vi .env
```

| 變數 | 範例 | 說明 |
|---|---|---|
| `KC_VERSION` | `26.4` | Keycloak 映像檔 tag（請固定版本，見[升級](#升級)） |
| `KC_PUBLIC_URL` | `https://sso.example.com` | 使用者瀏覽器看到的網址（經反向代理），也是 issuer 的前綴 |
| `KC_ADMIN_URL` | `https://10.0.0.20:8443` | 管理介面網址，只在內網使用。**必須是 `https://…:8443`**（見上方說明）。 |
| `KC_BIND_ADDR` | `0.0.0.0`（或 `10.0.0.20`） | 8080 / 8443 埠發佈在哪個位址 |
| `KC_DB_PASSWORD` | （隨機） | PostgreSQL 密碼（資料庫首次啟動時套用） |
| `KC_BOOTSTRAP_ADMIN_USERNAME` | `temp-admin` | 只在首次啟動使用的暫時管理員 |
| `KC_BOOTSTRAP_ADMIN_PASSWORD` | （隨機） | 其密碼——於第五節清除 |

### 啟動

```bash
cd /opt/keycloak
docker compose -f docker-compose.yml -f docker-compose.bootstrap.yml up -d   # first start only
until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo "Keycloak ready"
docker compose ps
```

首次啟動需一兩分鐘（建立資料庫結構）。查看日誌：`docker compose logs -f keycloak`。

此時可從管理網段開啟管理介面 `https://10.0.0.20:8443/admin/`。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 五、替換初始（bootstrap）管理員

bootstrap 管理員只是暫時用的。請在 `master` realm 建立正式管理員，再刪掉 `temp-admin`。

```bash
cd /opt/keycloak
KC="docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh"
read -rsp 'Bootstrap (temp-admin) password: ' BOOT; echo
read -rsp 'New permanent admin password: ' NEWPW; echo

# 1) 以暫時管理員登入
$KC config credentials --server http://localhost:8080 --realm master --user temp-admin --password "$BOOT"

# 2) 建立正式管理員（名稱自訂）並給予 realm 的 admin 角色
$KC create users -r master -s username=kc-admin -s enabled=true
$KC set-password -r master --username kc-admin --new-password "$NEWPW"
$KC add-roles -r master --uusername kc-admin --rolename admin

# 3) 改以正式管理員登入，刪除 temp-admin
$KC config credentials --server http://localhost:8080 --realm master --user kc-admin --password "$NEWPW"
TID=$($KC get users -r master -q username=temp-admin --fields id --format csv --noquotes)
$KC delete "users/$TID" -r master

# （建議）正式管理員下次登入管理介面時必須設定 OTP
AID=$($KC get users -r master -q username=kc-admin --fields id --format csv --noquotes)
$KC update "users/$AID" -r master -s 'requiredActions=["CONFIGURE_TOTP"]'

unset BOOT NEWPW
```

接著把 `.env` 裡兩行 bootstrap 設定刪除，並**不帶** bootstrap 檔重建容器（`KC_BOOTSTRAP_ADMIN_USERNAME` 有值但密碼為空時 Keycloak 會拒絕啟動——所以 bootstrap 變數只放在 `docker-compose.bootstrap.yml`）：

```bash
sed -i '/^KC_BOOTSTRAP_ADMIN_/d' /opt/keycloak/.env
docker compose up -d   # from now on always without docker-compose.bootstrap.yml
```

正式管理員的帳密請存進**密碼管理工具**。到 `https://10.0.0.20:8443/admin/` 登入一次確認（並完成 OTP 綁定）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 六、設定 realm（configure-realm.sh）

### `realm.env`

```bash
cd /opt/keycloak
cp realm.env.example realm.env && chmod 600 realm.env
vi realm.env
```

| 變數 | 範例 | 說明 |
|---|---|---|
| `REALM` | `jtvc` | realm 名稱。issuer 會是 `https://sso.example.com/realms/jtvc`。 |
| `PORTAL_URL` | `https://vc.example.com` | portal 對外網址——必須與 portal 的站台網址一致。Redirect URI＝`<PORTAL_URL>/sso-callback`；登出後導回＝`<PORTAL_URL>/`。 |
| `CLIENT_ID` | `jt-vc-portal` | OIDC client ID |
| `KC_ISSUER_BASE` | `https://sso.example.com` | 只用於最後輸出 issuer 提示 |
| `LDAP_URL` | `ldaps://dc1.example.com:636` | 以 LDAPS 連 AD。**留空＝僅本地帳號模式**（不接 AD；使用者與兩個群組直接在 Keycloak 管理） |
| `LDAP_BASE_DN` | `DC=example,DC=com` | 搜尋使用者的位置（含子樹） |
| `LDAP_BIND_DN` | `CN=svc-keycloak,CN=Users,DC=example,DC=com` | 查詢用服務帳號（AD 也可用 UPN，例如 `svc-keycloak@example.com`） |
| `LDAP_BIND_CREDENTIAL` | （留空） | 其密碼。**建議留空**，改在管理介面設定（見下方），就不會存進任何檔案。 |
| `LDAP_GROUPS_DN` | `CN=Groups,DC=example,DC=com` | 兩個群組所在的容器 |
| `ADMIN_GROUP` / `HOST_GROUP` | `VC-Admins` / `VC-Hosts` | 群組名稱（CN） |
| `LOCKOUT_FAILURES` | `5` | Keycloak 暴力破解門檻——**必須小於 AD 的帳號鎖定門檻** |
| `LOCALES` / `DEFAULT_LOCALE` | `en,zh-Hant,ja` / `en` | 登入頁、帳號頁與管理介面的語言（內建 `en`、`zh-Hant` 繁中、`zh-Hans` 簡中、`ja`…）。依瀏覽器語言自動選擇，使用者也可在登入頁切換；找不到對應語言時用 `DEFAULT_LOCALE`。master realm（管理介面）一併套用。 |
| `KC_ADMIN_URL` | `https://10.0.0.20:8443` | 與 `.env` 相同。腳本會把它設為 **master realm 的 Frontend URL**，讓 Keycloak 管理員登入頁一律由內網管理網址提供（絕不經 `sso.example.com`，反向代理會拒絕 `/realms/master`）。 |
| `KC_ADMIN_USER` / `KC_ADMIN_PASSWORD` | （您的 master 管理員） | 腳本登入用。執行後請清空（或把這兩行從 `realm.env` 刪掉、改在 shell 以 `export` 提供——`realm.env` 中的空白值會覆蓋 export 的值）。 |

可選的覆寫（環境變數）：`KCADM`——執行 kcadm 的指令（預設 `docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh`）；`KC_SERVER`——kcadm 看到的伺服器網址（預設 `http://localhost:8080`）；`SECRET_OUT`——client secret 輸出位置（預設 `client-secret.txt`）。

### 執行

```bash
cd /opt/keycloak
./configure-realm.sh
```

輸出範例：

```
created realm jtvc
realm settings applied (brute force: 5 failures, OTP required)
created LDAP provider ad (…)
  NOTE: LDAP bind password not set — set it in the admin console (User federation → ad) or LDAP_BIND_CREDENTIAL
created group mapper vc-groups
created client jt-vc-portal
created groups mapper

== jt-vc-portal settings (System settings → Single sign-on) ==
Issuer        : https://sso.example.com/realms/jtvc
Client ID     : jt-vc-portal
Client secret : (printed to client-secret.txt, chmod 600)
Groups claim  : groups   Admin group: VC-Admins   Host group: VC-Hosts
Redirect URI  : https://vc.example.com/sso-callback
```

client secret 寫在 `client-secret.txt`（權限 600），第八節要貼到 portal；之後請存進密碼管理工具，檔案可刪除。

**可重複執行**：每個物件都依名稱找出後就地更新；`LDAP_BIND_CREDENTIAL` 留空時不會動到既有的 bind 密碼，client secret 也不會重新產生。

### 腳本設定了哪些東西

**realm 安全設定**

| 設定 | 值 |
|---|---|
| `sslRequired` | `external`（除私有位址外一律要求 HTTPS） |
| 自行註冊 / 重設密碼 / 記住我 / 修改帳號名稱 / 以 email 登入 | 全部關閉 |
| 暴力破解偵測 | 開啟，僅暫時鎖定：`failureFactor=LOCKOUT_FAILURES`、`waitIncrementSeconds=300`、`maxFailureWaitSeconds=1800`、`maxDeltaTimeSeconds=900`、快速登入檢查 1 秒 → 等待 60 秒 |
| 事件 | 啟用登入 / 登出 / code-to-token / OTP 事件（保留 90 天）、管理事件，`jboss-logging` listener（輸出到 stdout） |
| OTP 原則 | TOTP、HmacSHA1、6 位數、30 秒 |
| 必要動作 **Configure OTP** | 啟用並設為**預設動作**→ 每位使用者首次登入都必須綁定 OTP |
| Session | SSO session 閒置 **30 分**、最長 **12 小時**（與 portal 的 session 逾時一致）；access token 5 分 |

**LDAP 使用者聯合「ad」**（僅在設定 `LDAP_URL` 時）

| 設定 | 值 |
|---|---|
| 廠商 / 編輯模式 | Active Directory / **READ_ONLY**（Keycloak 絕不寫入 AD） |
| 連線 | `LDAP_URL`（LDAPS）、不用 StartTLS、**一律使用 truststore SPI**、連線池、逾時 5 秒 / 10 秒 |
| 帳號 / RDN / UUID 屬性 | `sAMAccountName` / `cn` / `objectGUID` |
| 使用者 DN / 範圍 | `LDAP_BASE_DN`，子樹 |
| **自訂使用者篩選** | `(\|(memberOf=CN=VC-Admins,<GROUPS_DN>)(memberOf=CN=VC-Hosts,<GROUPS_DN>))`——對 Keycloak 而言只存在這兩個群組的成員 |
| 匯入 / 同步 | 首次登入時匯入；關閉定期全量 / 異動同步；關閉 Kerberos |
| 群組對應 **`vc-groups`** | `group-ldap-mapper`、READ_ONLY、群組 DN＝`LDAP_GROUPS_DN`、篩選 `(\|(cn=VC-Admins)(cn=VC-Hosts))`、以 `member`（DN）判定成員、依成員屬性載入群組 |

僅本地帳號模式（`LDAP_URL` 留空）時，腳本改為建立本地群組 `VC-Admins` 與 `VC-Hosts`；請在管理介面的 *Users* 建立使用者並加入群組。

**OIDC client `jt-vc-portal`**

| 設定 | 值 |
|---|---|
| 類型 | confidential（client secret） |
| 流程 | **只允許標準流程（授權碼）**——關閉 implicit、direct access grants、service accounts；不顯示同意畫面；關閉 front-channel logout |
| PKCE | `pkce.code.challenge.method = S256`（強制） |
| Redirect URI | 只允許 `<PORTAL_URL>/sso-callback`（不用萬用字元） |
| 登出後導回 | `<PORTAL_URL>/` |
| Web origins | 無 |
| Protocol mapper **`groups`** | Group Membership、claim `groups`、**不含完整路徑**、放進 ID token 與 userinfo |

### 設定 LDAP bind 密碼（若先前留空）

管理介面（`https://10.0.0.20:8443/admin/`）→ realm **jtvc** → **User federation** → **ad** → **Bind credentials** → 輸入 `svc-keycloak` 的密碼 → **Save** → 按 **Test connection** 與 **Test authentication**。

確認只看得到群組成員：realm **jtvc** → **Users** → 搜尋某位成員（如 `alice`）——找得到；搜尋非成員——找不到。（使用者在首次搜尋 / 登入時匯入，不做定期同步。）

---

<br>
<br>
<br>
<br>
<br>
<br>

## 七、反向代理

`keycloak/nginx-sso.conf.example` 是 `sso.example.com` 的 nginx server 區塊：

- **只把 `/realms/…` 與 `/resources/…` 轉送**到 `http://10.0.0.20:8080`。其餘一律回 **404**，包括 `/admin`、`/metrics`、`/health` 與 `/`。
- **`/realms/master` 也回 404**：master realm（Keycloak 管理員）只從內網管理網址使用。這條規則必須放在其他 `/realms` location 之前。
- 登入表單 POST（`/realms/<realm>/login-actions/authenticate`）依來源 IP **限速**（`limit_req`，每分鐘 20 次、burst 10），作為防猜密碼的第一道防線。zone 需宣告在 `http {}` 內。
- **HSTS**、`X-Content-Type-Options: nosniff` 與 `X-Frame-Options` 由 Keycloak 自己送出，反向代理不再重複加（否則標頭會出現兩次）。
- 共用片段 `kc-proxy.conf` 設定 `Host`、`X-Forwarded-Host`、`X-Forwarded-Proto https`、`X-Forwarded-Port 443`、`X-Forwarded-For` **只帶真實來源 IP**（`$remote_addr`；經 CDN 時先用 `real_ip` 模組還原），避免用戶端偽造（Keycloak 以 `KC_PROXY_HEADERS=xforwarded` 執行），並**加大 proxy buffer**（Keycloak 的回應帶有很大的標頭 / cookie，預設 buffer 會造成 `502 upstream sent too big header`）。

```bash
# 在反向代理主機上
cp nginx-sso.conf.example /etc/nginx/sites-available/sso.example.com.conf     # 修改名稱 / IP / 憑證路徑
ln -s /etc/nginx/sites-available/sso.example.com.conf /etc/nginx/sites-enabled/

# 限速 zone（http 區塊）
echo 'limit_req_zone $binary_remote_addr zone=kc_login:10m rate=20r/m;' > /etc/nginx/conf.d/kc-limit.conf

# 代理共用片段
cat > /etc/nginx/snippets/kc-proxy.conf <<'EOF'
proxy_set_header Host              $host;
proxy_set_header X-Forwarded-Host  $host;
proxy_set_header X-Forwarded-Proto https;
proxy_set_header X-Forwarded-Port  443;
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_buffer_size 128k;
proxy_buffers 4 256k;
proxy_busy_buffers_size 256k;
EOF

nginx -t && systemctl reload nginx
```

> nginx 1.25.1 以上請把 `listen 443 ssl http2;` 改成 `listen 443 ssl;` + `http2 on;`，避免棄用警告。
>
> 選用強化：`master` realm 只有管理介面會用到，而管理介面走內網。可在其他 location 之前加上 `location ^~ /realms/master/ { return 404; }`，讓網際網路連不到 master realm 的登入頁。

### 驗證

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://sso.example.com/admin/     # 404
curl -s -o /dev/null -w '%{http_code}\n' https://sso.example.com/           # 404
curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration | jq -r .issuer
# → https://sso.example.com/realms/jtvc（必須完全相符）
```

若 issuer 顯示 `http://…`、內網 IP 或其他埠號，請修正 `.env` 的 `KC_PUBLIC_URL` 後執行 `docker compose up -d`。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 八、串接 jt-vc-portal

以管理員登入 portal →「**系統設定**」→「**單一登入（SSO / OIDC）**」卡片。

| 欄位 | 值 |
|---|---|
| **啟用單一登入** | 勾選 |
| **Redirect URI（請在 IdP 註冊這個網址）** | 唯讀，顯示 `<站台網址>/sso-callback`。必須與 `configure-realm.sh` 輸出的 Redirect URI 相同（即 `PORTAL_URL`＝portal 的站台網址）。 |
| **Issuer** | `https://sso.example.com/realms/jtvc`（不要帶其他路徑；結尾的 `/` 會自動去除） |
| **登入按鈕文字（留空用預設）** | 選填，預設「以公司帳號登入（SSO）」 |
| **Client ID** | `jt-vc-portal` |
| **Client secret** | `client-secret.txt` 的內容。之後不會再顯示；日後儲存時留空＝沿用既有 secret。 |
| **管理員群組（逗號分隔）** | `VC-Admins` |
| **主持人群組（逗號分隔）** | `VC-Hosts` |
| **IdP 必須使用 HTTPS（建議保持勾選）** | 勾選 |
| 進階：Scopes 與 claim 名稱 | 預設：scopes `openid email profile`、群組 claim `groups`、帳號名稱 claim `preferred_username`、Email claim `email`、顯示名稱 claim `name`。**使用本範本時請把顯示名稱 claim 改成 `display_name`**：`configure-realm.sh` 會對應 AD 的 `displayName`（與 `givenName`），並以 `display_name` 帶給 portal。Keycloak 的 `name` 是「名＋空格＋姓」，中文姓名「陳小明」會變成「小明 陳」（沒對應到名時甚至只剩姓） |

按「**儲存並測試連線**」。portal 會取得 discovery 文件與簽章金鑰（JWKS）；成功時顯示「連線成功：已取得 IdP 設定與 N 把簽章金鑰。」。失敗時會顯示（並記入稽核）簡短原因——見[疑難排解](#十一疑難排解)。

群組規則：不在任一群組的使用者**無法登入**。比對不分大小寫；同時屬於兩個群組時以 `VC-Admins` 為準。Keycloak 完整路徑（`/A/B`）的群組可用完整路徑或最後一段比對。

### 首次登入測試

1. 用無痕視窗開 portal 登入頁 → 「**以公司帳號登入（SSO）**」。
2. 進入 Keycloak 登入頁 → 輸入 `VC-Hosts` 成員的 AD 帳號（`sAMAccountName`）與密碼。
3. **首次登入會強制綁定 OTP**：用驗證器 App（Google Authenticator、Microsoft Authenticator、FreeOTP 等）掃描 QR code，輸入驗證碼。
4. 回到 portal 儀表板。「**帳號管理**」會出現新帳號，帶 **SSO** 徽章，角色依群組決定。

SSO 帳號在 portal 沒有本地密碼，也沒有本地 2FA（OTP 由 Keycloak 負責）。登出 portal 時也會結束 Keycloak session（以 `id_token_hint` 進行 RP-initiated logout），再導回 `<PORTAL_URL>/`。

帳號綁定：portal 以（issuer、`sub`）綁定 SSO 帳號。首次登入時以 `preferred_username` 當帳號名稱建立帳號；若已有**本地**帳號使用相同帳號名稱或 email，登入會被拒絕——絕不自動合併（防止帳號接管）。請先把該本地帳號改名或刪除。

### 選用：僅限單一登入

SSO 運作正常後，可勾選「**僅限單一登入：一般帳號不能再用本地密碼登入**」。

- 必須**至少有一位啟用中的本地管理員**（非 SSO 帳號）才能儲存——這是 Keycloak 或 AD 故障時的緊急帳號。
- 僅限單一登入模式下，來源 IP **不在**「**僅限單一登入時，仍允許本地密碼登入的來源 IP / CIDR**」清單內（例如 `10.0.1.0/24`）者，看不到本地密碼表單，直接 `POST /verify` 也會被拒絕（並記入稽核）。卡片會顯示「**您目前的來源 IP**」——儲存前請確認它在清單範圍內。
- **留空＝只允許本機**（`127.0.0.1`、`::1`）。在 Docker 與反向代理之後，瀏覽器通常不會從本機連入，所以實務上留空等於「網頁上不能本地登入」——若需要網頁備援，請明確填入管理網段。
- 儲存時會檢查 IP / CIDR 格式。

**緊急還原 CLI**（在 portal 主機上執行；網頁存取一律 404）：

```bash
docker exec -u www-data jt-vc-portal php /var/www/html/sso-cli.php show               # 顯示目前 SSO 設定
docker exec -u www-data jt-vc-portal php /var/www/html/sso-cli.php disable-sso-only   # 保留 SSO，恢復本地密碼登入
docker exec -u www-data jt-vc-portal php /var/www/html/sso-cli.php disable            # 完全停用 SSO
```

兩種變更都會保留已存的 client secret，並寫入稽核記錄（行為人 `cli`）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 九、日常維運

### 新增 / 移除使用者

權限**只透過 AD 群組成員**管理：

- **授權**：把使用者加入 `VC-Hosts` 或 `VC-Admins`。下次登入時生效（portal 帳號在那時建立）。
- **變更角色**：在兩個群組間移動。每次登入都會重新同步角色（最後一位啟用中的 portal 管理員不會被降級）。
- **撤銷**：從兩個群組移除（或停用 AD 帳號）。下一次登入起 Keycloak 就找不到此人，portal 也會拒絕。
- **立即切斷**（既有 session）：在 portal「**帳號管理**」停用該帳號，或編輯並勾選「**強制登出此帳號所有已登入的裝置**」。也可一併結束 Keycloak session：管理介面 → realm **jtvc** → **Users** → 該使用者 → **Sessions** → **Sign out**。

### 變更 `svc-keycloak` 密碼

**兩邊都要改**，而且接連完成：

1. AD：`Set-ADAccountPassword -Identity svc-keycloak -Reset`（Windows）或 `udm users/user modify … --set password=…`（UCS）。
2. Keycloak：realm **jtvc** → **User federation** → **ad** → **Bind credentials** → 新密碼 → **Save** → **Test authentication**。

第 2 步完成前，SSO 登入會失敗。

### 備份

```bash
# 每日（例如 cron 02:30）
install -d -m 700 /var/backups/keycloak
cd /opt/keycloak
docker compose exec -T postgres pg_dump -U keycloak -d keycloak -Fc > /var/backups/keycloak/keycloak-$(date +%F).dump
tar -C /opt -czf /var/backups/keycloak/keycloak-files-$(date +%F).tar.gz --exclude=keycloak/data keycloak
find /var/backups/keycloak -mtime +30 -delete
```

檔案備份內含 `.env`、`realm.env`、`client-secret.txt` 與 truststore——備份請加密 / 限制存取，並複製到主機以外的地方。

**還原**（Keycloak 版本須與備份時相同）：

```bash
tar -C /opt -xzf keycloak-files-YYYY-MM-DD.tar.gz        # 新主機：還原 /opt/keycloak
cd /opt/keycloak
docker compose up -d postgres
docker compose stop keycloak
docker compose exec -T postgres psql -U keycloak -d postgres -c 'DROP DATABASE IF EXISTS keycloak;' -c 'CREATE DATABASE keycloak OWNER keycloak;'
docker compose exec -T postgres pg_restore -U keycloak -d keycloak --no-owner < keycloak-YYYY-MM-DD.dump
docker compose up -d
until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo ready
```

請至少在拋棄式主機上實際演練一次還原。

### 升級

1. 閱讀目前版本到目標版本之間每一版的 Keycloak release notes / migration guide。
2. 備份（見上）。
3. 修改 `.env` 的 `KC_VERSION`（一律固定版本）。
4. 套用：

   ```bash
   cd /opt/keycloak
   docker compose pull && docker compose up -d
   until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo ready
   docker compose logs --tail 100 keycloak
   ```

5. 驗證：`curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration | jq -r .issuer`、在 portal 按「**儲存並測試連線**」，並實際以 SSO 登入一次。
6. 以 kcadm 檢查設定（也可重跑可重複執行的 `./configure-realm.sh`）：

   ```bash
   KC="docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh"
   $KC config credentials --server http://localhost:8080 --realm master --user kc-admin
   $KC get realms/jtvc --fields bruteForceProtected,failureFactor,sslRequired
   $KC get clients -r jtvc -q clientId=jt-vc-portal --fields clientId,redirectUris,attributes
   ```

PostgreSQL：映像檔固定在主版本 17；小版本更新隨 `docker compose pull` 取得。PostgreSQL **主版本**升級需要 dump / restore——不要直接改 tag。

### 日誌外拋

- 容器日誌：`docker compose logs keycloak`（Docker 預設的 `json-file` driver，位於 `/var/lib/docker/containers/`）。
- Keycloak 事件（登入、登入失敗、登出、OTP 綁定…）經 **`jboss-logging`** listener 輸出到 stdout。Keycloak 預設以 WARN 記錄**錯誤**事件、以 DEBUG 記錄**成功**事件（不會輸出）；附的 `docker-compose.yml` 已設定 `KC_SPI_EVENTS_LISTENER__JBOSS_LOGGING__SUCCESS_LEVEL: info`（注意是雙底線，Keycloak 26 的選項語法，已在 26.4 驗證），成功登入會以 INFO 記錄。
- 用主機上的日誌代理程式送到 SIEM，例如讀取 Docker JSON 日誌檔的 **Wazuh agent**，或把 Docker log driver 改成 `journald` / `syslog`。
- jt-vc-portal 本身的 `sso_login` / `sso_fail` 稽核事件，已會送到 portal 設定的 SIEM。

### 監控

```bash
curl -sf http://127.0.0.1:9000/health/ready && echo OK     # 只能在 Keycloak 主機上執行
```

請由主機上的監控代理程式檢查（9000 埠只綁本機）。外部則監控 `https://sso.example.com/realms/jtvc/.well-known/openid-configuration`（HTTP 200）與 TLS 憑證到期日。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 十、資安檢查清單

- [ ] 管理介面**無法從網際網路存取**（`https://sso.example.com/admin/` 與 `https://sso.example.com/realms/master/` → 404）；8080 埠只允許反向代理，8443 埠（管理介面，HTTPS）只允許管理網段；9000 埠只綁本機。
- [ ] **bootstrap 管理員已刪除**，`.env` 已移除 `KC_BOOTSTRAP_ADMIN_*` 兩行，容器不帶 `docker-compose.bootstrap.yml` 運行；正式管理員啟用 OTP，帳密存於密碼管理工具。
- [ ] **強制 OTP**（必要動作 *Configure OTP* 已啟用且為預設）。
- [ ] Keycloak **暴力破解門檻（`LOCKOUT_FAILURES`）< AD 帳號鎖定門檻**；可行的話已啟用 AD 帳號鎖定。
- [ ] LDAP 聯合為 **READ_ONLY**、走 **LDAPS** 並驗證 CA（truststore），且**使用者篩選只限 `VC-Admins` / `VC-Hosts`**。
- [ ] `svc-keycloak` 為**唯讀**（不在任何管理群組）、**禁止互動式登入**、使用強密碼。
- [ ] client 為 **confidential**、只允許標準流程、強制 **PKCE S256**、**redirect URI 完全相符**（`<PORTAL_URL>/sso-callback`）。
- [ ] **TLS 只在反向代理終結並啟用 HSTS**；Keycloak 的 HTTP 埠從不對外公開。
- [ ] `.env`、`realm.env`、`client-secret.txt` 權限 **600**，`/opt/keycloak` 為 700；`realm.env` 已清除 `KC_ADMIN_PASSWORD` / `LDAP_BIND_CREDENTIAL`。
- [ ] portal：已勾選「**IdP 必須使用 HTTPS**」；若啟用「**僅限單一登入**」，至少保留一位本地緊急管理員，允許的 CIDR 越窄越好。
- [ ] 主機自動更新（`apt install unattended-upgrades`），Keycloak 版本固定並定期更新。
- [ ] **每日備份**、存放於主機之外，且**已實際演練還原**。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 十一、疑難排解

| 症狀 | 原因 / 處理 |
|---|---|
| portal：**「Discovery issuer mismatch」** | Keycloak 回傳的 issuer 與 Issuer 欄位不同。請把 `curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration \| jq -r .issuer` 的輸出原封不動貼上（不要帶 `/.well-known/…` 等路徑，realm 名稱大小寫須一致）。若 Keycloak 自己回報 `http://`、內網 IP 或埠號，請修正 `KC_PUBLIC_URL` 與反向代理的 `X-Forwarded-*` 標頭，再 `docker compose up -d`。 |
| portal：**「IdP endpoint must use https」** | issuer（或 discovery 內的某個端點）是 `http://`。請改用經反向代理的 HTTPS 網址。取消勾選「IdP 必須使用 HTTPS」只適用於隔離的測試環境。 |
| portal：**「IdP connection failed (curl …)」** | portal 主機無法解析 / 連到 `sso.example.com:443`，或憑證不是公開信任的。在 portal 主機測試：`docker exec jt-vc-portal curl -sI https://sso.example.com/realms/jtvc`。 |
| Keycloak 日誌出現 **LDAPS 憑證錯誤**（`PKIX path building failed`、`SSLHandshakeException`、主機名稱不符） | `truststores/` 缺 CA（須為 PEM、可讀、權限 644），或網域控制站憑證不含 `LDAP_URL` 使用的名稱。用第四節的 `openssl s_client` 驗證後 `docker compose restart keycloak`。 |
| **找不到使用者**（登入顯示帳號或密碼錯誤，但 *Test authentication* 正常） | 使用者不是群組的**直接**成員，或自訂篩選對不上：確認 `LDAP_GROUPS_DN` 正是群組所在容器、群組 CN 相符（`samba-tool group show` / `Get-ADGroup`），然後重跑 `./configure-realm.sh`。 |
| **token 裡沒有群組** → portal 稽核：「user not in any allowed group」 | LDAP 群組對應 `vc-groups` 或 client 的 `groups` protocol mapper 遺失 / 錯誤（重跑腳本）。確認 portal 的「**管理員群組**」/「**主持人群組**」名稱，以及群組 claim 為 `groups`。不在任一群組的使用者依設計會被拒絕。 |
| **OTP 驗證碼被拒** | 時間偏差：校正 Keycloak 主機（`timedatectl`、chrony / systemd-timesyncd）與手機的時間。同一組驗證碼**不能重複使用**——請等 30 秒後的下一組。 |
| portal 稽核：**「username or email conflicts with an existing account」** | portal 已有相同帳號名稱或 email 的**本地**帳號。把該本地帳號改名或刪除（SSO 帳號絕不與本地帳號合併）後再登入。 |
| portal：**「account disabled」** | portal 帳號在「帳號管理」被停用——請在該處啟用。 |
| **所有人都無法登入**（Keycloak 或 AD 故障，且已啟用僅限單一登入） | 在 portal 主機執行：`docker exec -u www-data jt-vc-portal php /var/www/html/sso-cli.php disable-sso-only`，再以本地緊急管理員登入。 |
| 輸錯密碼後 **Keycloak 帳號被鎖** | 暴力破解偵測：等待（5 分鐘起，最長 30 分鐘），或在管理介面 → **Users** → 該使用者 → 關閉 *Temporarily locked*。 |
| 反向代理回 `502 Bad Gateway` / `upstream sent too big header` | 缺少 proxy buffer 設定（`kc-proxy.conf`）。 |
| 管理介面顯示 **「Something went wrong」** | 以純 HTTP 開啟了（例如 `http://10.0.0.20:8080/admin/`）。Keycloak 26 需要 secure context：請改用 `https://10.0.0.20:8443/admin/`（第四節，憑證在 `certs/`）。 |
| 管理介面**一直轉圈** / 瀏覽器主控台顯示對 `sso.example.com/realms/master/…` 的請求失敗 | master realm 的登入頁仍使用公開網址（尚無法解析，或被反向代理拒絕）。在 `realm.env` 設定 `KC_ADMIN_URL` 後重跑 `./configure-realm.sh`（它會設定 master realm 的 Frontend URL）。 |
| 管理介面一直重導或顯示「HTTPS required」 | 請從**私有**位址經 `KC_ADMIN_URL` 存取（master realm 為 `sslRequired=external`）。 |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 十二、測試方式

- **`tests/run-sso.sh`**——端對端整合測試，不碰任何正式系統。它會啟動拋棄式 Keycloak，以**同一支 `configure-realm.sh`** 在本地帳號模式（`LDAP_URL` 留空，並使用 `KCADM` / `KC_SERVER` / `SECRET_OUT` 覆寫）完成設定，建立分屬 `VC-Admins`、`VC-Hosts` 與不屬任何群組的測試使用者，建置並啟動拋棄式 portal，再以 Playwright 跑 **52 項瀏覽器 / 整合檢查**，包括：首次登入綁定 OTP 與 TOTP 登入、群組 → 角色、不在允許群組者被拒、授權請求帶 PKCE S256 / `state` / `nonce`、固定 redirect URI、重放回呼被拒、與本地帳號同名衝突、停用帳號（既有 session 立即失效）、僅限單一登入模式的 IP 允許清單與本地管理員保護、儲存並測試連線、fail2ban 鎖定、Keycloak 暴力破解鎖定、Keycloak 拒絕未帶 PKCE 或未註冊 redirect URI 的請求、RP-initiated logout、`sso-cli.php` 指令、重跑腳本（冪等），以及 `KC_ADMIN_URL` 會設定 master realm 的 Frontend URL 且不改變公開 issuer，以及登入頁依瀏覽器語言顯示繁中 / 日文 / 英文（含管理員登入頁）。
- **`tests/unit/test_oidc.php`**——`lib/oidc.php` 的單元測試，在測試內產生 RSA 金鑰並偽造 ID token：簽章與演算法白名單（拒絕 `none` / `HS*`）、`iss` / `aud` / `azp` / `exp` / `iat` / `nonce` / `sub` 檢查、JWK → PEM 轉換、群組 → 角色、帳號建立與衝突規則、IdP 端點的 HTTPS / 主機限制。

每次發版前請與其他測試（`tests/run-unit.sh`、`tests/run-sso.sh`）一起執行。
