<p align="center"><img src="docs/images/icon.svg" alt="jt-vc-portal" width="96" height="96"></p>

# jt-vc-portal v1.19.0 — 会議管理システム

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
- **会議の文字起こしと要約（jt-live-whisper）**：自前ホストの Jibri での録画が完了すると、ポータルはそれを [jt-live-whisper](https://github.com/jasoncheng7115/jt-live-whisper)（JTLW）に渡し、発言者付きの文字起こしと会議要約を生成できます。要点、決定事項とアクション項目、出来事、リスク、未解決の課題、議題、発言統計を含み、各項目に録画内の時刻が付きます。アカウントごとの権限（利用不可 / 手動 / 自動）と会議ごとの設定に対応し、管理者はどの会議でも生成できます。ビューアーには波形プレーヤー、クリックでその位置から再生、発言者の名前変更、発言者の対応候補（Jitsi の発言者タイムラインに基づく）を備え、議事録一式を PDF / DOCX / ODT / HTML（ほかに TXT / SRT / JSON / Markdown）でエクスポートできます。ポータル自体が言語モデルに接続することはありません。設定の詳細は [TRANSCRIPTS-SETUP_ja.md](TRANSCRIPTS-SETUP_ja.md) を参照してください。
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
| PHP | 8.3 | **8.4** |
| Web サーバー | Apache + `mod_rewrite`（`AllowOverride All`） | 同左 |
| PHP 拡張 | `openssl`、`fileinfo`、`json`、`mbstring`、`curl`、`zlib` | 同左 |
| その他 | 書き込み可能なデータディレクトリ、JaaS モードでは 8x8 の秘密鍵 | Docker 24+ |

> 自前ホストの Jitsi Meet モードでは、別途稼働中の Jitsi Meet サーバーが必要です（「接続モード」を参照）。

> **PHP 8.2 はサポート対象外になりました**：PHP 8.2 はセキュリティ修正のみで、2026-12-31 に終了（EOL）します。直接インストールしたサイトは 8.3 以降へ更新してください（8.3 のセキュリティ修正は 2027-12-31、8.4 は 2028-12-31 まで）。Docker イメージと Release イメージはすでに 8.4 のため影響はありません。

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

> **リバースプロキシでセキュリティヘッダーを追加しないでください**：CSP、`X-Frame-Options`、`X-Content-Type-Options`、`Referrer-Policy` は jt-vc-portal 自身が送ります。プロキシでもう一組追加すると、互いに矛盾する二組になります（ブラウザーは両方を適用するか、最後の一組だけを使います）。特に **`Permissions-Policy`** に注意してください。会議は別ドメインの Jitsi を埋め込んでいるため、ポータルのドメインが `camera=(self)` や `microphone=()` のような値を送ると、埋め込まれた会議はカメラとマイクを使えません（Chrome や Edge は即座に拒否します）。どうしても追加する場合は、`camera`、`microphone`、`display-capture` に Jitsi のドメインを含めてください。例：`microphone=(self "https://meet.example.com")`。
> nginx の落とし穴：`http` レベルの `add_header`、または `include /etc/nginx/conf.d/*.conf;` で自動的に読み込まれるファイル内の `add_header` は、自身で `add_header` を書いていない**すべての**サイトに適用されます。特定サイト用のヘッダーファイルは `conf.d` の外（例：`/etc/nginx/snippets/`）に置き、そのサイトの `server` / `location` 内でのみ `include` してください。

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
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.19.0/jt-vc-portal-1.19.0-docker-amd64.tar.gz
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.19.0/jt-vc-portal-1.19.0-docker-amd64.tar.gz.sha256

# 2) 完全性を検証（OK と表示されるはず）
sha256sum -c jt-vc-portal-1.19.0-docker-amd64.tar.gz.sha256

# 3) イメージを読み込む（jt-vc-portal:1.19.0 と :latest のタグが作成される）
docker load < jt-vc-portal-1.19.0-docker-amd64.tar.gz

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

### アップグレードの前に：各バージョンで必要なもの

Docker イメージと Release イメージにはすべて含まれているため、追加のインストールは不要です。**直接インストール**の場合は、飛ばすバージョンの行を確認してください：

| アップグレード元 | 追加が必要なもの |
|---|---|
| v1.10.0 より前 | シングルサインオン（任意）には PHP の `curl`、`openssl` 拡張が必要です（Debian / Ubuntu：`apt install php-curl`）。 |
| v1.12.0 より前 | 文字起こしと要約（任意）には `curl` と**毎分実行するバックグラウンドジョブ**が必要です。「会議の文字起こしと要約」を参照してください。Docker の場合もホストにジョブを追加します。 |
| v1.16.0 より前 | セルフホストの Jibri がある場合は、Jibri ホストの `jibri-recordings-api/server.py` も更新し（パスとヘッダーの安全性強化）、`systemctl restart jibri-recordings-api` を実行してください。 |
| v1.16.1 より前 | セルフホストの Jitsi Meet が `stable-11031` 以降の場合：`.env` を `XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass` にし、`JICOFO_ENABLE_AUTH=0` を追加、旧 `GLOBAL_CONFIG=disable_cascading_set = false` を無効にしてから `docker compose up -d`。そうしないとゲストがモデレーターになります（録画や退出操作が可能）。詳しくは [JITSI-MEET-SETUP_ja.md](JITSI-MEET-SETUP_ja.md) を参照してください。 |
| v1.16.2 より前 | セルフホストの Jibri が `stable-11031` 以降の場合：jibri サービスの `environment` に `SE_AVOID_STATS=true`、`SE_OFFLINE=true` を追加してコンテナーを作り直してください。そうしないと Jibri ホストの外部接続が遅いとき、録画開始を押してしばらく待った後に「すべての録画が現在使用中」と表示されます。[JIBRI-SETUP_ja.md](JIBRI-SETUP_ja.md) の 7 節を参照。 |
| v1.14.0 より前 | 議事録の PDF / DOCX / ODT エクスポートには PHP の `zlib` 拡張（Debian / Ubuntu の PHP パッケージには組み込み済み）と、同梱フォント `lib/fonts/NotoSansTC-Regular.ttf`（`git pull` で取得されます）が必要です。 |

`php -m | grep -iE 'curl|mbstring|openssl|zlib|fileinfo|json'` で確認できます。アップグレード後に**システム設定**を開くと、不足しているコンポーネントが上部に表示され、バックグラウンドジョブが動いていない場合は「文字起こしと要約」カードに警告が出ます。

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
| 録画 | 8x8 に組み込み（プランによる） | 自前ホストの Jibri と併用（本システムで閲覧・再生・ダウンロード） |
| 文字起こし | 8x8 の別料金のライブ字幕（分単位の課金、ファイルは 24 時間のみ保持し本システムには保存されない）。想定外の課金を避けるため本システムでは既定で無効 | Jibri 録画 + セルフホストの jt-live-whisper：会議後に話者付きの文字起こしを生成して本システムに保存。データは自社外に出ません |
| 会議要約 | なし | jt-live-whisper がセルフホストの言語モデルで作成：要点、決定事項と ToDo、リスク、トピック、発言統計。各項目に録画内の時刻付き |
| 議事録のエクスポート | なし | PDF / DOCX / ODT / HTML、ほかにプレーンテキスト、SRT、JSON、Markdown |

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
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php show     # 現在のパスを表示
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php reset    # /jt-login に戻す
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php set xxx  # 新しいパスを直接設定

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

## 会議の文字起こしと要約（jt-live-whisper）

> **設定ガイドの全体**（JTLW 側のキーと証明書、ポータルの設定、バックグラウンドジョブ、権限、エクスポート、トラブルシューティング）：**[TRANSCRIPTS-SETUP_ja.md](TRANSCRIPTS-SETUP_ja.md)** を参照してください。

自前ホストの Jibri での録画が完了すると、ポータルはそれを音声サービス **jt-live-whisper（JTLW）** に渡し、発言者付きの文字起こしと会議要約を受け取ります。結果はポータルが録画の隣に保存します。

**要件**

- 録画の閲覧が設定済みであること（自前ホストの Jibri + `jibri-recordings-api`、前節を参照）。
- jt-live-whisper の REST API（`api_revision` 2.4 以降）と、本ポータル用の API キー（スコープ `jobs:write`、`jobs:read`、`jobs:cancel`、`profiles:read`）。
- 任意：完了通知を送るには、JTLW ホストから `<your site>/jtlw-webhook` に到達できる必要があります。なくてもポータルは動作します。バックグラウンドワーカーが毎分進捗を確認します。

**設定**

1. **システム設定 → 文字起こしと要約**：JTLW の URL（例：`https://10.0.0.30:8790`）と API キーを入力し、自己署名証明書の場合はその PEM を貼り付けます（貼り付けたものがそのまま信頼されます。証明書の検証を無効にすることはありません。表示される SHA-256 フィンガープリントを照合してください）。会議の言語（分かっている場合は指定してください。「自動判定」は冒頭約 30 秒で決めます）と、要約を生成するかどうかを選びます。**保存して接続テスト**を押し、続いて **webhook を登録**を押します。
2. **バックグラウンドワーカー** — 毎分実行します：
   - Docker：ホストの crontab に `* * * * * docker exec -u www-data jt-vc-portal php /var/www/html/transcribe-worker.php` を追加
   - 直接インストール：`/etc/cron.d/jtvc-transcribe` に `* * * * * www-data php /var/www/jt-vc-portal/transcribe-worker.php`（インストール先に置き換え）
   録画を 1 件ずつアップロードし、進捗を追跡し、結果を取得し、録画がなくなった結果を削除します。同時に実行されるのは 1 つだけです。
3. **権限 — アカウント管理 → 文字起こし権限**をホストごとに設定します：*利用不可*（既定）、*手動*（自分の会議で「文字起こしを生成」を押せる）、*自動*（録画完了時に生成）。会議室の作成時、文字起こしを利用できるホストはその会議ごとにオン / オフを設定できます。管理者はどの会議でも文字起こしを生成できます。自動生成は、機能を有効にした後に録画された会議のみが対象です。

**仕組みとデータの保持**

- ポータルは録画を JTLW にストリーミングで送り、1 つのジョブ（認識、発言者、句読点の校正、要約）を登録し、webhook を待つかポーリングして、文字起こしと要約（JSON + Markdown）を取得してディスクに書き込んでから、初めて JTLW にそのコピーの削除を指示します。JTLW はアップロードされた録画を処理後に削除します。
- 結果はデータディレクトリ（`transcripts/<recording id>/`）に保存され、録画に連動します。録画を削除した場合や、Jibri の保持ポリシーで録画が削除された場合は、その文字起こしと要約も削除されます。
- ホストが見られるのは自分が主催した会議の文字起こしのみです。監査ログには誰が生成・閲覧・ダウンロード・名前変更したかが記録されますが、文字起こしの内容は記録されません。
- 要約は中国語と英語の会議で利用できます。日本語と韓国語では文字起こしのみ生成されます。発言者 ID（S1、S2…）は声のクラスターであり、名前ではありません。文字起こし画面で名前を変更できます。
- **議事録のエクスポート（v1.14.0）**：文字起こし画面から議事録一式（会議情報、要約（出典付きの決定事項と ToDo、リスク、未解決の質問、トピック、発言統計）、名前の変更を反映した文字起こし）を **PDF、DOCX、ODT、HTML**（HTML は v1.15.0 以降。どのブラウザーでも開け、そのまま印刷できる単一ファイル）でダウンロードできます。プレーンテキスト、SRT 字幕、JSON、Markdown の要約は「その他の形式」にあります。すべて portal 自身が生成します（サーバーに LibreOffice やブラウザーエンジンは不要）。PDF には同梱の Noto Sans TC フォント（SIL Open Font License）から使用した文字だけを埋め込むため、中国語と日本語が正しく表示され、検索もできます。このフォントには韓国語は含まれません。
- **発言者の候補（v1.13.0）**：ホストの会議画面が Jitsi の「現在の発言者」タイムラインを記録し、文字起こし画面で各発言者 ID がどの参加者かを候補として表示します（重なった時間の割合付き）。クリック 1 回で適用するか、参加者一覧から選べます。タイムラインを完全にするため、ホストの会議画面は会議中ずっと開いたままにしてください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## セキュリティ

OWASP Top 10:2025 に項目ごとに準拠しています。

- **A01 アクセス制御の不備**：未認可のページは常に 404（入口を明かさない）。会議室・録画・文字起こしは所有者ごとに分離され、ホストは自分が主催した会議だけを閲覧できます。文字起こしにはアカウント単位の権限（利用不可 / 手動 / 自動）と会議ごとのスイッチがあります。シングルサインオンのユーザーのロールは IdP のグループだけで決まり、指定グループ外のユーザーは拒否します。状態を変更する操作はすべて POST + CSRF トークン。
- **A02 セキュリティ設定のミス**：エラー表示とバージョン情報の露出は無効。すべての応答にコンテンツセキュリティポリシー（既定は最も厳格、ページでは nonce 版に置き換え）とセキュリティヘッダーを付与。機密パス（`lib/`、`keys/`、`*.json`）へのアクセスは拒否。`X-Real-IP` は許可リストのリバースプロキシからのものだけを信頼。設定画面にシークレットを再表示しません。システム設定画面で不足コンポーネントと停止中のバックグラウンドジョブを表示します。
- **A03 ソフトウェアサプライチェーンの不備**：外部 PHP パッケージはゼロ：OIDC、PDF / DOCX / ODT の生成、ZIP はすべて自前で実装し、LibreOffice などの大きなパッケージは不要。フロントエンドの CDN リソースには SRI。Jitsi IFrame API（`external_api.js`）はバージョンを固定して同梱し SRI を付与、第三者から都度読み込みません。PDF 用フォントを同梱（SIL OFL）。イメージのビルド時に最新の OS セキュリティ更新を適用。Release イメージは CI がタグのソースからビルドし sha256 を添付。
- **A04 暗号化の失敗**：パスワードは bcrypt でハッシュ化。JWT は RS256 / HS256 で署名し有効期限を設定。シングルサインオンの id_token は IdP の公開鍵（JWKS）で署名を検証し、RS256 / RS384 / RS512 のみ受け付け、`none` やアルゴリズム混同を拒否。音声サービスへの接続は常に TLS 証明書を検証（自己署名証明書は貼り付けた PEM で信頼し、検証を無効にすることはありません）。webhook は HMAC 署名。セッション Cookie は HttpOnly・SameSite・Secure。
- **A05 インジェクション**：出力はすべてエスケープし、入力はサニタイズ。メールヘッダーインジェクションと CSV 数式インジェクションを防止。転送する syslog から改行を除去して偽装を防止。エクスポートする DOCX / ODT / HTML の内容はすべてエスケープし制御文字を除去。全ページ（会議画面を含む）で nonce ベースのコンテンツセキュリティポリシーを適用し、インラインスクリプトを禁止。
- **A06 安全でない設計**：ゲートウェイ型の構成、安全な既定値（ゲストは名前が必須、主催者がオンラインの間だけ入室可能）、最小権限のロール。ポータルは AD のパスワードを一切扱いません。会社アカウントは必ず OIDC の ID プロバイダーを経由し、MFA と総当たり対策はそこで行います。ポータルは言語モデルにも直接接続せず、文字起こしと要約はセルフホストの音声サービスに任せ、結果を保存した後に相手側のコピーを削除させます。
- **A07 認証の不備**：OIDC シングルサインオン：Authorization Code + PKCE（S256）+ state + nonce、アカウントは iss + sub で紐付け、メールアドレスでローカルアカウントと自動統合することはありません。「SSO のみ」モードと IP 制限付きの緊急用ローカル管理者。ローカルアカウントは TOTP 二要素認証（リプレイ防止）、実際の送信元 IP **＋アカウント単位**のログインロック（多数の IP からのパスワード推測を防止）。セッションのアイドル / 絶対タイムアウト、パスワード変更や強制サインアウトで即時失効。任意のログインパス偽装（リダイレクトで漏れない）。
- **A08 ソフトウェアとデータの整合性の不具合**：すべてのデータファイルを「アトミック」に書き込みます。新しい内容をいったん一時ファイルに書き切ってから、1 回の名前変更で本来のファイルと入れ替えるため、書き込み中に停電やクラッシュが起きても以前の完全なファイルが残り、壊れたファイルにはなりません。さらに「ロック」により、同時に行われた変更は順番に適用され、互いに上書きされません。webhook（8x8 の利用量、音声サービス）は HMAC 署名と時間枠で検証し、idempotency key / イベント ID で重複を除去。音声サービスへのジョブには Idempotency-Key を付け、再送しても二重に処理されません。結果をディスクに保存してから相手側に削除を依頼。設定のインポートはホワイトリスト方式。
- **A09 セキュリティログとアラートの不備**：完全な監査ログ：ログイン、シングルサインオンの成功 / 失敗、会議室・アカウント・設定・録画・文字起こしに対するすべての操作（文字起こしの内容は記録しない）。各エントリはリアルタイムで SIEM に転送（syslog / CEF / GELF）し、偽装防止のため改行を除去します。
- **A10 例外的な状況の不適切な処理**：フェイルセーフな縮退：読み取り失敗時は既定値、メール / ログ転送の失敗で本処理を止めない、エラー内容を漏らさない。シングルサインオンの検査が 1 つでも失敗すれば拒否（フェイルクローズ）。音声サービスに接続できない場合はバックオフで再試行し、24 時間を超えると分かりやすい理由付きで失敗とし、要約の失敗は自動でやり直します。コンポーネント不足時はエラー 500 ではなく分かりやすいメッセージを返します。再生失敗時はログインの期限切れ、ファイルなし、サービス停止を区別します。
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

同梱フォント `lib/fonts/NotoSansTC-Regular.ttf`（Noto Sans TC、PDF エクスポート用）は SIL Open Font License 1.1 でライセンスされています。全文は `lib/fonts/OFL.txt` を参照してください。

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
