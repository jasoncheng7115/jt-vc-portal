# Keycloak（OIDC シングルサインオン）× jt-vc-portal セットアップ

> **作者**：Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　プロジェクト [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)
>
> English: [KEYCLOAK-SETUP.md](KEYCLOAK-SETUP.md) · 繁體中文: [KEYCLOAK-SETUP_zh-TW.md](KEYCLOAK-SETUP_zh-TW.md)

本ガイドでは、**専用ホスト**に **Keycloak** をデプロイして jt-vc-portal の OIDC アイデンティティプロバイダー（IdP）とし、オンプレミスの **Active Directory**（Windows AD または Univention UCS / Samba AD）を **LDAPS** でフェデレーションする手順を説明します。完了後、ホストと管理者は会社アカウント＋必須のワンタイムパスワード（OTP）で portal にログインします。ゲストには影響せず、これまでどおり招待リンクから参加します。

> 対象バージョン：Keycloak **26.4**（`quay.io/keycloak/keycloak`）、PostgreSQL 17、Docker Compose v2、「シングルサインオン（SSO / OIDC）」設定カードを備えた jt-vc-portal。
> 必要なファイルはすべてリポジトリの [`keycloak/`](keycloak/) フォルダーにあります。本ガイドのホスト名・IP アドレス・DN はすべて**例**（`example.com`、`10.0.0.x`）です。ご自身の値に置き換えてください。

---

## 目次

**計画**

