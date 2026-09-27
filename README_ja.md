<p align="center"><img src="docs/images/icon.svg" alt="jt-vc-portal" width="96" height="96"></p>

# jt-vc-portal v1.10.0 — 会議管理システム

> English: [README.md](README.md) · 繁體中文: [README_zh-TW.md](README_zh-TW.md)

> Jitsi Meet をベースにした会議ポータルで、[8x8 JaaS](https://jaas.8x8.vc/)[^8x8]（クラウドホスト型）と **[自前ホストの Jitsi Meet](https://github.com/jitsi/jitsi-meet)** の**デュアルモード**に対応しています。
> ホストはサインイン後に会議室を作成し、招待リンク（QR コード / `.ics` カレンダー招待付き）を発行できます。ゲストは招待リンクから参加します。
> 複数アカウント / ロール / 2FA、SIEM 転送付きの完全な監査ログ、fail2ban 方式のロックアウト、予約時間帯などのエンタープライズ機能を標準で備えています。

[^8x8]: **8x8** は 2018 年以降 Jitsi / Jitsi Meet の開発と保守を担っている企業で、**8x8 JaaS（Jitsi as a Service）**はその公式クラウドホスト型 Jitsi サービスです。

**プロジェクトページ / デモ：** <https://jasoncheng7115.github.io/jt-vc-portal/>

![License](https://img.shields.io/badge/License-GPL--3.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4.svg)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED.svg)
![OWASP](https://img.shields.io/badge/OWASP-Top_10_2025-success.svg)
![Dependencies](https://img.shields.io/badge/PHP_deps-zero-brightgreen.svg)

---

## 主な機能

- **2 つの接続モード**：8x8 JaaS（クラウドホスト型、RS256 + kid）または自前ホストの Jitsi Meet（HS256 または JWT なし）。管理画面でワンクリックで切り替えられ、コードの変更は不要です。
- **会議室の管理**：会議室の作成 / 入室 / 削除（招待者にはカレンダーのキャンセル通知が届きます）、ランダムな会議室名、最近の会議室一覧、招待のワンクリックコピー、QR コードのポップアップ。
- **予約時間帯**：開放する時間帯を設定できます。開始前はゲストにフリップ時計のカウントダウンが表示され、ホストが早めに入室すると自動的に開放されます。
- **ゲストの流れ**：ゲストは参加前に表示名の入力が必須です。開放時間外は待機 / カウントダウン / 終了のページが表示されます。
- **ロビーモード**：会議室の作成時に選択できます。ホストの入室時に自動で有効になり、ゲストはホストが 1 人ずつ許可して入室させます。
- **メール招待**：参加者のメールアドレスを入力すると、ワンクリックでカレンダーに追加できる `.ics` 添付（METHOD:REQUEST）付きの招待を送信します。
- **複数アカウント / ロール / 2FA**：管理者はすべての会議室を、ホストは自分が作成した会議室のみを参照できます。TOTP による二要素認証に対応しています。
- **シングルサインオン（OIDC）**：ホストと管理者は Keycloak / Entra ID 経由で会社アカウントでログイン可能（AD グループ → ロール、MFA は IdP 側）。ポータルは AD / LDAP に直接接続しません。IP 制限付きの緊急用管理者を残した「SSO のみ」モードに対応。構築手順：[KEYCLOAK-SETUP_ja.md](KEYCLOAK-SETUP_ja.md)。
- **監査とセキュリティ**：利用者の操作（サインイン、会議室の作成、招待、設定変更など）の完全な監査ログ + syslog / CEF / GELF によるリアルタイム転送、fail2ban 方式のログインロックアウト、CSRF 対策。OWASP Top 10:2025 に準拠しています。
- **録画の閲覧**（自前ホストの Jibri）：Jibri ホスト上の録画サービスと連携し、オンラインでの一覧 / 再生 / ダウンロード / 削除、ホストのストレージ容量の表示、保持ポリシー（経過日数 / 容量 / 残骸。既定はすべて無効）を提供します。ホストは自分が主催した会議の録画を閲覧できます。
- **多言語インターフェース**：ポータルの UI は繁體中文（繁体字中国語）、English、日本語に対応しています。ブラウザから自動判定され、アカウントメニューまたはプロフィールで利用者ごとに切り替えられます。Jitsi の会議画面の言語をインターフェース言語に合わせることもできます。
- **外観のカスタマイズ**：Jitsi UI の 60 言語、22 種類のテーマ、サイト名とロゴの変更、ログインページのパスの偽装。
- **会議の統計**：会議時間のランキング、同時参加者数のピーク、参加者の入退室タイムライン。JaaS モードでは USAGE webhook による MAU の集計も可能です。
- **外部 PHP パッケージゼロ**：コアはすべて手書きで、composer の依存関係はありません（フロントエンドは CDN から qrcodejs / flatpickr を読み込むのみ）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 動作要件

| 項目 | 最低 | 推奨 |
|---|---|---|
| PHP | 8.2 | **8.4** |
| Web サーバー | Apache + `mod_rewrite`（`AllowOverride All`） | 同左 |
| PHP 拡張 | `openssl`、`fileinfo`、`json`、`mbstring` | 同左 |
| その他 | 書き込み可能なデータディレクトリ、JaaS モードでは 8x8 の秘密鍵 | Docker 24+ |

> 自前ホストの Jitsi Meet モードでは、別途稼働中の Jitsi Meet サーバーが必要です（「接続モード」を参照）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## インストール方法 1：直接インストール（Apache + PHP）

```bash
# 1) コードを取得
git clone https://github.com/jasoncheng7115/jt-vc-portal.git
cd jt-vc-portal

# 2) Apache モジュールと .htaccess を有効化（Debian/Ubuntu の例）
a2enmod rewrite
# サイト設定が AllowOverride All で、DocumentRoot がこのフォルダを指していることを確認

# 3) .htaccess を有効化（誤って有効にならないよう、本プロジェクトでは dot.htaccess として同梱）
cp dot.htaccess .htaccess

# 4) 永続データ用ディレクトリを作成（既定は /var/jaas-data。config.php の DATA_DIR で変更可）
sudo mkdir -p /var/jaas-data
sudo chown www-data:www-data /var/jaas-data

# 5) （JaaS モードのみ）8x8 の秘密鍵を配置
sudo mkdir -p keys
sudo cp /path/to/your/private.key keys/private.key
```

Apache のサイト設定例（`/etc/apache2/sites-available/jt-vc-portal.conf`）：

```apache
<VirtualHost *:80>
    ServerName vc.example.com
    DocumentRoot /var/www/jt-vc-portal

    <Directory /var/www/jt-vc-portal>
        Options -Indexes +FollowSymLinks
        AllowOverride All          # .htaccess を有効にするために必須
        Require all granted
    </Directory>

    # サーバーのバージョンを隠す
    ServerTokens Prod
    ServerSignature Off

    ErrorLog  ${APACHE_LOG_DIR}/jt-vc-portal-error.log
    CustomLog ${APACHE_LOG_DIR}/jt-vc-portal-access.log combined
</VirtualHost>
```

サイトと必要なモジュールを有効化します。

```bash
a2enmod rewrite headers
a2ensite jt-vc-portal
systemctl reload apache2
```

> 本番環境では Let's Encrypt の証明書を使って `*:443` で運用するか、前段にリバースプロキシを置いて HTTPS を処理してください。
> リバースプロキシ経由で転送する場合は `X-Real-IP` ヘッダーを保持し（fail2ban 方式のロックアウトと監査ログが実際の送信元 IP を得るために使用します）、`JTVC_TRUSTED_PROXIES` を設定してください（後述の「公開環境へのデプロイにおけるセキュリティ上の要点」を参照）。

主な設定（`config.php`）：

- `DATA_DIR`：永続データ用ディレクトリ（既定は `/var/jaas-data`）。
- `JWT_PRIVATE_KEY_PATH`：JaaS の RS256 秘密鍵のパス（既定は `keys/private.key`、ドキュメントルートからの相対パス）。
- 初期管理者：環境変数 `JTVC_ADMIN_USERNAME` / `JTVC_ADMIN_EMAIL` / `JTVC_ADMIN_PASSWORD` で設定できます。指定しない場合は初回起動時にランダムなパスワードが生成され、`DATA_DIR/INITIAL_ADMIN_PASSWORD.txt` に書き込まれます（サインイン後に削除してください）。
- リバースプロキシの信頼：`JTVC_TRUSTED_PROXIES`（カンマ区切りの IP / CIDR）。設定すると、`X-Real-IP` / `X-Forwarded-*` はこれらの送信元から来た場合にのみ信頼され、送信元 IP の偽装による fail2ban 方式のロックアウトの回避を防ぎます。空のままにすると互換モード（ヘッダーを無条件に信頼。コンテナのポート分離との併用が必須）になります。「公開環境へのデプロイにおけるセキュリティ上の要点」を参照してください。
- セッションのタイムアウト：`JTVC_SESSION_IDLE`（無操作の秒数、既定 1800）、`JTVC_SESSION_ABSOLUTE`（絶対的な秒数、既定 43200）。

PHP のハードニング（`php.ini` または conf.d での設定を推奨）：`display_errors=Off`、`expose_php=Off`、`session.cookie_httponly=1`、`session.cookie_samesite=Lax`、`session.use_strict_mode=1`。

---

<br>
<br>
<br>
<br>
<br>
<br>

## インストール方法 2：Docker でデプロイ

```bash
git clone https://github.com/jasoncheng7115/jt-vc-portal.git
cd jt-vc-portal

# ビルド（最新のベースイメージを取得するため --pull を推奨）
docker build --pull -t jt-vc-portal .

# ホスト側で永続ディレクトリを準備（www-data の UID は既定で 33）
mkdir -p /opt/jt-vc-portal/keys /opt/jt-vc-portal/data
chown 33:33 /opt/jt-vc-portal/data
# JaaS モード：8x8 の秘密鍵を配置
cp /path/to/private.key /opt/jt-vc-portal/keys/private.key

# 起動（-p で 127.0.0.1 にバインド：ローカルのリバースプロキシからのみ到達可能にし、コンテナのポートを直接公開しない）
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="CHANGE_ME_strong_password" \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal
```

コンテナは `:58189` で待ち受けます。前段に nginx / Apache のリバースプロキシを置いて HTTPS を付けることを推奨します。

```nginx
server {
    listen 443 ssl;
    server_name your-domain.com;
    location / {
        proxy_pass http://127.0.0.1:58189/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
    # ssl_certificate / ssl_certificate_key ...
}
```

> **公開環境へのデプロイにおけるセキュリティ上の要点（必須）**
> 本システムは `X-Real-IP`（次に `X-Forwarded-For`）から実際の送信元 IP を判定し、fail2ban 方式のロックアウトと監査ログに使用します。攻撃者がこのヘッダーを偽装してロックアウトを回避するのを防ぐため、次の対策を行ってください。
> - `JTVC_TRUSTED_PROXIES`（カンマ区切りの IP / CIDR、IPv4 / IPv6 対応）を設定します。このリストにある送信元の `X-Real-IP` / `X-Forwarded-*` **のみ**が信頼され、それ以外は実際の接続 IP（`REMOTE_ADDR`）が使われます。
> - `-p 127.0.0.1:58189:58189` でコンテナのポートを localhost にバインドするか、ファイアウォールで制限し、**リバースプロキシからのみ到達できる**ようにします。
> - 少なくともどちらか一方を行い、両方行うことを推奨します。**`JTVC_TRUSTED_PROXIES` を空のままにすると互換モード（ヘッダーを無条件に信頼）**になります。ポートが分離されていれば問題ありませんが、コンテナのポートに外部から直接到達できる場合、攻撃者は送信元 IP を偽装して fail2ban 方式のロックアウトを回避し、監査ログを汚染できてしまいます。
> - Cloudflare の配下にある場合は、リバースプロキシで `CF-Connecting-IP` から `X-Real-IP` を設定してください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## インストール方法 3：GitHub Releases からビルド済みイメージを読み込む

自分でビルドしたくない場合は、[Release](https://github.com/jasoncheng7115/jt-vc-portal/releases) に添付されたパッケージ済みイメージ（`linux/amd64`）をダウンロードし、`docker load` して実行します。

```bash
# 1) Release ページからイメージとチェックサムファイルをダウンロード（最新のバージョン番号を使用）
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.10.0/jt-vc-portal-1.10.0-docker-amd64.tar.gz
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.10.0/jt-vc-portal-1.10.0-docker-amd64.tar.gz.sha256

# 2) 完全性を検証（OK と表示されるはず）
sha256sum -c jt-vc-portal-1.10.0-docker-amd64.tar.gz.sha256

# 3) イメージを読み込む（jt-vc-portal:1.10.0 と :latest のタグが作成される）
docker load < jt-vc-portal-1.10.0-docker-amd64.tar.gz

# 4) ホスト側で永続ディレクトリを準備（www-data の UID は既定で 33）
mkdir -p /opt/jt-vc-portal/keys /opt/jt-vc-portal/data
chown 33:33 /opt/jt-vc-portal/data
cp /path/to/private.key /opt/jt-vc-portal/keys/private.key   # JaaS モードのみ

# 5) 起動（パラメータは方法 2 と同じ）
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="CHANGE_ME_strong_password" \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal:latest
```

> イメージにはアプリケーション本体のみが含まれ、**鍵や設定は含まれません**。8x8 の秘密鍵は実行時に `keys/` のマウントボリュームから渡します。
> 提供するのは `linux/amd64` のみです。その他のアーキテクチャ（arm64 など）は[方法 2](#インストール方法-2docker-でデプロイ) で自分でビルドしてください。
> HTTPS のリバースプロキシの設定は方法 2 と同じです。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 更新 / アップグレード

> すべての設定とデータ（アカウント、会議室、監査ログ、使用量など）は `DATA_DIR`（直接インストール）またはマウントボリューム（Docker）に保存されるため、**更新してもデータは失われません**。JSON の構造は互換性を保ったまま自動的にアップグレードされます。それでも、更新前にデータディレクトリと `keys/` をバックアップすることを推奨します。

### 方法 1：直接インストールの更新

```bash
cd /var/www/jt-vc-portal        # インストール先のディレクトリ

# 1) バックアップ（推奨）
sudo cp -a /var/jaas-data /var/jaas-data.bak-$(date +%Y%m%d)

# 2) 最新のコードを取得
git pull

# 3) .htaccess が更新されていれば再適用
cp dot.htaccess .htaccess

# 4) 再読み込み（opcache をクリア）
sudo systemctl reload apache2
```

その後サインインし、トップバー左上（サイト名の横）のバージョン番号が更新されていることを確認してください。

### 方法 2：Docker の更新

```bash
cd /path/to/jt-vc-portal

# 1) データボリュームをバックアップ（推奨）
cp -a /opt/jt-vc-portal/data /opt/jt-vc-portal/data.bak-$(date +%Y%m%d)

# 2) 最新のコードを取得してイメージを再ビルド（--pull でベースイメージも更新）
git pull
docker build --pull -t jt-vc-portal .

# 3) コンテナを置き換える（データ / 秘密鍵はマウントボリューム上にあるため影響なし）
docker stop jt-vc-portal && docker rm jt-vc-portal
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal

# 4) 確認
docker ps --filter name=jt-vc-portal
```

> 初期管理者の環境変数（`JTVC_ADMIN_*`）はアカウントの初回作成時にのみ使われるため、更新時は省略できます。一方、`JTVC_TRUSTED_PROXIES`（および任意の `JTVC_SESSION_*`）は実行のたびに有効になるため、**`docker run` のたびに必ず指定してください**。
> バージョン番号はサインイン後、トップバー左上のサイト名の横に表示されます（クリックすると本プロジェクトの GitHub ページが開きます）。新しいバージョンになっているかの確認に使ってください。

### 方法 3：Release イメージからの更新

```bash
# 1) データボリュームをバックアップ（推奨）
cp -a /opt/jt-vc-portal/data /opt/jt-vc-portal/data.bak-$(date +%Y%m%d)

# 2) 新しいイメージをダウンロードし、検証して読み込む
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/latest/download/jt-vc-portal-<new-version>-docker-amd64.tar.gz.sha256
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/latest/download/jt-vc-portal-<new-version>-docker-amd64.tar.gz
sha256sum -c jt-vc-portal-<new-version>-docker-amd64.tar.gz.sha256
docker load < jt-vc-portal-<new-version>-docker-amd64.tar.gz

# 3) コンテナを置き換える（データ / 秘密鍵はマウントボリューム上にあるため影響なし）
docker stop jt-vc-portal && docker rm jt-vc-portal
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal:latest
```

---

<br>
<br>
<br>
<br>
<br>
<br>

## 初期設定

1. サイトを開き、`/jt-login` から初期管理者としてサインインします（パスワードは上記を参照）。
2. **システム設定**を開きます。
   - **接続モード**：8x8 JaaS か自前ホストの Jitsi Meet を選び、対応するパラメータを入力します。
   - **サイト設定**：サイト名とロゴ。
   - **ログインページのパス**（任意）：ログインの入口を秘密のパスに変更します（後述）。
   - **会議室のインターフェース**：既定の UI 言語（既定は繁体字中国語）。
   - **録画設定**（任意）：録画者の表示名、自前ホストの Jibri 録画閲覧サービスへの接続、保持ポリシー。
   - **SMTP**（任意）：`.ics` 招待メールの送信。
   - **ログイン記録の転送**（任意）：syslog / CEF / GELF。
3. **プロフィール**でパスワードを変更し、2FA を有効にします。
4. ダッシュボードに戻って会議室を作成します。

### 接続モード

| | 8x8 JaaS | 自前ホストの Jitsi Meet |
|---|---|---|
| ドメイン | `8x8.vc` | 自分の Jitsi ドメイン |
| 必要なもの | App ID、Key ID（kid）、RS256 秘密鍵 | サービスのドメイン、（任意）JWT の app_id + HS256 シークレット |
| 料金 | 無料の Dev プラン（月 25 MAU）。超過分は 8x8 のプランに従って課金 | 自前で運用 |

自前ホストの Jitsi Meet で JWT を使う場合は、prosody でトークン認証を有効にし、app_id / app_secret を本システムと一致させる必要があります。

> **自前ホストとの連携手順の全体**（公式の Docker ベースの Jitsi Meet から本システムとの連携まで）：**[JITSI-MEET-SETUP_ja.md](JITSI-MEET-SETUP_ja.md)** を参照してください。

> **重要 — モバイル端末からの参加に関する制限（JWT 使用時）**
> JWT 認証を有効にすると（**8x8 JaaS では常に必須**、自前ホストの Jitsi Meet では `ENABLE_AUTH=1` の場合）、会議室は jt-vc-portal が発行したトークンのみを受け付けます。
> **公式の Jitsi Meet モバイルアプリ（iOS / Android）からは直接参加できません**。アプリは本ポータルを経由しないためトークンを取得できず、拒否されます。
> モバイルの利用者は、招待リンクを**モバイルブラウザ**で開き、本ポータル経由で参加してください（会議は埋め込み表示されるため、使い勝手は同じです）。
> 自前ホストで「JWT なしの匿名モード」（`ENABLE_AUTH=0`）を使う場合はモバイルアプリから直接参加できますが、会議室名を知っていれば誰でも入室できるため、**安全性は低くなります**。

---

<br>
<br>
<br>
<br>
<br>
<br>

## ログインページのパスの偽装

既定のログインの入口は `/jt-login` です。**システム設定 → ログインページのパス**で、自分だけが知る秘密のパスに変更でき（英数字と `. _ -` のみ、長さ 1〜64）、自動スキャンや総当たり攻撃にさらされる機会を減らせます。

- 変更後は、元の `/jt-login` や割り当てのないパスはすべて **404** を返し、設定したパスでのみログインページが表示されます。
- 変更時に実際のパスは監査ログ / SIEM に書き込まれません（漏洩防止のため）。
- **新しいパスは必ず控えておいてください。** 忘れたり締め出されたりした場合は、サーバー側から CLI で元に戻せます。

```bash
# Docker でのデプロイ
docker exec -u www-data jaas-auth php /var/www/html/login-path.php show     # 現在のパスを表示
docker exec -u www-data jaas-auth php /var/www/html/login-path.php reset    # /jt-login に戻す
docker exec -u www-data jaas-auth php /var/www/html/login-path.php set xxx  # 新しいパスを直接設定

# 直接インストール（Apache + PHP）：プロジェクトのルートディレクトリで実行
sudo -u www-data php login-path.php reset
```

---

<br>
<br>
<br>
<br>
<br>
<br>

## 録画の閲覧（自前ホストの Jibri）

自前ホストの Jitsi Meet + Jibri で録画する場合、同梱の `jibri-recordings-api`（Python 標準ライブラリのみで動くサービス）を Jibri ホスト上で動かすと、ポータルから録画を**一覧 / 再生 / ダウンロード / 削除**でき、**録画ホストのストレージ容量**も表示できます。

- このサービスは本ポータルの送信元 IP + Bearer トークンによるリクエストのみを受け付けます。ポータル側でも、プロキシする前に管理者のサインインを要求します。
- **システム設定 → 録画設定 → Jibri 録画サービス**にサービスの URL とトークンを入力します。検出されると、ナビゲーションバーに「録画記録」タブが表示されます。
- **保持ポリシー**（既定はすべて無効）：経過日数（N 日間保持）、容量（最低限の空き容量を確保 / 録画の合計サイズに上限を設定し、古いものから削除）、残骸や未完了の録画の自動クリーンアップ。録画中のファイルがクリーンアップされることはありません。
- サービスのインストールと systemd の設定は **[JIBRI-SETUP_ja.md](JIBRI-SETUP_ja.md)** を参照してください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## セキュリティ

OWASP Top 10:2025 に項目ごとに準拠しています。

- **A01 アクセス制御**：権限のないページは 404 を返し、会議室は所有者ごとに分離され（ホストのハートビート / 退出と録画へのアクセスを含む）、状態を変更する操作はすべて POST + CSRF トークンです。
- **A02 セキュリティ設定**：エラー表示とバージョン情報の開示を無効化、セキュリティヘッダー、機密パスへのアクセス拒否。
- **A03 サプライチェーン**：外部 PHP パッケージゼロ。フロントエンドの CDN リソースは SRI で完全性を検証します。Jitsi IFrame API（`external_api.js`）は第三者から都度読み込むのではなく、同梱して SRI で固定しています。イメージのビルド時に最新の OS セキュリティ更新を適用します。
- **A04 暗号**：bcrypt によるパスワード、JWT 署名、webhook の HMAC、安全なセッション Cookie。
- **A05 インジェクション**：出力のエスケープ、入力のサニタイズ、メールヘッダーインジェクション対策、会議ページを含む全ページで nonce ベースの Content Security Policy（インラインスクリプトの許可なし）。
- **A06 安全な設計**：ゲートウェイ型のアーキテクチャ、安全な既定値（ゲストは名前の入力が必須、ホストがオンラインの間のみ入室可）、最小権限のロール。
- **A07 認証**：TOTP による 2FA、実際の送信元 IP による fail2ban 方式のログインロックアウト**に加え、分散型の推測攻撃に対するアカウント単位のロックアウト**、セッションの無操作 / 絶対タイムアウト、任意のログインページのパスの偽装（リダイレクトで漏れることはありません）。
- **A08 データの完全性**：すべてのデータファイルをロック付きでアトミックに書き込み（並行処理でも更新が失われません）。webhook は HMAC 署名で検証し、重複イベントは冪等キーで除去します。
- **A09 ログとアラート**：完全な監査ログ + SIEM へのリアルタイム転送。
- **A10 例外処理**：フェイルセーフな縮退動作。読み込みに失敗したら既定値に戻り、メール / 転送の失敗は主処理を妨げず、エラーは漏洩しません。
- 秘密鍵、設定、実行時データはすべてマウントボリュームに保存され、**バージョン管理に入ることはありません**（`.gitignore` を参照）。
- **既知の制限（Jitsi の設計によるもの）：** 会議室名は招待リンクの一部であるため、機密性の高い会議ではランダムな名前かロビーモードを使ってください。JWT なしの自前ホストモードでは、会議室名を知っていれば誰でも Jitsi のドメインから直接参加できます（JWT を有効にしてください。設定ページでも警告されます）。会議トークンを一度受け取ったゲストは、その有効期間（6 時間）内であれば直接再参加できます。問題になる場合はロビーモードを使ってください。
- 各リリースは、単体テスト、結合テスト、ブラウザの e2e テスト、そして **High / Medium のアラートがゼロ**の OWASP ZAP スキャンに合格する必要があります。[TEST_CHECKLIST_ja.md](TEST_CHECKLIST_ja.md) を参照してください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## ライセンス

本プロジェクトは [GNU General Public License v3.0](LICENSE)（GPL-3.0-only）の下で公開されています。

> v1.7.0 までのバージョンは Apache License 2.0 で公開されていました。v1.8.0 以降は GPL-3.0 でライセンスされています。

<br>
<br>
<br>
<br>
<br>
<br>

## 免責事項

本ソフトウェアは明示・黙示を問わずいかなる保証もなく「現状のまま」提供されます。デプロイ環境のセキュリティとコンプライアンス（8x8 JaaS などの第三者サービスの規約と料金を含む）については、利用者が単独で責任を負います。本ソフトウェアの使用により生じた直接的または間接的な損害について、作者は一切の責任を負いません。

<br>
<br>
<br>
<br>
<br>
<br>

## 作者 / リンク

- 作者：Jason Cheng（[jasoncheng7115](https://github.com/jasoncheng7115)）
- プロジェクト：<https://github.com/jasoncheng7115/jt-vc-portal>
- 不具合の報告：GitHub Issues から
