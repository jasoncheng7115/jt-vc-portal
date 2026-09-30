# セルフホスト Jitsi Meet × jt-vc-portal 連携セットアップ

> English: [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md) · 繁體中文: [JITSI-MEET-SETUP_zh-TW.md](JITSI-MEET-SETUP_zh-TW.md)

> **作者**：Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　プロジェクト [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

本書では、**公式の Docker 版 Jitsi Meet** を jt-vc-portal の「認証ゲートウェイ」と連携して動作させるための設定方法を説明します。

> 対象バージョン：[docker-jitsi-meet](https://github.com/jitsi/docker-jitsi-meet) **`stable-11031`**（2026-06-08 リリース）。他の安定版でも手順は同じで、バージョン番号を置き換えるだけです。

---

## 目次

**基本概念**

- [アーキテクチャ概要](#アーキテクチャ概要)
- [前提条件](#前提条件)
- [ポートと NAT の設定（メディアのフォールバック / TURN を含む）](#ポートと-nat-の設定)

**インストール手順**

- [1. 公式 docker-jitsi-meet の取得](#1-公式-docker-jitsi-meet-の取得)
- [2. 外部公開の基本設定（`.env`）](#2-外部公開の基本設定env-の編集)
- [3. JWT 認証の有効化（推奨）](#3-jwt-認証の有効化推奨)
- [4. Jitsi の起動](#4-jitsi-の起動)
- [5. jt-vc-portal 側の設定](#5-jt-vc-portal-側の設定)

**応用 / 運用**

- [6. トラブルシューティング](#6-トラブルシューティング)
- [7. 録画（Jibri）](#7-録画jibri任意)
- [8. ブランドロゴと録画者の非表示](#8-ブランドロゴと録画者の非表示サーバーの-configjs)
- [9. Jitsi のアップグレード](#9-jitsi-のアップグレード)

---

<br>
<br>
<br>
<br>
<br>
<br>

## アーキテクチャ概要

```
ユーザー ──▶ jt-vc-portal（vc.example.com）──── IFrame 埋め込み ────▶ セルフホスト Jitsi Meet（meet.example.com）
              ‧ログイン / ロール / ロビー / 監査                          ‧実際の音声・映像会議
              ‧JWT の発行（HS256、任意）                                  ‧メディアはブラウザーがこのドメインへ直接接続
```

- **jt-vc-portal**：入口（ログイン、権限、予約、ロビー、監査）を担当し、IFrame API で Jitsi を埋め込みます。
- **Jitsi Meet**：会議そのものを担当します。ブラウザーは `meet.example.com` に直接接続して `external_api.js` を読み込み、メディアをやり取りします。
- 認証方式は 2 種類：**JWT なし（オープン）** または **HS256 JWT（推奨）**。後者では jt-vc-portal が発行したトークンを持つ人だけが会議室に入れます。

> **運用範囲（セルフホスト vs. JaaS）**：ここで説明するポート、NAT、メディアのトラバーサル / TURN（`turns/443` を含む）は、**セルフホストモードの場合にのみ自分で対処が必要なもの**です。**8x8 JaaS** を使う場合、メディアと NAT トラバーサルはすべて 8x8 クラウドが処理するため、これらのポートの開放や coturn の運用は不要です。JaaS モードの jt-vc-portal は認証（JWT への署名）だけを行い、メディアが自分のホストを通ることはありません。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 前提条件

- 外部から到達可能で DNS が設定されたホスト。例：`meet.example.com`（jt-vc-portal とは別のドメインまたはサブドメインである必要があります）。
- 外部に開放：`443/tcp`（Web / シグナリング）、`10000/udp`（JVB メディア）。
- 有効な HTTPS 証明書（Jitsi 内蔵の Let's Encrypt を利用可能）。
- Docker と Docker Compose がインストール済みであること。

---

<br>
<br>
<br>
<br>
<br>
<br>

## ポートと NAT の設定

### 開放するポート

| ポート | プロトコル | 用途 | 必須か |
|---|---|---|---|
| 443 | TCP | HTTPS の Web + 会議シグナリング（BOSH / WebSocket） | 必須 |
| 80 | TCP | HTTP→HTTPS リダイレクト + Let's Encrypt 証明書の発行 | 内蔵 LE を使う場合は必須 |
| 10000 | UDP | JVB メディア（音声・映像 RTP）、**メインのメディア経路** | 必須 |
| 4443 | TCP | JVB メディアの TCP フォールバック（UDP が遮断されたネットワークの利用者向け） | 任意 |

- メディアはほぼ常に **UDP/10000** を通ります。UDP を遮断する一部のネットワークでのみ **TCP/4443** のフォールバックに頼ります。安定性のため両方の開放を推奨します。
- ファイアウォール / クラウドの Security Group で、上記を**インバウンド**として許可する必要があります。
- シグナリング（参加、チャット）は 443/TCP、メディア（音声と映像）は 10000/UDP を使います。どちらかが欠けると「参加はできるが画面が真っ黒 / 音が出ない」状態になります。

> **注意（旧バージョンとの違い）**：現在の Jitsi（`stable-11031` を含む）の JVB は **UDP 10000 番の単一ポート**で多重化しており、全参加者のメディアがこの 1 ポートを共有します。「10000–20000 の範囲をまるごと開放する」必要は**もうありません**。それは何年も前の旧バージョンのやり方（`org.ice4j.ice.harvest.MIN/MAX_PORT` による動的ポート範囲）で、docker の単一ポートモードでは過去のものです。`UDP 10000`（+ 任意で `TCP 4443`）を許可するだけで十分です。

### NAT / ファイアウォールの内側にある場合（ホストがプライベート IP）

JVB はデフォルトで「自分から見えている IP」をブラウザーに通知します。ホストがプライベート IP（NAT の内側）の場合、ゲストにはプライベート IP が渡され、メディアに到達できません。JVB に**パブリック IP を通知させる**必要があります。

**(1) `.env` を編集**して JVB にパブリック IP を通知させます（複数の値はカンマ区切り。パブリック IP とホストのプライベート IP を両方書くと、外部・内部どちらのクライアントも接続できます）：

```ini
JVB_ADVERTISE_IPS=<public-IP>,<host-private-IP>
```

**(2) ルーター / ファイアウォールで Jitsi ホストへポートフォワード：**

- `UDP 10000` → ホスト:10000（**最重要**）
- `TCP 443` → ホスト:443
- `TCP 80` → ホスト:80（Let's Encrypt の発行 / 更新時）
- （任意）`TCP 4443` → ホスト:4443

**(3) クラウドホスト**（GCP / AWS / Azure など）：VPC ファイアウォール / Security Group で `UDP 10000`、`TCP 443`、`TCP 80`（および `TCP 4443`）を許可し、パブリック IP を `JVB_ADVERTISE_IPS` に設定します。

> **最もよくある失敗**：会議室には入れるが画面が真っ黒 / 音が出ない → 8 割は `UDP 10000` が許可 / 転送されていないか、`JVB_ADVERTISE_IPS` がパブリック IP に設定されていないことが原因です。

### メディア経路の自動フォールバック（UDP 10000 → （任意の TCP 4443）→ TURN、turns/443 を含む）

ブラウザーの **ICE** の仕組みにより、ゲストの音声・映像のメディア経路は「速いものからファイアウォール通過性の高いものへ」の順で**自動的に**試され、使える中で最も優先度の高い経路が選ばれます。**判定ロジックを書く必要はなく、経路を用意しておくだけで十分です**：

| 優先度 | 経路 | 想定シナリオ | 難易度 |
|---|---|---|---|
| 1 | **UDP 10000** → JVB へ直接 | 通常のネットワーク（最高品質） | デフォルトで利用可能 |
| 2 | **TCP 4443** → JVB へ直接 | UDP は遮断、任意の TCP 送信は許可 | 任意 / 旧方式 |
| 3 | **TURN**：`turn`（udp/tcp 3478）+ `turns`（tls 443）→ coturn 経由で中継 | UDP が遮断、または 443 **しか**許可されていない | 別途 coturn が必要 |

> ほとんどの利用者は**第 1 層の UDP 10000** だけで問題なく使えます。フォールバックが必要になるのは「ゲストのネットワークが非常に厳しい」場合だけです。また **TURN（第 3 層）は単一のコンポーネントで「UDP が使えない」と「443 しか残っていない」の両方をカバーできる**ため、第 2 層を飛ばして直接 TURN を用意することを推奨します。
>
> 性能のトレードオフ：下の層ほどファイアウォール通過性は高くなりますが、遅くなります。`turns/443` は TCP + 中継 + TLS がもう 1 層加わるため遅延が最も大きく、coturn が中央のボトルネックにもなります。「最後の命綱」としてのみ使い、UDP 10000 が使えるときに頼らないでください（Google Meet も同様で、UDP を優先し、UDP が完全に遮断されたときだけ TCP/443 にフォールバックし、TCP では品質が低下すると公式に明記しています）。

#### 第 1 層：UDP 10000（デフォルト、最高品質）

前述のとおり：`UDP 10000` を許可し、`JVB_ADVERTISE_IPS` を設定します。

#### 第 2 層：TCP 4443（JVB へ直接、任意 / 旧方式）

現在の Jitsi は JVB 内蔵の TCP ハーベスターを**デフォルトで無効**にしており、上流ではフォールバックを TURN で一元的に処理するようになっています。`stable-11031` の docker `.env` にも対応するスイッチは**ありません**。強制的に有効にするには、カスタム設定を重ねて TCP ハーベスターを再度有効にし、`TCP 4443` を開放する必要があります。**ほとんどの場面では不要なので、直接第 3 層の TURN を用意してください**。

#### 第 3 層：TURN（coturn、turns/443 を含む）

仕組み：`turn`（udp/tcp 3478）と `turns`（TLS 443）の両方を提供する **TURN サーバー（coturn）** を動かします。ICE は「まず UDP 中継、次に TCP 中継、最後に turns/443」と自動的に段階を下げて試し、つながるものを使います。その後 coturn がメディアを UDP で JVB に中継します。`docker-jitsi-meet` には **coturn が含まれていません**が、公式の Docker イメージで簡単に起動できます。

**(1) coturn の設定** `coturn/turnserver.conf`：

```ini
listening-port=3478
tls-listening-port=5349           # 構成 A では直接 443 を設定可能（後述）
fingerprint
use-auth-secret
static-auth-secret=<長いランダム文字列、prosody と共有>
realm=meet.example.com
cert=/etc/coturn/certs/turn.crt
pkey=/etc/coturn/certs/turn.key
min-port=49152
max-port=65535
external-ip=<このホストのパブリックIP>
no-multicast-peers
no-cli
```

**(2) Docker で coturn を起動**（公式イメージ `coturn/coturn`）。coturn は広い範囲の UDP 中継ポートを必要とするため、**ホストネットワーク**が最も簡単です。以下は Jitsi の compose と並べて置く compose ファイルです：

```yaml
# docker-compose.coturn.yml
services:
  coturn:
    image: coturn/coturn:4.6          # バージョンを固定し latest は使わない
    restart: unless-stopped
    network_mode: host                # 中継ポートが多いのでホストネットワークが最も簡単
    volumes:
      - ./coturn/turnserver.conf:/etc/coturn/turnserver.conf:ro
      - ./coturn/certs:/etc/coturn/certs:ro
    command: ["-c", "/etc/coturn/turnserver.conf"]
```

```bash
docker compose -f docker-compose.coturn.yml up -d
```

**(3) 443 をどこに置くか — 2 つの構成から 1 つを選択：**

- **構成 A（シンプル、推奨）：coturn に専用のホスト名 + IP を持たせる**（別の小さなホスト、または同じマシンの 2 つ目のパブリック IP）。turnserver.conf で直接 `tls-listening-port=443` を設定すれば、coturn が単独で 443 を使うため、**nginx による振り分けはまったく不要**です。DNS の `turn.meet.example.com` をその IP に向けるだけです。
- **構成 B（Jitsi と同じ IP の 443 を共有）**：この場合に限り、前段で nginx の `stream` + `ssl_preread` を使い、**SNI** でトラフィックを振り分ける必要があります（TLS は終端せず、そのままパススルーします）：

```nginx
stream {
  map $ssl_preread_server_name $upstream {
    turn.meet.example.com  127.0.0.1:5349;   # TURN/TLS → coturn
    default                127.0.0.1:8443;    # それ以外 → Jitsi の web コンテナー
  }
  server {
    listen 443;
    listen [::]:443;
    ssl_preread on;
    proxy_pass $upstream;
  }
}
```

> 構成 B では nginx が 443 を使うため、Jitsi の web コンテナーは別のポートに移し（`.env` で `HTTPS_PORT=8443` を設定）、nginx からリバースプロキシする必要があります。`turn.meet.example.com` と `meet.example.com` はどちらもこのホストに向けます。

**(4) prosody から TURN をブラウザーに通知させる**（XEP-0215 `external_services`）ことで、ブラウザーが TURN を利用できることを認識します。docker 版では prosody の設定を重ねることで実現し、内容は以下と同等です：

```lua
external_services = {
  { type = "turn",  host = "turn.meet.example.com", port = 3478, transport = "udp", secret = "<coturn の static-auth-secret と同じ>" };
  { type = "turns", host = "turn.meet.example.com", port = 443,  transport = "tcp", secret = "<coturn の static-auth-secret と同じ>" };
};
```

**(5) 検証**：`https://webrtc.github.io/samples/src/content/peerconnection/trickle-ice/` を開いて `turns:turn.meet.example.com:443?transport=tcp` を入力すると、`relay` 候補が表示されるはずです。さらにテスト用クライアントのネットワークを 443 のみに制限し、会議が引き続き使える（映像 / 音声が正常）ことを確認します。

> この層は証明書、SNI による振り分け（構成 B の場合）、coturn と prosody のシークレットの一致などが絡むため、環境による差が大きくなります。必要であれば、実際の構成（同一ホスト / 別ホスト、証明書の入手元）に合わせた手順書を別途作成できます。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 1. 公式 docker-jitsi-meet の取得

```bash
git clone https://github.com/jitsi/docker-jitsi-meet.git
cd docker-jitsi-meet
git checkout stable-11031

cp env.example .env
./gen-passwords.sh         # 各内部コンポーネントのランダムなパスワードを生成（.env に書き戻す）

mkdir -p ~/.jitsi-meet-cfg/{web,transcripts,prosody/config,prosody/prosody-plugins-custom,jicofo,jvb,jigasi,jibri}
```

> `.env` の `JITSI_IMAGE_VERSION` は `stable-11031`（チェックアウトしたタグと一致）にしておくと、対応するイメージが取得されます。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 2. 外部公開の基本設定（`.env` の編集）

```ini
# 公開 URL（= jt-vc-portal の「サービスドメイン」に入力するアドレス）
PUBLIC_URL=https://meet.example.com

# Let's Encrypt 自動証明書
ENABLE_LETSENCRYPT=1
LETSENCRYPT_DOMAIN=meet.example.com
LETSENCRYPT_EMAIL=you@example.com

# 外部ポート
HTTP_PORT=80
HTTPS_PORT=443
JVB_PORT=10000

TZ=Asia/Taipei

# ロビー：jt-vc-portal の「ロビーモード」（toggleLobby）を有効にする
ENABLE_LOBBY=1
```

### TLS 証明書の方式（いずれか 1 つを選択）

**(A) Let's Encrypt 自動証明書**（上の例）：`ENABLE_LETSENCRYPT=1` + `LETSENCRYPT_DOMAIN` + `LETSENCRYPT_EMAIL`。コンテナーが証明書を自動で取得・更新します。外部から `TCP 80` に到達できる必要があります。

**(B) 独自の SSL 証明書**（証明書 / 社内 CA / ワイルドカード証明書をすでに持っている場合）：Let's Encrypt を無効にし、証明書を web コンテナーの keys ディレクトリに置きます —

```ini
ENABLE_LETSENCRYPT=0
```

```bash
# 証明書を web 設定ボリュームの keys/ に置く（CONFIG のデフォルトは ~/.jitsi-meet-cfg）
mkdir -p ~/.jitsi-meet-cfg/web/keys
cp your-fullchain.pem ~/.jitsi-meet-cfg/web/keys/cert.crt   # 中間証明書チェーンを含む完全な証明書
cp your-private.key   ~/.jitsi-meet-cfg/web/keys/cert.key   # 対応する秘密鍵
# 再起動して web コンテナーに読み込ませる
docker compose up -d
```

> ファイル名は固定です：**`cert.crt`（フルチェーン）** と **`cert.key`（秘密鍵）** を `~/.jitsi-meet-cfg/web/keys/`（コンテナー内では `/config/keys/`）に置きます。`HTTPS_PORT=443` は変更しません。証明書の期限が切れる前にファイルを差し替え、`docker compose restart web` を実行してください。

**(C) 前段のリバースプロキシで TLS を処理**（証明書は nginx / Traefik / HAProxy 側にある場合）：コンテナーは HTTP のみを提供し、TLS はリバースプロキシに任せます —

```ini
ENABLE_LETSENCRYPT=0
DISABLE_HTTPS=1
HTTP_PORT=8000
```

リバースプロキシは `https://meet.example.com` をコンテナーの `HTTP_PORT` へ転送します。また **WebSocket**（会議シグナリングに必須）と `X-Forwarded-*` / `Host` ヘッダーも転送する必要があります。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 3. JWT 認証の有効化（推奨）

Jitsi が **jt-vc-portal の発行したトークンだけを受け付ける**ようにし、会議室名を推測しただけでは誰も入り込めないようにします。セルフホストモードでは、jt-vc-portal は **HS256 の共有シークレット**でトークンに署名します。

> **重要 — JWT を有効にすると、公式モバイルアプリからは直接参加できません**
> `ENABLE_AUTH=1`（JWT）では、会議室は jt-vc-portal が発行したトークンしか受け付けません。**公式の Jitsi Meet モバイルアプリ（iOS / Android）は本ポータルを経由せずトークンを取得できないため、参加できません**（認証 / トークンのエラーが表示されます）。
> モバイル端末では招待リンクを**モバイルブラウザー**で開き、jt-vc-portal 経由で参加してください。どうしてもネイティブアプリから会議室へ直接参加させたい場合は、「JWT なしの匿名モード」（後述）のままにするしかなく、安全性は低くなります。

`.env` に設定：

```ini
ENABLE_AUTH=1
AUTH_TYPE=jwt
ENABLE_GUESTS=0                       # トークン保持者のみ入室可（入口は jt-vc-portal が守る）

JWT_APP_ID=jt-vc-portal               # ← jt-vc-portal の「App ID」に対応
JWT_APP_SECRET=<長いランダム文字列を生成>  # ← jt-vc-portal の「app_secret（HS256 共有シークレット）」に対応
JWT_ACCEPTED_ISSUERS=jt-vc-portal     # App ID と同じ
JWT_ACCEPTED_AUDIENCES=jt-vc-portal   # App ID と同じ

# === モデレーター権限の制御（非常に重要。後述のとおり 3 行とも必須）===
ENABLE_AUTO_OWNER=0                            # 「最初に参加した人」を自動でモデレーターにしない
XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass   # トークンでモデレーターを決定。ホストはロビーを通過
JICOFO_ENABLE_AUTH=0                           # モデレーターは上のモジュールが決める。jicofo は付与しない
```

対応関係（**3 か所すべてを一致させる必要があります**）：

| jt-vc-portal（システム設定 → 接続モード） | Jitsi の `.env` | 備考 |
|---|---|---|
| App ID | `JWT_APP_ID` / `JWT_ACCEPTED_ISSUERS` / `JWT_ACCEPTED_AUDIENCES` | jt-vc-portal が署名するトークンは `iss` = `aud` = App ID |
| app_secret | `JWT_APP_SECRET` | HS256 の共有シークレット。両側で同一 |
| JWT sub | （prosody が検証する subject） | **空欄のまま**（デフォルトで `*` を送信し、テナントなしの単一ドメインに適合）。マルチテナント構成の場合のみテナント名を入力 |

> **シークレットの生成**：`openssl rand -hex 32` を実行し、同じ値を両側に貼り付けます。
>
> sub のデフォルトが `*` である理由：標準的な単一ドメインの docker-jitsi-meet（テナントなし）構成では、prosody のトークン検証は `sub` として `*` かテナント名しか受け付けません。本システムはデフォルトで `*` を送るため、そのまま動作します。

### モデレーター権限の制御（重要：設定しないと全員がモデレーターになる）

**docker-jitsi-meet で JWT を使う場合、デフォルトでは「有効なトークンを持つ人は全員モデレーター」になります** — トークンに `context.user.moderator: false` が含まれていても強制されません。その結果、**ゲストもモデレーターになり**、他者のキック、全員に対する会議の終了、**ロビーの回避**ができてしまいます。「指定したホストだけをモデレーターにする」には、上記 `.env` の 3 行が**すべて必須**です：

| 設定 | 効果 |
|---|---|
| `ENABLE_AUTO_OWNER=0` | 「最初に参加した人が自動的に owner になる」動作を無効にします。 |
| `XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass` | トークンの `moderator` フラグに基づいてユーザーを `owner`（モデレーター）または `member`（一般参加者）に設定する prosody モジュールを有効にします。`stable-11031` から Jitsi 本体に内蔵されています（`/prosody-plugins/mod_token_affiliation.lua`）。`token_lobby_bypass`（イメージ同梱のコミュニティモジュール）は、トークンに `lobby_bypass: true` がある人にロビーを通過させます。**これがないと、ロビーを有効にした部屋でホストが切断して再入室するとロビーで止められ、部屋にゲストしかいない場合は誰も入室を許可できません。** jt-vc-portal は v1.16.1 からホストにだけこのフラグを付けます。ゲストは引き続きロビーでノックが必要です。 |
| `JICOFO_ENABLE_AUTH=0` | jicofo の認証による権限付与を無効にします。有効のままだと jicofo が**有効なトークンを持つ全員**をモデレーターに昇格させ、上のモジュールの設定を上書きします。**この行がないとゲストもモデレーターのままで、録画の開始・退出させる・会議の終了ができてしまいます。** Jitsi のメンテナーが推奨する方法です（[#16297](https://github.com/jitsi/jitsi-meet/issues/16297)、[#16905](https://github.com/jitsi/jitsi-meet/issues/16905)）。無効にしても会議への参加には有効なトークンが必要です（prosody の検証は変わりません）。 |

> **stable-10888 以前からのアップグレード**：以前の本ガイドではコミュニティ版モジュール（`/prosody-plugins-contrib/token_affiliation`）と `GLOBAL_CONFIG=disable_cascading_set = false` を使っていました。`stable-11031` からコミュニティ版は削除され内蔵版が使われるため、その行は効果がありません。**アップグレード後は上の `JICOFO_ENABLE_AUTH=0` に切り替えてください。** そうしないとゲストが再びモデレーターになります。アップグレード後、ゲストで「録画開始」が表示されないこと、他の人を退出させられないことを確認してください。

設定後に `docker compose up -d` を実行します（prosody / jicofo が再作成されます）。jt-vc-portal 側では、ホストのトークンに `moderator: true`、ゲストのトークンに `moderator: false` が入る（本システムが自動で処理）ため、**ホスト = owner / モデレーター、ゲスト = member**（キック / 会議終了はできず、ロビーで待機させられる）となります。

> **ホストとゲストを別のドメイン / テナントに分ける必要はありません** — 同じ入口・同じ種類のトークンで、`moderator` フラグによって区別すれば十分です。
>
> 検証：ゲストとして会議に参加し、そのゲストの参加者パネル / 「⋯」メニューに「全員をミュート / 会議を終了 / キック」が**表示されない**ことを確認します。これらはホストにだけ表示されます。

### JWT 有効化後のアクセス動作（匿名アクセスはデフォルトで遮断）

JWT を有効にしても `https://meet.example.com/` のフロントエンドは読み込まれますが、**jt-vc-portal が発行したトークンがなければ、誰も会議室を作成・参加できません**（認証失敗が表示されます）。つまり **匿名での会議室作成はすでに遮断されており**、公式モバイルアプリがドメインに直接アクセスした場合も同様です。jt-vc-portal を経由した人（トークンを持つ人）だけが入れるため、**セキュリティ上の心配はありません**。

> **以下は純粋に見た目の問題であり任意です。行わなくてもセキュリティには影響しません。** 「`meet.example.com` に直接アクセスすると Jitsi の画面 / ランダムな会議室 / アプリのインストール案内が表示される」ことが気になり、meet を純粋なバックエンドにしたい場合にのみ変更してください：
>
> **(1) ウェルカムページを非表示にする**（`.env`）：`ENABLE_WELCOME_PAGE=0`。ただし無効にすると、`/` に直接アクセスした際にランダムな会議室名が自動生成され、スマートフォンでは「アプリで参加」のディープリンクページが引き続き表示されます（タップしても JWT によって遮断されます）。
>
> **(2) よりすっきりした方法 — ルートをポータルへリダイレクト**：web コンテナーに既にある `include /config/nginx-custom/*.conf;` を利用し、`/` だけをリダイレクトする設定を置きます（会議室の URL、`external_api.js`、IFrame 埋め込みには影響しません）：
>
> ```bash
> mkdir -p ~/.jitsi-meet-cfg/web/nginx-custom
> cat > ~/.jitsi-meet-cfg/web/nginx-custom/redirect-root.conf <<'EOF'
> location = / {
>     return 302 https://vc.example.com/;   # 自分の jt-vc-portal の URL に置き換える
> }
> EOF
> docker exec docker-jitsi-meet-web-1 nginx -s reload
> ```
>
> これで `meet.example.com` に直接アクセスするとポータルへリダイレクトされ、ポータル経由で埋め込まれたリクエスト（`/<room>` + `external_api.js`）だけが通常どおり処理されます。

### JWT を使わない場合（オープンモード）

`ENABLE_AUTH=0` に設定し、jt-vc-portal で「セルフホストで JWT が必要」を「いいえ」にするだけです。フロントエンドはトークンを送らず、会議室名を知っている人は誰でも参加できます。**安全性は低く、社内ネットワーク / テスト用途にのみ適しています。**

---

<br>
<br>
<br>
<br>
<br>
<br>

## 4. Jitsi の起動

```bash
docker compose up -d
docker compose ps
```

検証：ブラウザーで `https://meet.example.com/external_api.js` を開き、JS が返ってくることを確認します（jt-vc-portal は埋め込み時にこれを読み込みます）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 5. jt-vc-portal 側の設定

jt-vc-portal にログイン → **システム設定 → 接続モード設定** で「セルフホスト Jitsi Meet」に切り替え、以下を入力します：

| 項目 | 設定例 |
|---|---|
| モード | セルフホスト Jitsi Meet |
| サービスドメイン | `meet.example.com`（`https://` は付けない） |
| 本システムの公開 URL | `https://vc.example.com` |
| セルフホストで JWT が必要 | はい（`ENABLE_AUTH=1` に対応）/ いいえ（`ENABLE_AUTH=0`） |
| App ID | `jt-vc-portal`（= `JWT_APP_ID`） |
| app_secret（HS256） | （= `JWT_APP_SECRET`） |
| JWT sub | 空欄のまま（デフォルトで `*` を送信）。マルチテナント構成の場合のみテナント名を入力 |

保存後、jt-vc-portal から会議室を作成してホストとして開始すると、セルフホストの Jitsi が埋め込まれ、（有効な場合は）トークンが自動的に渡されます。

> **会議室左上のロゴ**：現在は Jitsi サーバーの `config.js` で一元的に設定します（ライブ会議と録画で一貫させるため）。[第 8 節](#8-ブランドロゴと録画者の非表示サーバーの-configjs)を参照してください。jt-vc-portal は IFrame でロゴを上書きしなくなり、「会議室のカスタマイズ」からもロゴの項目を削除しました。

> **以下はすべて会議参加時に jt-vc-portal が渡すため、Jitsi 側の変更は不要です：**
> - **「アプリで参加」ディープリンクの無効化**（`configOverwrite.disableDeepLinking = true`）：スマートフォンでポータル経由で参加すると、公式アプリのインストール / 起動ページへ飛ばずにそのままブラウザーで開きます（そのアプリは JWT のため接続できません）。
> - **会議室のカスタマイズ**（システム設定 → 会議室のカスタマイズ、セルフホストモード）：参加時ミュート / カメラオフ、最大映像品質、**デフォルト表示（スピーカー / ギャラリー）**、ツールバー各項目のオン / オフ。いずれも参加時に適用されます。
> - **ロビーモード**：会議室作成時にチェックすると、ホストが参加した時点（モデレーター権限を取得した後）で自動的に有効になり、ゲストは 1 人ずつ承認されないと入室できません。
> - **コーデックの優先順位**（`videoQuality.codecPreferenceOrder = VP9, H264, VP8, AV1`）：デスクトップのみの会議では VP9（低帯域でも高画質）を使い、**iPhone/iPad を含む会議ではハードウェア H.264 に自動で切り替わります**（iOS Safari は VP9 に対応しておらず、そうしないと最も画質の低い VP8 にフォールバックしてしまうため）。

> **モバイルでの視聴品質**：携帯回線のスマートフォンで相手の映像がぼやけるのは、主にモバイルの下り帯域 + 適応ビットレートによるものです（LAN 側は帯域に余裕があるため鮮明です）。VP9/H.264 ですでに可能な限り改善しています。これ以上良くするにはネイティブアプリ（本アーキテクチャでは JWT のため使えません）を使うか、メディアが TCP 中継ではなく UDP 10000 で直接流れるようにする必要があります。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 6. トラブルシューティング

| 症状 | 考えられる原因 / 対処 |
|---|---|
| `external_api.js` が 404 | `PUBLIC_URL` / HTTPS が正しく設定されていない。コンテナーと証明書が正常か確認 |
| 会議参加時にトークン / 認証エラー | App ID、`JWT_APP_SECRET`、`iss`、`aud` が一致していない。またはマルチテナント環境では「JWT sub」にテナント名の入力が必要（単一ドメインなら空欄で `*` を送信） |
| ロビーが効かない | `.env` に `ENABLE_LOBBY=1` が必要。かつ会議室作成時に「ロビーモード」をチェックする必要がある |
| 画面が真っ黒 / メディアが流れない | `10000/udp` が開放されていない、または NAT。`.env` に `JVB_ADVERTISE_IPS=<host-public-IP>` を設定 |
| 一部のゲスト（厳しいネットワーク）だけメディアがつながらない | そのゲストのネットワークが UDP を遮断 / 443 のみ許可 →「メディア経路の自動フォールバック」を参照し、TURN（coturn、turns/443 を含む）を用意 |
| ドメインを統一した体験にしたい | jt-vc-portal のアドレスバーは常に `vc.example.com` を表示します。`meet.example.com` は F12 / 接続の詳細でしか見えません（正常） |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 7. 録画（Jibri、任意）

会議の録画には **Jibri** を別途デプロイする必要があります（専用のリソースが必要で、1 インスタンスは同時に 1 つの会議しか録画できません）。**複数会議室の同時録画**や**録画での CJK 文字の表示（CJK フォント）**を含む完全な手順は **[JIBRI-SETUP_ja.md](JIBRI-SETUP_ja.md)** を参照してください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 8. ブランドロゴと録画者の非表示（サーバーの config.js）

この 2 つはどちらも **Jitsi サーバー自身の `config.js`** で設定する必要があります。理由は同じです：

- **Jibri の録画**は自前のブラウザーで **Jitsi サーバーに直接接続**し、**ポータルの IFrame を経由しません** — そのため、ポータルが渡す設定は録画には効かず、サーバーの `config.js` だけが「ライブ + 録画」の両方に効きます。
- 録画者の非表示（`hiddenDomain`）は IFrame の `configOverwrite` では確実に効かないため、サーバー側で設定するのが最も確実です。

docker-jitsi-meet のコンテナーは起動のたびに `~/.jitsi-meet-cfg/web/custom-config.js` と `custom-interface_config.js` を生成された設定の末尾に自動で**追記**します（web コンテナーの `/etc/cont-init.d/10-config` を参照）。そのため、この 2 ファイルを置けば設定は永続化されます。

**設定（2 ファイル）：**

```bash
# (1) config.js の上書き：左上ロゴ + 録画者の非表示
cat > ~/.jitsi-meet-cfg/web/custom-config.js <<'JS'
config.defaultLogoUrl = "https://vc.example.com/logo";   // 自分のポータル URL + /logo に置き換える
config.hiddenDomain   = "hidden.meet.jitsi";             // 録画者のログインドメイン。参加者リスト / 人数から除外
JS

# (2) interface_config.js の上書き：ウォーターマークのロゴ
cat > ~/.jitsi-meet-cfg/web/custom-interface_config.js <<'JS'
interfaceConfig.DEFAULT_LOGO_URL     = "https://vc.example.com/logo";
interfaceConfig.JITSI_WATERMARK_LINK = "https://vc.example.com";
interfaceConfig.SHOW_JITSI_WATERMARK = true;
JS
```

**適用（web コンテナーを再起動するため、ライブサービスが数秒間中断します）：**

```bash
docker restart docker-jitsi-meet-web-1
```

**ポイント：**

- `https://vc.example.com/logo` は自分の jt-vc-portal の公開 URL + `/logo` に置き換えてください（ポータルは「システム設定 → サイト設定」でアップロードしたロゴをここで PNG として返します。Jibri ホストからもこの URL に到達できる必要があります）。
- 以後、ポータルでロゴ画像を変更すると自動的に反映されます（URL は変わらないため、**Jitsi を再起動し直す必要はありません**）。
- `hidden.meet.jitsi` は docker-jitsi-meet の録画者ドメイン（`XMPP_RECORDER_DOMAIN`）で、通常はこの値です。`docker exec docker-jitsi-meet-prosody-1 grep -i VirtualHost /config/conf.d/*.lua` で確認できます。

**検証：**

```bash
docker exec docker-jitsi-meet-web-1 grep -E "defaultLogoUrl|hiddenDomain" /config/config.js
```

その後テスト録画を行い、再生時に左上にカスタムロゴが表示されている（Jitsi デフォルトのウォーターマークではない）こと、参加者リスト / 人数に録画者が**含まれていない**ことを確認します。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 9. Jitsi のアップグレード

アップグレード前に、**会議中の人・録画中のものがないこと**を確認してください（アップグレードで全会議が中断されます）：

```bash
docker exec docker-jitsi-meet-jicofo-1 curl -s http://127.0.0.1:8888/stats   # conferences と participants がどちらも 0 であること
```

設定をバックアップします（問題があれば元のバージョンに戻せるように）：

```bash
cd docker-jitsi-meet
tar czf ~/jitsi-backup-$(date +%Y%m%d).tgz .env docker-compose*.yml -C ~ .jitsi-meet-cfg
git describe --tags > ~/jitsi-backup-$(date +%Y%m%d).version     # ロールバック用に現在のバージョンを記録
```

アップグレード：

```bash
git fetch --tags
git checkout stable-<新バージョン>
docker compose pull
docker compose up -d
```

JWT と連携の設定は変更不要です（`custom-config.js` / `custom-interface_config.js` は保持され、自動的に再度追記されます）。

- **Jitsi ホストと Jibri ホストは同じバージョンに揃えてください**（[JIBRI-SETUP_ja.md](JIBRI-SETUP_ja.md) のアップグレードの節を参照）。バージョンが離れすぎると録画が接続できないことがあります。
- ロールバック：`git checkout <元のバージョン>` の後に `docker compose up -d`（旧イメージはホストに残っています）。
- アップグレード後、テスト会議を開いて 2 人で互いの声が聞こえること、短い録画が再生できることを確認してください。
- 本ガイドは `stable-11031` で検証しています。**`stable-11146` 以降は構造的な変更**です（ベースが Debian 13、コンテナが非 root 実行、イメージが GitHub Container Registry へ移動、web コンテナの内部ポートと WebSocket 設定が変更）。単なるバージョン変更ではないため、公式リリースノートを読み、テスト環境でリハーサルしてからアップグレードしてください。独自の `jibri-cjk` イメージも再確認が必要です。