- [1. 概要とアーキテクチャ](#1-概要とアーキテクチャ)
- [2. 要件](#2-要件)

**インストール**

- [3. Active Directory の準備](#3-active-directory-の準備)
- [4. Docker のインストールと Keycloak のデプロイ](#4-docker-のインストールと-keycloak-のデプロイ)
- [5. ブートストラップ管理者の置き換え](#5-ブートストラップ管理者の置き換え)
- [6. realm の設定（configure-realm.sh）](#6-realm-の設定configure-realmsh)
- [7. リバースプロキシ](#7-リバースプロキシ)
- [8. jt-vc-portal との接続](#8-jt-vc-portal-との接続)

**運用**

- [9. 運用](#9-運用)
- [10. セキュリティチェックリスト](#10-セキュリティチェックリスト)
- [11. トラブルシューティング](#11-トラブルシューティング)
- [12. テスト方法](#12-テスト方法)

---

<br>
<br>
<br>
<br>
<br>
<br>

## 1. 概要とアーキテクチャ

```
                     インターネット / 利用者のブラウザー
                                   │  HTTPS 443
                                   ▼
                ┌──────────────────────────────────────┐
                │ リバースプロキシ（TLS 終端）         │
                │ sso.example.com → /realms/ と        │
                │ /resources/ のみ転送、他はすべて 404 │
                │ vc.example.com  → jt-vc-portal       │
                └───────┬──────────────────────┬───────┘
          HTTP 8080     │                      │  HTTP（portal ポート）
                        ▼                      ▼
┌────────────────────────────────┐   ┌──────────────────────────┐
│ Keycloak ホスト（VM / LXC）    │   │ jt-vc-portal ホスト      │
│ 10.0.0.20                      │   │（コンテナー jaas-auth）  │
│  keycloak :8080（プロキシのみ）│◄──┤ OIDC：discovery・token・ │
│  keycloak :8443（管理 HTTPS）  │   │ JWKS はすべて            │
│  keycloak :9000（ヘルス、      │   │ https://sso.example.com  │
│                  localhost）   │   │ 経由                     │
│  postgres（内部ネットワーク）  │   └──────────────────────────┘
└───────────────┬────────────────┘
                │ LDAPS 636（読み取り専用 bind：svc-keycloak）
                ▼
┌────────────────────────────────┐
│ AD ドメインコントローラー      │
│ dc1.example.com                │
│ グループ VC-Admins / VC-Hosts  │
└────────────────────────────────┘

管理ネットワーク（例：10.0.1.0/24）──HTTPS 8443──► Keycloak 管理コンソール（プロキシは経由しない）
```

**設計上の判断**

| 判断 | 理由 |
|---|---|
| **jt-vc-portal は AD / LDAP に直接接続しない** | portal は OIDC のみを扱います。ディレクトリの資格情報、LDAP bind、パスワード検証はすべて Keycloak 内に留まり、portal が受け取るのは署名付き ID トークンだけです。 |
| **Keycloak が LDAPS（636 番ポート）で AD を読み取り専用でフェデレーション** | パスワードは LDAP bind で AD に対して検証され、Keycloak が AD に書き込むことはありません。 |
| **Keycloak は専用ホスト（VM / LXC）で稼働**し、portal ホストには置かない | 影響範囲・パッチ適用・バックアップを分離します。ディレクトリの資格情報を持つ IdP を公開 Web アプリとホストを共有させるべきではありません。 |
| **リバースプロキシで公開するのは `/realms/` と `/resources/` のみ** | 管理コンソール（`/admin`）、メトリクス、ヘルスエンドポイントは内部ネットワークのみ（`KC_HOSTNAME_ADMIN`）。 |
| **認可コードフロー＋PKCE（S256）**、confidential クライアント | portal は `state` と `nonce` も送ります。Keycloak は PKCE と完全一致のリダイレクト URI を強制します。 |
| **Keycloak で OTP（TOTP）を必須化** | 全ユーザーが初回ログイン時に認証アプリを登録し、以後のログインでは毎回コードが必要です。 |
| **Keycloak のブルートフォース閾値 < AD のアカウントロックアウト閾値** | AD がロックする**前に** Keycloak が（一時的に）ロックするため、攻撃者が Keycloak 経由で AD アカウントをロックさせることはできません。AD にロックアウトがない場合は、Keycloak がパスワード推測に対する主な防御になります。 |
| **Keycloak から見えるのは `VC-Admins` / `VC-Hosts` のメンバーのみ**（LDAP カスタムフィルター） | それ以外の AD アカウントは Keycloak 経由で試行もロックもできません。 |

**どこで何を守るか**

| 層 | 保護 |
|---|---|
| リバースプロキシ | TLS、HSTS、ログイン POST のレート制限、管理コンソールは転送しない |
| Keycloak | AD パスワード＋必須 OTP、ブルートフォース検知、グループメンバーのみ可視、PKCE、固定リダイレクト URI、セッションのアイドル 30 分 / 最大 12 時間 |
| jt-vc-portal | ID トークンの署名（RS256/384/512 のみ）と `iss` / `aud` / `azp` / `exp` / `nonce` を検証、一度限りの `state`、グループ → ロール（許可グループ外は拒否）、（issuer、subject）でアカウントを紐付け既存のローカルアカウントとは決して統合しない、送信元 IP の fail2ban、すべて監査ログ / SIEM に記録 |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 2. 要件

### ホスト

| 項目 | 推奨 |
|---|---|
| OS | Ubuntu Server 24.04 LTS |
| CPU / メモリ | 2 vCPU / 4 GB RAM |
| ディスク | 20 GB 以上（PostgreSQL データ、イメージ、ログ） |
| 種別 | Keycloak 専用の VM または LXC コンテナー |

**Proxmox LXC**：LXC 内で Docker を動かすには `nesting` と `keyctl` 機能が必要です：

```bash
# Proxmox ノードで実行（120 は CT ID に置き換え）、その後コンテナーを再起動
pct set 120 --features nesting=1,keyctl=1
pct reboot 120
```

### DNS と TLS

- Keycloak 用の DNS 名（例：**`sso.example.com`**）をリバースプロキシに向けます。
- その名前の TLS 証明書は**リバースプロキシ上**に置きます（例：Let's Encrypt）。Keycloak 自体は内部ネットワークで平文 HTTP で動作します。
- portal ホストは `sso.example.com` を名前解決でき、HTTPS で到達でき、証明書が**公的に信頼された**ものである必要があります（portal はコンテナーの CA バンドルで証明書を検証します）。portal が同じ LAN 内にある場合は split DNS / ヘアピン NAT が必要になることがあります。

### ネットワーク

| 送信元 | 宛先 | ポート | 用途 |
|---|---|---|---|
| リバースプロキシ（例：10.0.0.10） | Keycloak 10.0.0.20 | TCP 8080 | ログインページ / OIDC |
| 管理ネットワーク（例：10.0.1.0/24） | Keycloak 10.0.0.20 | TCP 8443 | 管理コンソール（HTTPS） |
| Keycloak 10.0.0.20 | DC `dc1.example.com` | TCP 636 | LDAPS |
| jt-vc-portal | `sso.example.com`（プロキシ） | TCP 443 | Discovery、token、JWKS |

### ファイアウォールの推奨

- **8080**（HTTP）：リバースプロキシからのみ許可。
- **8443**（HTTPS、管理コンソール）：管理ネットワークからのみ許可。
- **9000**（ヘルス / 管理）：`docker-compose.yml` で `127.0.0.1` にバインド済み。絶対に公開しないこと。
- **PostgreSQL**：一切公開しない（Docker 内部ネットワークのみ）。
- Keycloak ホストの外向き通信：DC への 636 と、DNS / NTP / パッケージ更新のみ。

> **Docker は `ufw` をバイパスします**：Docker が公開したポートは `ufw` の INPUT ルールではなく `DOCKER-USER` チェーンで処理されます。ネットワークファイアウォール（例：Proxmox の VM/CT ファイアウォール）を使う、`.env` の `KC_BIND_ADDR` を内部 IP にする、または `DOCKER-USER` ルールを追加してください：
>
> ```bash
> # ルールは先頭に挿入されるため、DROP を先に、ACCEPT を後に挿入する
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

## 3. Active Directory の準備

必要なもの：

1. 2 つのセキュリティグループ：**`VC-Admins`**（portal 管理者）と **`VC-Hosts`**（portal ホスト）。
2. **読み取り専用の検索用サービスアカウント**（例：**`svc-keycloak`**）。Keycloak がユーザーとグループの検索にのみ使います。特別な権限は不要（認証済みドメインユーザーならディレクトリを読めます）。管理グループには**追加しないでください**。
3. DC の LDAPS 証明書に署名した **CA 証明書**。

> グループは `LDAP_GROUPS_DN` に設定するコンテナーの直下に置く必要があります（Windows AD の既定：`CN=Users,...`、UCS の既定：`CN=Groups,...`）。またユーザーは**直接の**メンバーである必要があります（`memberOf` フィルターはネストしたグループを辿りません）。

### (a) Windows AD（PowerShell、DC または RSAT 導入済みホストで）

```powershell
Import-Module ActiveDirectory

# グループ（-Path は LDAP_GROUPS_DN にするコンテナーに合わせる）
New-ADGroup -Name "VC-Admins" -GroupScope Global -GroupCategory Security -Path "CN=Users,DC=example,DC=com" -Description "jt-vc-portal administrators"
New-ADGroup -Name "VC-Hosts"  -GroupScope Global -GroupCategory Security -Path "CN=Users,DC=example,DC=com" -Description "jt-vc-portal hosts"

# サービスアカウント：無効状態で作成し、パスワード設定後に有効化
New-ADUser -Name "svc-keycloak" -SamAccountName "svc-keycloak" -UserPrincipalName "svc-keycloak@example.com" `
  -Path "CN=Users,DC=example,DC=com" -Description "Keycloak LDAP lookup (read-only)" -Enabled $false
Set-ADAccountPassword -Identity "svc-keycloak" -Reset -NewPassword (Read-Host -AsSecureString "svc-keycloak password")
Set-ADUser -Identity "svc-keycloak" -PasswordNeverExpires $true -CannotChangePassword $true   # 任意
Enable-ADAccount -Identity "svc-keycloak"

# メンバー
Add-ADGroupMember -Identity "VC-Admins" -Members alice
Add-ADGroupMember -Identity "VC-Hosts"  -Members bob,carol

# realm.env に必要な DN
Get-ADDomain | Select-Object DistinguishedName                 # LDAP_BASE_DN
Get-ADGroup "VC-Admins" | Select-Object DistinguishedName      # → LDAP_GROUPS_DN＝「CN=VC-Admins,」以降の部分
Get-ADUser "svc-keycloak" | Select-Object DistinguishedName    # LDAP_BIND_DN

# アカウントロックアウトポリシー
Get-ADDefaultDomainPasswordPolicy | Select-Object LockoutThreshold,LockoutDuration,LockoutObservationWindow
```

**対話型ログオンの拒否（推奨）**：ドメイン（またはコンピューター）にリンクした GPO で、`svc-keycloak` を「ローカル ログオンを拒否」と「リモート デスクトップ サービスを使ったログオンを拒否」に追加します。このアカウントに必要なのは LDAP bind だけです。

### (b) Univention UCS（Primary Directory Node で）

UCS は **2 つのディレクトリ**を動かしています：**Samba AD**（389 / 636 番ポート）と UCS の **OpenLDAP**（7389 / 7636 番ポート）。Keycloak は **636 番の Samba AD** に向けてください。本ガイドの設定（`vendor=ad`、`sAMAccountName`、`objectGUID`、`memberOf`）は AD 向けです。

```bash
BASE=$(ucr get ldap/base)

# グループ
udm groups/group create --position "cn=groups,$BASE" --set name=VC-Admins --set description="jt-vc-portal administrators"
udm groups/group create --position "cn=groups,$BASE" --set name=VC-Hosts  --set description="jt-vc-portal hosts"

# サービスアカウント：無効・ログインシェルなしで作成（初期パスワードは使い捨ての乱数）
udm users/user create --position "cn=users,$BASE" \
  --set username=svc-keycloak --set lastname=keycloak \
  --set password="$(openssl rand -base64 24)" \
  --set disabled=1 --set shell=/bin/false

# 本番のパスワードを設定して有効化
read -rsp 'svc-keycloak password: ' PW; echo
udm users/user modify --dn "uid=svc-keycloak,cn=users,$BASE" --set password="$PW" --set disabled=0
unset PW

# メンバー
udm groups/group modify --dn "cn=VC-Admins,cn=groups,$BASE" --append users="uid=alice,cn=users,$BASE"
udm groups/group modify --dn "cn=VC-Hosts,cn=groups,$BASE"  --append users="uid=bob,cn=users,$BASE"

# Samba AD 側の DN（Keycloak が 636 番で見るのはこちら）
samba-tool user show svc-keycloak | grep '^dn:'     # LDAP_BIND_DN、例：CN=svc-keycloak,CN=Users,DC=example,DC=com
samba-tool group show VC-Admins   | grep '^dn:'     # 例：CN=VC-Admins,CN=Groups,DC=example,DC=com

# アカウントロックアウトポリシー
samba-tool domain passwordsettings show
```

### アカウントロックアウト閾値

AD のロックアウト閾値（`LockoutThreshold` /「Account lockout threshold」）を確認してください。`realm.env` の **`LOCKOUT_FAILURES` はそれより小さく**する必要があります（既定 5）。

閾値が **0（ロックアウトなし）** の場合は、有効化を検討してください（例：10 回、30 分）：

```powershell
# Windows AD
Set-ADDefaultDomainPasswordPolicy -Identity example.com -LockoutThreshold 10 -LockoutDuration 00:30:00 -LockoutObservationWindow 00:30:00
```

```bash
# UCS / Samba AD
samba-tool domain passwordsettings set --account-lockout-threshold=10 --account-lockout-duration=30 --reset-account-lockout-after=30
```

AD のロックアウトを無効のままにする場合、これらのアカウントの主な防御は Keycloak のブルートフォース検知になります。

### CA 証明書のエクスポート（LDAPS 用）

Keycloak は DC の LDAPS 証明書に署名した CA を信頼する必要があり、その証明書には `LDAP_URL` で使う DC 名（例：`dc1.example.com`）が含まれている必要があります。

| ディレクトリ | CA の取得場所 |
|---|---|
| UCS | Primary Directory Node の `/etc/univention/ssl/ucsCA/CAcert.pem`（PEM 形式） |
| Windows（AD CS エンタープライズ CA） | CA 上で `certutil -ca.cert ad-ca.cer`、その後変換：`openssl x509 -inform der -in ad-ca.cer -out ad-ca.pem` |

次のセクションで `truststores/ad-ca.pem` として配置します。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 4. Docker のインストールと Keycloak のデプロイ

### `keycloak/` 内のファイル

| ファイル | 用途 |
|---|---|
| `docker-compose.yml` | PostgreSQL 17 + Keycloak（`start`、本番モード）。Keycloak は 8080（HTTP、プロキシ用）、8443（HTTPS、内部管理コンソール、証明書は `./certs`）、9000（ヘルス、`127.0.0.1` にバインド）で待ち受け。`./truststores` と `./certs` を読み取り専用でマウントし、中の PEM をすべて信頼（`KC_TRUSTSTORE_PATHS`）。データベースは `./data/postgres`。 |
| `docker-compose.bootstrap.yml` | 一時管理者の変数を追加。初回起動時のみ使用（セクション 4〜5）。 |
| `.env.example` | `.env` のテンプレート（バージョン、URL、パスワード）。 |
| `realm.env.example` | `realm.env` のテンプレート（`configure-realm.sh` のパラメーター）。 |
| `configure-realm.sh` | realm・AD フェデレーション・OIDC クライアントを作成 / 更新する冪等なスクリプト（セクション 6）。 |
| `nginx-sso.conf.example` | リバースプロキシの例（セクション 7）。 |
| `.gitignore` | `.env`、`realm.env`、`client-secret.txt`、`data/`、`certs/`、`truststores/*.pem` をバージョン管理から除外。 |

### インストール

```bash
apt update && apt install -y docker.io docker-compose-v2 git curl jq
systemctl enable --now docker

# デプロイ先ディレクトリ（root のみ）
install -d -m 700 /opt/keycloak
git clone --depth 1 https://github.com/jasoncheng7115/jt-vc-portal.git /tmp/jtvc
cp -a /tmp/jtvc/keycloak/. /opt/keycloak/
rm -rf /tmp/jtvc

# LDAPS 用 CA 証明書（Keycloak コンテナーのユーザーが読めること）
install -d -m 755 /opt/keycloak/truststores
install -m 644 ad-ca.pem /opt/keycloak/truststores/ad-ca.pem

# この CA で本ホストから LDAPS を確認（"Verify return code: 0 (ok)" になること）
openssl s_client -connect dc1.example.com:636 -CAfile /opt/keycloak/truststores/ad-ca.pem </dev/null 2>/dev/null | grep 'Verify return code'

# 内部管理コンソール（8443 番）用の HTTPS 証明書。自己署名で構いません。
# subjectAltName にはブラウザーで入力する IP / 名前を入れてください。
install -d -m 750 /opt/keycloak/certs
openssl req -x509 -newkey rsa:3072 -nodes -days 3650 -subj "/CN=keycloak admin" \
  -addext "subjectAltName=IP:10.0.0.20,DNS:keycloak1" \
  -keyout /opt/keycloak/certs/tls.key -out /opt/keycloak/certs/tls.crt
chown -R 1000:0 /opt/keycloak/certs && chmod 600 /opt/keycloak/certs/tls.key   # コンテナーは uid 1000 で動作
```

> **なぜ管理コンソールは HTTPS なのか？** Keycloak 26 の管理コンソールにはブラウザーの *secure context*（PKCE 用の Web Crypto）が必要です。素の `http://<IP>:8080` で開くと **「Something went wrong」** としか表示されません。`http://localhost` なら動きますが、IP やホスト名では動かないため、管理コンソールはこの証明書で 8443 番から提供します。自己署名証明書についてブラウザーが一度警告します（または管理用端末で `tls.crt` を信頼済みとしてインポートしてください）。

### `.env`

```bash
cd /opt/keycloak
cp .env.example .env && chmod 600 .env
openssl rand -base64 32    # → KC_DB_PASSWORD
openssl rand -base64 24    # → KC_BOOTSTRAP_ADMIN_PASSWORD
vi .env
```

| 変数 | 例 | 意味 |
|---|---|---|
| `KC_VERSION` | `26.4` | Keycloak イメージのタグ（固定すること。[アップグレード](#アップグレード)参照） |
| `KC_PUBLIC_URL` | `https://sso.example.com` | 利用者のブラウザーから見える URL（プロキシ経由）。issuer のベースになります。 |
| `KC_ADMIN_URL` | `https://10.0.0.20:8443` | 管理コンソールの URL（内部ネットワークのみ）。**必ず `https://…:8443`**（上記参照）。 |
| `KC_BIND_ADDR` | `0.0.0.0`（または `10.0.0.20`） | 8080 / 8443 番を公開するアドレス |
| `KC_DB_PASSWORD` | （乱数） | PostgreSQL のパスワード（データベース初回起動時に適用） |
| `KC_BOOTSTRAP_ADMIN_USERNAME` | `temp-admin` | 初回起動専用の一時管理者 |
| `KC_BOOTSTRAP_ADMIN_PASSWORD` | （乱数） | そのパスワード。セクション 5 で削除します。 |

### 起動

```bash
cd /opt/keycloak
docker compose -f docker-compose.yml -f docker-compose.bootstrap.yml up -d   # first start only
until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo "Keycloak ready"
docker compose ps
```

初回起動は 1～2 分かかります（データベーススキーマの作成）。ログ：`docker compose logs -f keycloak`。

管理ネットワークから管理コンソール `https://10.0.0.20:8443/admin/` にアクセスできるようになります。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 5. ブートストラップ管理者の置き換え

ブートストラップ管理者は一時的なものです。`master` realm に恒久的な管理者を作成し、`temp-admin` を削除します。

```bash
cd /opt/keycloak
KC="docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh"
read -rsp 'Bootstrap (temp-admin) password: ' BOOT; echo
read -rsp 'New permanent admin password: ' NEWPW; echo

# 1) 一時管理者でログイン
$KC config credentials --server http://localhost:8080 --realm master --user temp-admin --password "$BOOT"

# 2) 恒久的な管理者を作成（名前は任意）し、realm の admin ロールを付与
$KC create users -r master -s username=kc-admin -s enabled=true
$KC set-password -r master --username kc-admin --new-password "$NEWPW"
$KC add-roles -r master --uusername kc-admin --rolename admin

# 3) 新しい管理者でログインし直し、temp-admin を削除
$KC config credentials --server http://localhost:8080 --realm master --user kc-admin --password "$NEWPW"
TID=$($KC get users -r master -q username=temp-admin --fields id --format csv --noquotes)
$KC delete "users/$TID" -r master

# （推奨）新しい管理者が次回コンソールにログインするとき OTP 登録を必須にする
AID=$($KC get users -r master -q username=kc-admin --fields id --format csv --noquotes)
$KC update "users/$AID" -r master -s 'requiredActions=["CONFIGURE_TOTP"]'

unset BOOT NEWPW
```

次に `.env` の bootstrap 2 行を削除し、bootstrap ファイル**なし**でコンテナーを再作成します（`KC_BOOTSTRAP_ADMIN_USERNAME` が設定されたままパスワードが空だと Keycloak は起動を拒否するため、bootstrap 変数は `docker-compose.bootstrap.yml` にだけ置きます）：

```bash
sed -i '/^KC_BOOTSTRAP_ADMIN_/d' /opt/keycloak/.env
docker compose up -d   # from now on always without docker-compose.bootstrap.yml
```

恒久的な管理者の資格情報は**パスワードマネージャー**に保管してください。`https://10.0.0.20:8443/admin/` に一度ログインして確認します（OTP も登録）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 6. realm の設定（configure-realm.sh）

### `realm.env`

```bash
cd /opt/keycloak
cp realm.env.example realm.env && chmod 600 realm.env
vi realm.env
```

| 変数 | 例 | 意味 |
|---|---|---|
| `REALM` | `jtvc` | realm 名。issuer は `https://sso.example.com/realms/jtvc` になります。 |
| `PORTAL_URL` | `https://vc.example.com` | portal の公開 URL。portal のサイト URL と一致させること。リダイレクト URI＝`<PORTAL_URL>/sso-callback`、ログアウト後の戻り先＝`<PORTAL_URL>/`。 |
| `CLIENT_ID` | `jt-vc-portal` | OIDC クライアント ID |
| `KC_ISSUER_BASE` | `https://sso.example.com` | 最後に issuer を表示するためだけに使用 |
| `LDAP_URL` | `ldaps://dc1.example.com:636` | LDAPS での AD 接続先。**空欄＝ローカルユーザーのみモード**（AD なし。ユーザーと 2 つのグループは Keycloak 自身で管理） |
| `LDAP_BASE_DN` | `DC=example,DC=com` | ユーザーの検索場所（サブツリー） |
| `LDAP_BIND_DN` | `CN=svc-keycloak,CN=Users,DC=example,DC=com` | 検索用サービスアカウント（AD では `svc-keycloak@example.com` のような UPN も可） |
| `LDAP_BIND_CREDENTIAL` | （空欄） | そのパスワード。**空欄を推奨**。管理コンソールで設定すれば（後述）ファイルに残りません。 |
| `LDAP_GROUPS_DN` | `CN=Groups,DC=example,DC=com` | 2 つのグループがあるコンテナー |
| `ADMIN_GROUP` / `HOST_GROUP` | `VC-Admins` / `VC-Hosts` | グループ名（CN） |
| `LOCKOUT_FAILURES` | `5` | Keycloak のブルートフォース閾値。**AD のロックアウト閾値より小さくすること** |
| `LOCALES` / `DEFAULT_LOCALE` | `en,zh-Hant,ja` / `en` | ログイン画面・アカウント画面・管理コンソールの言語（組み込み：`en`、`zh-Hant` 繁体字中国語、`zh-Hans` 簡体字中国語、`ja` など）。ブラウザの言語で自動選択され、ログイン画面で切り替えも可能。該当がない場合は `DEFAULT_LOCALE`。master realm（管理コンソール）にも適用。 |
| `KC_ADMIN_URL` | `https://10.0.0.20:8443` | `.env` と同じ値。スクリプトはこれを **master realm の Frontend URL** に設定するため、Keycloak 管理者のログインページは常に内部の管理 URL から提供されます（プロキシが `/realms/master` を拒否する `sso.example.com` からは提供されません）。 |
| `KC_ADMIN_USER` / `KC_ADMIN_PASSWORD` | （master の管理者） | スクリプトのログイン用。実行後は空にしてください（または 2 行とも `realm.env` から削除してシェルで `export` する。`realm.env` の空の値は export した値を上書きします）。 |

任意の上書き（環境変数）：`KCADM`——kcadm を実行するコマンド（既定 `docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh`）、`KC_SERVER`——kcadm から見たサーバー URL（既定 `http://localhost:8080`）、`SECRET_OUT`——クライアントシークレットの出力先（既定 `client-secret.txt`）。

### 実行

```bash
cd /opt/keycloak
./configure-realm.sh
```

出力例：

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

クライアントシークレットは `client-secret.txt`（パーミッション 600）に書き出されます。セクション 8 で portal に貼り付けたら、パスワードマネージャーに保管し、ファイルは削除してかまいません。

**再実行しても安全**：各オブジェクトは名前で検索してその場で更新します。`LDAP_BIND_CREDENTIAL` が空なら既存の bind パスワードは変更されず、クライアントシークレットも再生成されません。

### スクリプトが設定する内容

**realm のセキュリティ設定**

| 設定 | 値 |
|---|---|
| `sslRequired` | `external`（プライベートアドレス以外は HTTPS 必須） |
| ユーザー登録 / パスワードリセット / ログイン状態の保持 / ユーザー名の編集 / メールでのログイン | すべて無効 |
| ブルートフォース検知 | 有効、一時ロックのみ：`failureFactor=LOCKOUT_FAILURES`、`waitIncrementSeconds=300`、`maxFailureWaitSeconds=1800`、`maxDeltaTimeSeconds=900`、クイックログイン検査 1 秒 → 60 秒待機 |
| イベント | ログイン / ログアウト / code-to-token / OTP イベントを有効化（90 日保持）、管理イベント有効、`jboss-logging` リスナー（stdout へ出力） |
| OTP ポリシー | TOTP、HmacSHA1、6 桁、30 秒 |
| 必須アクション **Configure OTP** | 有効かつ**既定のアクション** → 全ユーザーが初回ログインで OTP を登録 |
| セッション | SSO セッションのアイドル **30 分**、最大 **12 時間**（portal のセッションタイムアウトと同じ）、アクセストークン 5 分 |

**LDAP ユーザーフェデレーション「ad」**（`LDAP_URL` 設定時のみ）

| 設定 | 値 |
|---|---|
| ベンダー / 編集モード | Active Directory / **READ_ONLY**（Keycloak は AD に書き込まない） |
| 接続 | `LDAP_URL`（LDAPS）、StartTLS なし、**truststore SPI を常に使用**、コネクションプール、タイムアウト 5 秒 / 10 秒 |
| ユーザー名 / RDN / UUID 属性 | `sAMAccountName` / `cn` / `objectGUID` |
| ユーザー DN / 範囲 | `LDAP_BASE_DN`、サブツリー |
| **カスタムユーザーフィルター** | `(\|(memberOf=CN=VC-Admins,<GROUPS_DN>)(memberOf=CN=VC-Hosts,<GROUPS_DN>))`——Keycloak にとって存在するのは 2 つのグループのメンバーだけ |
| インポート / 同期 | 初回ログイン時にインポート、定期的な全体 / 差分同期はオフ、Kerberos オフ |
| グループマッパー **`vc-groups`** | `group-ldap-mapper`、READ_ONLY、グループ DN＝`LDAP_GROUPS_DN`、フィルター `(\|(cn=VC-Admins)(cn=VC-Hosts))`、`member`（DN）でメンバーシップ判定、メンバー属性でグループを読み込み |

ローカルユーザーのみモード（`LDAP_URL` が空）では、代わりにローカルグループ `VC-Admins` と `VC-Hosts` を作成します。管理コンソールの *Users* でユーザーを作成し、グループに参加させてください。

**OIDC クライアント `jt-vc-portal`**

| 設定 | 値 |
|---|---|
| 種別 | confidential（クライアントシークレット） |
| フロー | **標準フロー（認可コード）のみ**——implicit、direct access grants、service accounts は無効、同意画面なし、front-channel logout 無効 |
| PKCE | `pkce.code.challenge.method = S256`（必須） |
| リダイレクト URI | `<PORTAL_URL>/sso-callback` のみ（ワイルドカードなし） |
| ログアウト後のリダイレクト | `<PORTAL_URL>/` |
| Web origins | なし |
| プロトコルマッパー **`groups`** | Group Membership、クレーム `groups`、**フルパスなし**、ID トークンと userinfo に含める |

### LDAP bind パスワードの設定（空欄にした場合）

管理コンソール（`https://10.0.0.20:8443/admin/`）→ realm **jtvc** → **User federation** → **ad** → **Bind credentials** → `svc-keycloak` のパスワードを入力 → **Save** → **Test connection** と **Test authentication** をクリック。

グループメンバーだけが見えることを確認：realm **jtvc** → **Users** → メンバー（例：`alice`）を検索すると見つかり、メンバー以外は見つからないこと。（ユーザーは初回の検索 / ログイン時にインポートされ、定期同期はありません。）

---

<br>
<br>
<br>
<br>
<br>
<br>

## 7. リバースプロキシ

`keycloak/nginx-sso.conf.example` は `sso.example.com` 用の nginx server ブロックです：

- **`/realms/…` と `/resources/…` のみ** `http://10.0.0.20:8080` に転送します。それ以外（`/admin`、`/metrics`、`/health`、`/` を含む）はすべて **404** を返します。
- **`/realms/master` も 404 を返します**：master realm（Keycloak 管理者）は内部の管理 URL からのみ使用します。このルールは他の `/realms` location より前に置く必要があります。
- ログインフォームの POST（`/realms/<realm>/login-actions/authenticate`）は送信元 IP ごとに**レート制限**（`limit_req`、毎分 20 回、burst 10）され、パスワード推測に対する最初の防御線になります。zone は `http {}` コンテキストで宣言する必要があります。
- **HSTS**、`X-Content-Type-Options: nosniff`、`X-Frame-Options` は Keycloak 自身が送るため、リバースプロキシでは重ねて付加しません（ヘッダーが 2 回出てしまうため）。
- 共通スニペット `kc-proxy.conf` は `Host`、`X-Forwarded-Host`、`X-Forwarded-Proto https`、`X-Forwarded-Port 443`、`X-Forwarded-For` には**実際の送信元 IP のみ**（`$remote_addr`。CDN 経由の場合は先に `real_ip` モジュールで復元）を設定してクライアントによる偽装を防ぎ（Keycloak は `KC_PROXY_HEADERS=xforwarded` で動作）、**プロキシバッファーを拡大**します（Keycloak の応答は大きなヘッダー / Cookie を含むため、既定のバッファーでは `502 upstream sent too big header` になります）。

```bash
# リバースプロキシ上で
cp nginx-sso.conf.example /etc/nginx/sites-available/sso.example.com.conf     # 名前 / IP / 証明書パスを編集
ln -s /etc/nginx/sites-available/sso.example.com.conf /etc/nginx/sites-enabled/

# レート制限 zone（http コンテキスト）
echo 'limit_req_zone $binary_remote_addr zone=kc_login:10m rate=20r/m;' > /etc/nginx/conf.d/kc-limit.conf

# プロキシ用スニペット
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

> nginx 1.25.1 以降では、非推奨警告を避けるため `listen 443 ssl http2;` を `listen 443 ssl;` + `http2 on;` に置き換えてください。
>
> 任意の強化：`master` realm は管理コンソールでしか使わず、管理コンソールは内部からアクセスします。他の location より前に `location ^~ /realms/master/ { return 404; }` を追加すれば、master realm のログインページにインターネットから到達できなくなります。

### 確認

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://sso.example.com/admin/     # 404
curl -s -o /dev/null -w '%{http_code}\n' https://sso.example.com/           # 404
curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration | jq -r .issuer
# → https://sso.example.com/realms/jtvc（完全一致すること）
```

issuer が `http://…`、内部 IP、別のポートになっている場合は、`.env` の `KC_PUBLIC_URL` を修正して `docker compose up -d` を実行してください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 8. jt-vc-portal との接続

portal に管理者でログイン →「**システム設定**」→「**シングルサインオン（SSO / OIDC）**」カード。

| 項目 | 値 |
|---|---|
| **シングルサインオンを有効にする** | チェック |
| **リダイレクト URI（この URL を IdP に登録してください）** | 読み取り専用。`<サイト URL>/sso-callback` を表示。`configure-realm.sh` が出力したリダイレクト URI と一致している必要があります（つまり `PORTAL_URL`＝portal のサイト URL）。 |
| **Issuer** | `https://sso.example.com/realms/jtvc`（余分なパスを付けない。末尾の `/` は自動で除去） |
| **ログインボタンの表示（空欄＝既定）** | 任意。既定は「会社アカウントでログイン（SSO）」 |
| **Client ID** | `jt-vc-portal` |
| **Client secret** | `client-secret.txt` の内容。再表示されません。以後の保存で空欄のままなら保存済みのシークレットを維持します。 |
| **管理者グループ（カンマ区切り）** | `VC-Admins` |
| **ホストグループ（カンマ区切り）** | `VC-Hosts` |
| **IdP は HTTPS 必須（チェックしたままを推奨）** | チェック |
| 詳細：スコープとクレーム名 | 既定：スコープ `openid email profile`、グループクレーム `groups`、ユーザー名クレーム `preferred_username`、メールクレーム `email`、表示名クレーム `name` |

「**保存して接続テスト**」をクリックします。portal は discovery ドキュメントと署名鍵（JWKS）を取得し、成功すると「接続成功：IdP の設定と N 個の署名鍵を取得しました。」と表示します。エラー時は短い理由を表示（および監査記録）します。[トラブルシューティング](#11-トラブルシューティング)を参照してください。

グループのルール：どちらのグループにも属さないユーザーは**ログインできません**。照合は大文字小文字を区別せず、両方に属する場合は `VC-Admins` が優先されます。Keycloak のフルパス（`/A/B`）のグループはフルパスでも最後の要素でも照合できます。

### 初回ログインのテスト

1. プライベートウィンドウで portal のログインページを開き →「**会社アカウントでログイン（SSO）**」。
2. Keycloak のログインページ → `VC-Hosts` メンバーの AD ユーザー名（`sAMAccountName`）とパスワードを入力。
3. **初回ログインでは OTP 登録が強制されます**：認証アプリ（Google Authenticator、Microsoft Authenticator、FreeOTP など）で QR コードを読み取り、コードを入力。
4. portal のダッシュボードに戻ります。「**アカウント管理**」に **SSO** バッジ付きの新しいアカウントが表示され、ロールはグループから決まります。

SSO アカウントには portal のローカルパスワードもローカル 2FA もありません（OTP は Keycloak が担当）。portal からログアウトすると Keycloak のセッションも終了し（`id_token_hint` 付きの RP-initiated logout）、`<PORTAL_URL>/` に戻ります。

アカウントの紐付け：portal は SSO アカウントを（issuer、`sub`）で紐付けます。初回ログイン時に `preferred_username` をユーザー名としてアカウントを作成しますが、同じユーザー名またはメールアドレスの**ローカル**アカウントが既にある場合はログインを拒否します。自動統合は決して行いません（アカウント乗っ取り防止）。先にそのローカルアカウントの名前を変更するか削除してください。

### 任意：SSO のみ

SSO が正常に動いたら、「**SSO のみ：通常のアカウントはローカルパスワードでログインできなくなります**」をチェックできます。

- **有効なローカル管理者**（SSO ではないアカウント）が**少なくとも 1 人**いないと保存できません。Keycloak や AD が停止したときの緊急用アカウントです。
- SSO のみモードでは、「**SSO のみのとき、ローカルパスワードでのログインを許可する送信元 IP / CIDR**」（例：`10.0.1.0/24`）に**含まれない**送信元 IP にはローカルパスワードのフォームが表示されず、`POST /verify` も拒否（および監査記録）されます。カードには「**現在の送信元 IP**」が表示されるので、保存前にそれが範囲内にあることを確認してください。
- **空欄＝ローカルホストのみ**（`127.0.0.1`、`::1`）。Docker とリバースプロキシの背後では、ブラウザーがローカルホストから来ることは通常ないため、実質的に空欄は「Web からのローカルログイン不可」を意味します。Web での予備手段が必要なら管理ネットワークを明示的に指定してください。
- IP / CIDR の書式は保存時に検証されます。

**復旧用 CLI**（portal ホスト上で実行。Web からのアクセスは 404）：

```bash
docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php show               # 現在の SSO 設定を表示
docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable-sso-only   # SSO は維持し、ローカルパスワードログインを再許可
docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable            # SSO を完全に無効化
```

どちらの変更も保存済みのクライアントシークレットを維持し、監査ログ（実行者 `cli`）に記録されます。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 9. 運用

### ユーザーの追加 / 削除

アクセス権は **AD のグループメンバーシップだけ**で管理します：

- **付与**：ユーザーを `VC-Hosts` または `VC-Admins` に追加。次回ログイン時に有効になります（その時点で portal アカウントが作成されます）。
- **ロール変更**：グループ間で移動。ログインのたびにロールが再同期されます（最後の有効な portal 管理者が降格されることはありません）。
- **取り消し**：両グループから外す（または AD アカウントを無効化）。次のログイン試行から Keycloak はそのユーザーを見つけられず、portal も拒否します。
- **即時遮断**（既存セッション）：portal の「**アカウント管理**」でアカウントを無効化するか、編集して「**このアカウントをすべてのデバイスから強制ログアウト**」をチェック。必要なら Keycloak のセッションも終了：管理コンソール → realm **jtvc** → **Users** → 対象ユーザー → **Sessions** → **Sign out**。

### `svc-keycloak` のパスワード変更

**両方**で、続けて変更してください：

1. AD：`Set-ADAccountPassword -Identity svc-keycloak -Reset`（Windows）または `udm users/user modify … --set password=…`（UCS）。
2. Keycloak：realm **jtvc** → **User federation** → **ad** → **Bind credentials** → 新しいパスワード → **Save** → **Test authentication**。

手順 2 が終わるまで SSO ログインは失敗します。

### バックアップ

```bash
# 毎日（例：cron で 02:30）
install -d -m 700 /var/backups/keycloak
cd /opt/keycloak
docker compose exec -T postgres pg_dump -U keycloak -d keycloak -Fc > /var/backups/keycloak/keycloak-$(date +%F).dump
tar -C /opt -czf /var/backups/keycloak/keycloak-files-$(date +%F).tar.gz --exclude=keycloak/data keycloak
find /var/backups/keycloak -mtime +30 -delete
```

ファイルのアーカイブには `.env`、`realm.env`、`client-secret.txt`、truststore が含まれます。バックアップは暗号化 / アクセス制限し、ホスト外にもコピーしてください。

**リストア**（バックアップ時と同じ Keycloak バージョンで）：

```bash
tar -C /opt -xzf keycloak-files-YYYY-MM-DD.tar.gz        # 新しいホストの場合：/opt/keycloak を復元
cd /opt/keycloak
docker compose up -d postgres
docker compose stop keycloak
docker compose exec -T postgres psql -U keycloak -d postgres -c 'DROP DATABASE IF EXISTS keycloak;' -c 'CREATE DATABASE keycloak OWNER keycloak;'
docker compose exec -T postgres pg_restore -U keycloak -d keycloak --no-owner < keycloak-YYYY-MM-DD.dump
docker compose up -d
until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo ready
```

使い捨てのホストで少なくとも一度はリストアを試してください。

### アップグレード

1. 現在のバージョンから目標バージョンまでの各バージョンの Keycloak リリースノート / 移行ガイドを読む。
2. バックアップする（上記）。
3. `.env` の `KC_VERSION` を変更する（常に固定バージョン）。
4. 適用：

   ```bash
   cd /opt/keycloak
   docker compose pull && docker compose up -d
   until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo ready
   docker compose logs --tail 100 keycloak
   ```

5. 確認：`curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration | jq -r .issuer`、portal の「**保存して接続テスト**」を実行し、実際に SSO でログインする。
6. kcadm で設定を確認（冪等な `./configure-realm.sh` を再実行してもよい）：

   ```bash
   KC="docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh"
   $KC config credentials --server http://localhost:8080 --realm master --user kc-admin
   $KC get realms/jtvc --fields bruteForceProtected,failureFactor,sslRequired
   $KC get clients -r jtvc -q clientId=jt-vc-portal --fields clientId,redirectUris,attributes
   ```

PostgreSQL：イメージはメジャーバージョン 17 に固定され、マイナー更新は `docker compose pull` で入ります。PostgreSQL の**メジャー**アップグレードには dump / restore が必要です。タグを変えるだけにしないでください。

### ログ転送

- コンテナーログ：`docker compose logs keycloak`（Docker 既定の `json-file` ドライバー、`/var/lib/docker/containers/` 配下）。
- Keycloak のイベント（ログイン、ログインエラー、ログアウト、OTP 登録…）は **`jboss-logging`** リスナー経由で stdout に出力されます。既定では**エラー**イベントは WARN、**成功**イベントは DEBUG で記録されます。同梱の `docker-compose.yml` では `KC_SPI_EVENTS_LISTENER__JBOSS_LOGGING__SUCCESS_LEVEL: info`（Keycloak 26 のオプション構文でアンダースコアは 2 つ。26.4 で確認済み）を設定済みのため、成功したログインは INFO で記録されます。
- ホストのログエージェントで SIEM に送ります。例：Docker の JSON ログファイルを読む **Wazuh agent**、または Docker のログドライバーを `journald` / `syslog` に変更。
- jt-vc-portal 自身の `sso_login` / `sso_fail` 監査イベントは、portal で設定した SIEM に既に送信されます。

### 監視

```bash
curl -sf http://127.0.0.1:9000/health/ready && echo OK     # Keycloak ホスト上でのみ実行可能
```

ホスト上の監視エージェントから確認してください（9000 番はローカルホストにのみバインド）。外部からは `https://sso.example.com/realms/jtvc/.well-known/openid-configuration`（HTTP 200）と TLS 証明書の有効期限を監視します。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 10. セキュリティチェックリスト

- [ ] 管理コンソールに**インターネットから到達できない**（`https://sso.example.com/admin/` と `https://sso.example.com/realms/master/` → 404）。8080 番はプロキシからのみ、8443 番（管理、HTTPS）は管理ネットワークからのみ、9000 番はローカルホストのみ。
- [ ] **ブートストラップ管理者を削除**し、`.env` から `KC_BOOTSTRAP_ADMIN_*` の 2 行を削除し、`docker-compose.bootstrap.yml` なしで稼働、パスワードマネージャーに保管。
- [ ] **OTP 必須**（必須アクション *Configure OTP* が有効かつ既定）。
- [ ] Keycloak の**ブルートフォース閾値（`LOCKOUT_FAILURES`）< AD のロックアウト閾値**。可能なら AD のロックアウトを有効化済み。
- [ ] LDAP フェデレーションは **READ_ONLY**、**LDAPS** で CA を検証（truststore）、**ユーザーフィルターは `VC-Admins` / `VC-Hosts` に限定**。
- [ ] `svc-keycloak` は**読み取り専用**（管理グループに属さない）、**対話型ログオン拒否**、強力なパスワード。
- [ ] クライアントは **confidential**、標準フローのみ、**PKCE S256** 必須、**リダイレクト URI 完全一致**（`<PORTAL_URL>/sso-callback`）。
- [ ] **TLS はプロキシでのみ終端し HSTS を有効化**。Keycloak の HTTP ポートは決して公開しない。
- [ ] `.env`、`realm.env`、`client-secret.txt` は **chmod 600**、`/opt/keycloak` は 700。`realm.env` から `KC_ADMIN_PASSWORD` / `LDAP_BIND_CREDENTIAL` を消去済み。
- [ ] portal：「**IdP は HTTPS 必須**」をチェック。「**SSO のみ**」を有効にする場合はローカルの緊急用管理者を最低 1 人残し、許可 CIDR はできるだけ狭くする。
- [ ] ホストは自動でパッチ適用（`apt install unattended-upgrades`）、Keycloak のバージョンは固定し定期的に更新。
- [ ] **バックアップ**を毎日取得してホスト外に保管し、**リストアを実際にテスト済み**。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 11. トラブルシューティング

| 症状 | 原因 / 対処 |
|---|---|
| portal：**「Discovery issuer mismatch」** | Keycloak が返す issuer と Issuer 欄が異なります。`curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration \| jq -r .issuer` の出力をそのまま貼り付けてください（`/.well-known/…` などのパスを付けない、realm 名の大文字小文字も一致させる）。Keycloak 自体が `http://`、内部 IP、ポート番号を返す場合は、`KC_PUBLIC_URL` とプロキシの `X-Forwarded-*` ヘッダーを修正して `docker compose up -d`。 |
| portal：**「IdP endpoint must use https」** | issuer（または discovery 内のエンドポイント）が `http://` です。プロキシ経由の HTTPS URL を使ってください。「IdP は HTTPS 必須」のチェックを外すのは隔離されたテスト環境だけにしてください。 |
| portal：**「IdP connection failed (curl …)」** | portal ホストが `sso.example.com:443` を名前解決 / 到達できない、または証明書が公的に信頼されていません。portal ホストで確認：`docker exec jaas-auth curl -sI https://sso.example.com/realms/jtvc`。 |
| Keycloak のログに **LDAPS 証明書エラー**（`PKIX path building failed`、`SSLHandshakeException`、ホスト名不一致） | `truststores/` に CA がない（PEM 形式・読み取り可能・パーミッション 644 であること）、または DC の証明書に `LDAP_URL` の名前が含まれていません。セクション 4 の `openssl s_client` で確認し、`docker compose restart keycloak`。 |
| **ユーザーが見つからない**（ログインでユーザー名またはパスワードが無効と表示、*Test authentication* は成功） | ユーザーがグループの**直接**メンバーでない、またはカスタムフィルターが一致しません。`LDAP_GROUPS_DN` がグループのコンテナーそのものか、グループの CN が一致するか（`samba-tool group show` / `Get-ADGroup`）を確認し、`./configure-realm.sh` を再実行。 |
| **トークンにグループがない** → portal の監査：「user not in any allowed group」 | LDAP グループマッパー `vc-groups` またはクライアントの `groups` プロトコルマッパーがない / 誤り（スクリプトを再実行）。portal の「**管理者グループ**」/「**ホストグループ**」の名前と、グループクレームが `groups` であることを確認。どちらのグループにも属さないユーザーは仕様どおり拒否されます。 |
| **OTP コードが拒否される** | 時刻のずれ：Keycloak ホスト（`timedatectl`、chrony / systemd-timesyncd）とスマートフォンの時刻を同期。同じコードは**再利用できません**。30 秒後の次のコードを待ってください。 |
| portal の監査：**「username or email conflicts with an existing account」** | 同じユーザー名またはメールアドレスの**ローカル** portal アカウントがあります。そのローカルアカウントの名前を変更するか削除してから（SSO アカウントはローカルアカウントと決して統合されません）再度ログイン。 |
| portal：**「account disabled」** | portal のアカウントが「アカウント管理」で無効化されています。そこで有効化してください。 |
| **全員がログインできない**（Keycloak または AD が停止、SSO のみが有効） | portal ホストで `docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable-sso-only` を実行し、ローカルの緊急用管理者でログイン。 |
| パスワード誤りの後に **Keycloak アカウントがロックされた** | ブルートフォース検知：待つ（5 分から最大 30 分）か、管理コンソール → **Users** → 対象ユーザー → *Temporarily locked* をオフ。 |
| プロキシで `502 Bad Gateway` / `upstream sent too big header` | プロキシバッファー設定（`kc-proxy.conf`）がありません。 |
| 管理コンソールに **「Something went wrong」** と表示される | 素の HTTP で開いています（例：`http://10.0.0.20:8080/admin/`）。Keycloak 26 には secure context が必要です。`https://10.0.0.20:8443/admin/` を使ってください（セクション 4、証明書は `certs/`）。 |
| 管理コンソールが**ずっと読み込み中** / ブラウザーのコンソールに `sso.example.com/realms/master/…` へのリクエスト失敗が出る | master realm のログインページがまだ公開 URL を使っています（まだ名前解決できない、またはプロキシに拒否される）。`realm.env` に `KC_ADMIN_URL` を設定して `./configure-realm.sh` を再実行してください（master realm の Frontend URL を設定します）。 |
| 管理コンソールがループする / 「HTTPS required」と表示される | **プライベート**アドレスから `KC_ADMIN_URL` 経由でアクセスしてください（master realm は `sslRequired=external`）。 |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 12. テスト方法

- **`tests/run-sso.sh`**——本番システムには一切触れないエンドツーエンドの統合テストです。使い捨ての Keycloak を起動し、**同じ `configure-realm.sh`** をローカルユーザーモード（`LDAP_URL` 空、`KCADM` / `KC_SERVER` / `SECRET_OUT` の上書きを使用）で実行して設定し、`VC-Admins`・`VC-Hosts`・どのグループにも属さないテストユーザーを作成、使い捨ての portal をビルドして起動し、Playwright で **52 項目のブラウザー / 統合チェック**を実行します。内容：初回ログインでの OTP 登録と TOTP ログイン、グループ → ロール、許可グループ外ユーザーの拒否、認可リクエストの PKCE S256 / `state` / `nonce`、固定リダイレクト URI、コールバック再送の拒否、ローカルアカウントとのユーザー名衝突、無効化アカウント（既存セッションの即時失効）、SSO のみモードの IP 許可リストとローカル管理者保護、保存して接続テスト、fail2ban ロック、Keycloak のブルートフォースロック、PKCE なしや未登録リダイレクト URI のリクエストを Keycloak が拒否すること、RP-initiated logout、`sso-cli.php` コマンド、スクリプトの再実行（冪等性）、`KC_ADMIN_URL` が公開 issuer を変えずに master realm の Frontend URL を設定すること、ログイン画面がブラウザの言語（繁体字中国語 / 日本語 / 英語、管理者ログインを含む）に従うこと。
- **`tests/unit/test_oidc.php`**——`lib/oidc.php` の単体テスト。テスト内で RSA 鍵を生成し ID トークンを偽造して検証します：署名とアルゴリズムのホワイトリスト（`none` / `HS*` を拒否）、`iss` / `aud` / `azp` / `exp` / `iat` / `nonce` / `sub` の検査、JWK → PEM 変換、グループ → ロール、アカウント作成と衝突ルール、IdP エンドポイントの HTTPS / ホスト制限。

リリースのたびに、他のテスト（`tests/run-unit.sh`、`tests/run-sso.sh`）とあわせて実行してください。
