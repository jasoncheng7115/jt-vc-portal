# Jibri 録画 × セルフホスト Jitsi Meet セットアップ

> **作者**：Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　プロジェクト [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)
>
> English: [JIBRI-SETUP.md](JIBRI-SETUP.md) · 繁體中文: [JIBRI-SETUP_zh-TW.md](JIBRI-SETUP_zh-TW.md)

本ガイドは [JITSI-MEET-SETUP_ja.md](JITSI-MEET-SETUP_ja.md) の続きとして、同じ docker-jitsi-meet 環境に **Jibri による録画** を追加します。特に次の 2 点に重点を置いています：**複数の会議室を同時に録画すること**、そして **録画内の中国語（CJK）テキストを正しく表示すること** です。

> 対象バージョン：docker-jitsi-meet / `jitsi/jibri` **`stable-10888`**。
> 本ガイドは **同時録画 2 本（Jibri コンテナ 2 台）** を前提に書かれています。本数を増減する場合は、本文中の「2」をすべて合わせて調整してください。

---

## 目次

**計画**

- [デプロイ構成（Jibri 専用 VM を推奨）](#デプロイ構成jibri-専用-vm-を推奨)
- [1. Jibri の概要、制限とリソース](#1-jibri-の概要と制限)

**インストール（同一ホスト構成、最も始めやすい）**

- [2. ホストの前提条件：snd-aloop](#2-ホストvmの前提条件snd-aloop-のロード同時録画数を決定)
- [3. 録画の有効化（`.env`）](#3-録画の有効化docker-jitsi-meet-env)
- [4. 録画機の増減](#4-録画機の増減)
- [5. 録画内の中国語テキスト（必須）](#5-録画内の中国語cjkテキスト必須)
- [6. 録画ファイルと再生（jibri-recordings-api）](#6-録画ファイルと再生jibri-recordings-api)

**運用 / 応用**

- [7. トラブルシューティング](#7-トラブルシューティング)
- [8. アップグレード SOP](#8-アップグレード-sop)
- [9. Jibri 専用 VM（Jitsi と分離）](#9-jibri-専用-vmjitsi-と分離手順)

---

<br>
<br>
<br>
<br>
<br>
<br>

## デプロイ構成（Jibri 専用 VM を推奨）

| 規模 | 推奨 |
|---|---|
| テスト / 小規模、ときどき 1 会議を録画する程度 | Jibri と Jitsi を **同じ VM** に置く（同じ docker-compose スタック。最も簡単で、本ガイドの既定手順） |
| 本番 / 複数コンテナ（本ガイドでは 2 台以上） | **Jibri を専用 VM（または複数 VM）に置き**、Jitsi ホストと分離する |

**本番で分離する理由：**

1. **リソースの分離（最重要）**：Jibri は headless Chrome + ffmpeg で構成され、CPU / RAM を大量かつ突発的に消費します。prosody / jicofo / JVB とホストを共有すると、録画が混み合ったときに **すべての** 会議の品質が低下するおそれがあります。分離しておけば、Jibri が CPU を使い切っても影響を受けるのは録画だけで、会議には影響しません。
2. **カーネル要件が Jibri VM だけに限定される**：`snd-aloop` と「LXC ではなく VM であること」が必要なのは Jibri だけで、Jitsi のコアサービスにはこの制約がありません。分離すれば、カーネルモジュールを扱う必要があるのは Jibri 側のマシンだけになります。
3. **独立したスケーリング**：同時録画数を増やしたいときは、Jitsi ホストに手を加えず Jibri VM を追加するだけで済みます。

**分離の方法：** Jibri はメインスタックの prosody に **XMPP で** 接続します（ネットワーク的に到達できればよく、同じホストである必要はありません）。専用の Jibri VM でも本ガイドの手順（snd-aloop + jibri コンテナ）に従いますが、`.env` の `XMPP_SERVER` / `XMPP_*_DOMAIN` / `JIBRI_*` は **メインの Jitsi ホスト** を指し、その値と一致させる必要があります（docker-jitsi-meet の「スタンドアロン Jibri」方式）。

> 第 3〜4 節は、最も始めやすい「同一ホスト」構成を前提に書かれています。**「Jibri 専用 VM」（Jitsi と分離、推奨される本番構成）の完全な手順は [第 9 節](#9-jibri-専用-vmjitsi-と分離手順) を参照してください。** 第 2 節（snd-aloop）と第 5 節（CJK フォント）はどちらの構成でも必須です。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 1. Jibri の概要と制限

- Jibri（Jitsi Broadcasting Infrastructure）は **headless Chrome** として会議に参加し、**ffmpeg** で映像と音声をキャプチャして `.mp4` に保存します（または RTMP でライブ配信します）。
- **1 台の Jibri コンテナが同時に録画できる会議は 1 つだけ** です。本ガイドは **同時録画 2 本 = Jibri コンテナ 2 台** を前提としています。
- 音声のキャプチャには ALSA ループバック（`snd-aloop`）の仮想サウンドデバイスが必要で、**同時に動く Jibri ごとに専用のループバックデバイスが 1 つ必要** です（録画 2 本 → デバイス 2 つ）。
- Jibri はリソースを多く消費します。1 本あたりおおよそ 1〜2 vCPU + 1〜2 GB RAM（headless Chrome + ffmpeg）です。**同時 2 本なら 4 vCPU / 8 GB を推奨** します（下記「VM リソースの目安」の表を参照）。

### 「コンテナ（LXC）」ではなく「VM」で動かす必要がある

Jibri はホストカーネルに **`snd-aloop` モジュールをロード** させる必要があり、さらに **`/dev/snd`** へのアクセスも必要です。これはホストカーネルを共有するコンテナ（Proxmox LXC などの OS レベルのコンテナ）の中では **実現できません**。したがって：

- **「Docker を動かす Jibri ホスト」は VM（独自のカーネルを持つ KVM / 完全仮想マシン）にし**、その VM の中で Docker を使って 2 台の Jibri コンテナを動かします。
- 例：Proxmox → **VM** を作成（LXC ではない）→ Linux + Docker をインストール → VM 内で `modprobe snd-aloop` を実行。
- 「コンテナ内では動かせない」とは、Jibri ホスト自体が LXC であってはならないという意味です。Jibri サービス自体は VM 内の Docker コンテナとして動かして構いません（VM は独自のカーネルを持つため、モジュールをロードしてコンテナに `/dev/snd` を渡せます）。

### VM リソースの目安（本ガイドの目標「同時録画 2 本」向け）

| 項目 | 推奨 | 備考 |
|---|---|---|
| vCPU | **4 コア**（最低 3、余裕を持つなら 6） | Jibri は headless Chrome + ffmpeg エンコードで CPU 負荷が非常に高く、1 本あたり約 1〜2 vCPU |
| RAM | **8 GB**（最低 4） | Chrome + ffmpeg で 1 本あたり約 1〜2 GB、これにシステムの余裕分を加算 |
| システムディスク | **20〜30 GB** | OS + Docker + Jibri/Chrome イメージで約 10〜15 GB |
| 録画用ストレージ | **別途用意。専用ディスク / NFS を推奨（100 GB 以上）** | 下記の容量見積もりを参照。`finalize.sh` で自動的に別の場所へ移動し、ローカルのコピーを削除することも可能 |
| 音声 | **snd-aloop ×2**（1 本につきループバックカード 1 枚） | LXC ではなく VM であること（上記参照） |

**録画容量の見積もり（見落としがち）**：1080p30 H.264 でおおよそ **0.5〜1 GB / 時間 / 本** です。「同時録画本数 × 1 会議あたりの時間 × 保持する件数」で見積もってください。例：2 本をそれぞれ 2 時間録画すると約 2〜4 GB。長期保存する場合は、録画を専用の大容量ディスク、NAS、またはオブジェクトストレージに置いてください。

**その他の注意点**：
- **ネットワーク**：Jibri は録画のために会議全体を「ダウンロード」するため、Jitsi / JVB との間に安定した帯域が必要です。同じサブネットに置くのが理想です。
- **CPU タイプ（Proxmox）**：Jibri は `snd-aloop` カーネルモジュールに依存するため、ライブマイグレーションの価値はあまりありません。`host` と `x86-64-v2-AES` のどちらでもよく、オフラインマイグレーションは問題ありません。
- **本数を増やす場合**：vCPU / RAM、`snd-aloop` デバイス数（第 2 節）、`--scale jibri=N`（第 4 節）を比例して一緒に増やしてください（例：3 本 ≈ 6 vCPU / 12 GB）。
- **最小構成**（ときどき 1 本録画する程度）：2 vCPU / 4 GB / システムディスク 20 GB + 録画用ストレージ。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 2. ホスト（VM）の前提条件：snd-aloop のロード（同時録画数を決定）

Jibri は ALSA の **ループバック仮想サウンドカード**（`snd-aloop`）を使って会議の音声を ffmpeg に流します。**同時録画 1 本ごとに専用のループバックカードが 1 枚必要** なので、録画 2 本ならカード 2 枚が必要です。この作業は **Jibri VM の OS レベル** で行います（コンテナ内ではありません）。

### 1) カーネルに snd-aloop モジュールがあるか確認する

```bash
modinfo snd-aloop >/dev/null 2>&1 && echo "OK: module present" || echo "Module missing, install it"
```

最小構成のクラウド / サーバー用イメージにはこのモジュールが含まれていないことが多いため、先にインストールしてから続けてください（Debian / Ubuntu）：

```bash
sudo apt-get update
sudo apt-get install -y "linux-modules-extra-$(uname -r)" alsa-utils
```

### 2) 起動時にロード + カード枚数を固定（永続化）

```bash
# (a) 起動時に snd-aloop を自動ロード
echo 'snd-aloop' | sudo tee /etc/modules-load.d/snd-aloop.conf

# (b) ループバックカードを 2 枚に固定（同時録画 1 本ごとに「1」とインデックスを 1 つずつ並べる）
echo 'options snd-aloop enable=1,1 index=0,1' | sudo tee /etc/modprobe.d/snd-aloop.conf
```

- `enable=1,1`：カードを 2 枚有効化。`index=0,1`：それぞれに 0 と 1 の番号を割り当てる。
- 録画 3 本なら `enable=1,1,1 index=0,1,2`、以下同様です。

### 3) 再起動（推奨）

> **上の 2 つのファイルを編集したら、一度再起動してください。**
> 理由：作業を始める前に、`snd-aloop` がすでにシステムによって（オプションなし / 誤ったカード枚数で）ロードされている可能性が十分にあります。モジュールが「すでにメモリ上にある」間は、`modprobe ... enable=...` で渡したオプションは無視されます。再起動すれば、`/etc/modprobe.d` の正しいカード枚数で **クリーンに** ロードされることが保証されます。

```bash
sudo reboot
```

#### 再起動せずにすぐ反映する（代替手段）

いったんアンロードしてから、オプション付きで再ロードします（再起動不要）：

```bash
sudo modprobe -r snd-aloop 2>/dev/null   # アンロード。"in use" と表示される場合は何かが使用中 -> 代わりに再起動する
sudo modprobe snd-aloop enable=1,1 index=0,1
```

### 4) 確認（再起動または再ロード後）

```bash
lsmod | grep snd_aloop        # snd_aloop がロードされているはず
cat /proc/asound/cards        # "Loopback" カードが 2 枚（index 0, 1）表示されるはず
```

**Loopback カードが 2 枚** 表示されれば成功です。

> 増減する場合：ここの `enable=` / `index=` を第 4 節の `--scale jibri=` と一緒に調整し、再起動（または上記の代替手段で再ロード）してください。
> `modprobe` でモジュールが見つからない、または権限がないと表示され、パッケージをインストールしても失敗する場合、このマシンはほぼ確実に **VM ではなく LXC コンテナ** です。第 1 節を参照し、VM を使用してください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 3. 録画の有効化（docker-jitsi-meet `.env`）

> **prosody / jicofo / jibri の設定ファイルを手作業で編集する必要はもうありません。** 旧来の「パッケージ版（apt install）」では、`prosody` の recorder vhost、jibri の brewery MUC、`jicofo` のプロパティ、`jibri.conf`、`prosodyctl register` などを手動で編集する必要がありましたが、**Docker 版ではこれらすべてがコンテナ起動時に環境変数から自動生成されます**。設定するのは `.env` の変数だけで、ホスト側に残る作業は `snd-aloop`（第 2 節。カーネルレベルの機能でありコンテナからは提供できないため）のみです。

```ini
ENABLE_RECORDING=1

# アカウント名は env.example の既定値（recorder / jibri）のままで構いません。パスワードは gen-passwords.sh が生成します：
#   JIBRI_RECORDER_PASSWORD, JIBRI_XMPP_PASSWORD（./gen-passwords.sh 実行後に .env に書き込まれる）
# JIBRI_RECORDER_USER=recorder
# JIBRI_XMPP_USER=jibri

# タイムゾーン（録画ファイル名 / 埋め込みタイムスタンプ / ログの時刻に影響。全コンテナ共通）
TZ=Asia/Taipei

# 録画の出力先ディレクトリ（コンテナ内のパス。ホストのボリュームにマッピングされる）
JIBRI_RECORDING_DIR=/config/recordings
# 録画後に実行するスクリプト（任意：オブジェクトストレージへのアップロード / 通知送信など。未設定ならファイルを保存するだけ）
# JIBRI_FINALIZE_RECORDING_SCRIPT_PATH=/config/finalize.sh
```

Jibri サービスは、`jibri.yml` を追加で重ねて起動します（docker-jitsi-meet の慣例）：

```bash
docker compose -f docker-compose.yml -f jibri.yml up -d
```

Jibri コンテナはホストの `/dev/snd` へのアクセスを必要とし（jibri.yml ですでに `devices: /dev/snd` が設定済み）、前の手順でロードした snd-aloop モジュールに依存します。

### 録画の出力先（ホスト側）

- 既定では、jibri の設定 / 録画ボリュームはホストの **`~/.jitsi-meet-cfg/jibri`** → コンテナの `/config` にマウントされ、録画はホストの **`~/.jitsi-meet-cfg/jibri/recordings/`** 以下に保存されます。
- 会議ごとに専用のサブフォルダ（`.mp4` とメタデータを含む）が作られます。ファイル名 / 時刻は `TZ` の設定に従います。
- 別の場所に保存したい場合：`jibri.yml` でそのボリュームにマッピングするホスト側のパスを変更します（例：大容量ディスク / NFS のマウントポイント）。コンテナ内の `JIBRI_RECORDING_DIR` は `/config/recordings` のままにしてください。
- `CONFIG` 変数（docker-jitsi-meet の `.env`、既定値 `~/.jitsi-meet-cfg`）は、全コンポーネントの設定 / データのホスト側ルートディレクトリを指定します。これを変更すればまとめて移動できます。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 4. 録画機の増減

「録画機」= jibri コンテナ 1 台で、**1 台のコンテナが同時に録画できる会議は 1 つだけ** です。N 件の会議を同時に録画する = **snd-aloop ループバックカード N 枚 + jibri コンテナ N 台** で、この 2 つの数は一致している必要があります。

> 以下のコマンドは **同一ホスト構成**（`-f docker-compose.yml -f jibri.yml`）を例にしています。**Jibri 専用 VM** の場合は、compose の引数を `-f docker-compose.jibri-standalone.yml` に置き換えてください（[第 9 節](#9-jibri-専用-vmjitsi-と分離手順) を参照）。

### (1) ループバックカードの枚数を設定する（録画数の上限を決定）

これは **Jibri VM の OS レベル** で設定します（コンテナ内ではありません）。同時録画 1 本につきカード 1 枚です。2 枚の例：

```bash
echo 'snd-aloop' | sudo tee /etc/modules-load.d/snd-aloop.conf
echo 'options snd-aloop enable=1,1 index=0,1' | sudo tee /etc/modprobe.d/snd-aloop.conf
sudo modprobe -r snd-aloop 2>/dev/null && sudo modprobe snd-aloop enable=1,1 index=0,1   # "in use" は録画中を意味する -> 代わりに再起動する
cat /proc/asound/cards          # Loopback カードが 2 枚表示されるはず
```

録画 3 本なら `enable=1,1,1 index=0,1,2`、以下同様です。変更後に一度再起動するのが最も確実です（モジュールがすでにロードされている間はオプションが無視されます）。

### (2) jibri コンテナを追加 / 台数を設定する（カード枚数がすでに N 以上であること）

```bash
cd docker-jitsi-meet
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=2
```

### (3) コンテナの台数を減らす（余分なものは停止される）

```bash
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=1
```

### (4) 停止 / 再起動（設定と録画は保持される）

```bash
docker compose -f docker-compose.yml -f jibri.yml stop jibri      # すべて停止
docker compose -f docker-compose.yml -f jibri.yml start jibri     # 再度起動
docker compose -f docker-compose.yml -f jibri.yml restart jibri   # 再起動（stop + start）
```

### (5) 確認

```bash
docker compose -f docker-compose.yml -f jibri.yml ps              # 対応する台数の jibri コンテナが一覧され、すべて running であるはず
```

各 jibri コンテナはループバックカードを 1 枚ずつ使用します。jicofo は各録画リクエストを「空いている」jibri に割り当てるため、同時録画の最大数 = min(カード枚数, コンテナ台数) となります。

> 3 つをすべて同じ数に保ってください：`snd-aloop カード枚数 = jibri コンテナ台数 = 希望する同時録画数`。どれかが不足すると、超過した録画リクエストは Jibri を確保できず、失敗するか保留のままになります。増減時は両方を一緒に調整し、VM のリソースが十分であること（1 本あたり約 1〜2 vCPU + 1〜2 GB）を確認してください。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 5. 録画内の中国語（CJK）テキスト（必須）

Jibri は headless Chrome を使って「会議画面を録画」します。**公式の `jitsi/jibri` イメージには CJK フォントが含まれていない** ため、中国語の名前 / チャット / 字幕が録画内で空の四角（□、いわゆる「豆腐」）として表示されます。jibri イメージに CJK フォントを追加する必要があります。

拡張イメージをビルドします：

```dockerfile
# jibri-cjk/Dockerfile
FROM jitsi/jibri:stable-10888
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      fonts-noto-cjk fonts-noto-cjk-extra fonts-noto-color-emoji \
 && fc-cache -f \
 && rm -rf /var/lib/apt/lists/*
# 重要：USER jibri に戻さないこと。jibri イメージは s6 を root で起動します（サービスについては自身で jibri に権限を落とします）。
# 最後に USER jibri を追加すると、コンテナは "s6-mkdir: /var/run/s6: Permission denied" で失敗し、再起動を繰り返します。
```

ビルドして compose で使用するようにします：

```bash
docker build -t jibri-cjk:stable-10888 ./jibri-cjk
```

`jibri.yml`（または `docker-compose.override.yml`）で、jibri サービスの `image:` を `jibri-cjk:stable-10888` に変更し、再起動します。

> 確認：中国語の参加者名を含む会議を録画し、再生時に中国語が正しく表示される（豆腐の四角がない）ことを確認してください。Noto CJK は繁体字中国語のカバー範囲が最も充実しています。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 6. 録画ファイルと再生（jibri-recordings-api）

### 録画の出力先

録画は、管理しやすい専用のパス（ホームディレクトリ内の隠しフォルダではなく）に書き出すことを推奨します。jibri サービスの compose ファイルで、録画ボリュームを `/srv/recordings` にマッピングします：

```yaml
    volumes:
      - ${CONFIG}/jibri:/config:Z
      - /srv/recordings:/config/recordings
```

コンテナ内の `JIBRI_RECORDING_DIR` は `/config/recordings` のままにします。各録画には専用のサブフォルダ（UUID）が作られ、`<room>_<time>.mp4` と `metadata.json` が格納されます。

### 録画再生サービス

同梱の `jibri-recordings-api`（Python 標準ライブラリのみ、サードパーティパッケージ不要）により、jt-vc-portal から録画をオンラインで **一覧 / 再生 / ダウンロード / 削除** でき、**ホストのストレージ容量** の表示や **保持ポリシー** の適用も行えます。

1) プログラムをインストールします（`server.py` は本リポジトリの `jibri-recordings-api/` ディレクトリにあります）：

```bash
sudo mkdir -p /opt/jibri-recordings-api
sudo cp jibri-recordings-api/server.py /opt/jibri-recordings-api/server.py
```

2) まずランダムなトークンを生成します（出力された文字列をコピーします）：

```bash
openssl rand -hex 32
```

3) 環境ファイル `/etc/jibri-recordings-api.env` を作成し、前の手順の出力を `API_TOKEN=` の後ろに **貼り付け** ます（これは systemd の EnvironmentFile でありシェルスクリプトではないため、`$(...)` は **使えません**。実際の文字列を記入してください）：

```bash
REC_DIR=/srv/recordings
API_TOKEN=<前の手順で生成したランダム文字列を貼り付け>
ALLOW_IPS=<ポータルホストの IP>,127.0.0.1
PORT=9080
```

権限を設定します：`chmod 600 /etc/jibri-recordings-api.env`。

> **セキュリティ上の注意：** このサービスは平文の HTTP で通信し、Bearer トークンで認証するため、ポータルと Jibri ホストの間ではトークンが暗号化されずに流れます。この通信は信頼できる内部ネットワーク内にとどめ、さらにホストのファイアウォールで 9080 番ポートを制限し、ポータルホストからのみ到達できるようにしてください（`ALLOW_IPS` はアプリケーションレベルのチェック、ファイアウォールはネットワークレベルのチェックです）。2 台のホストが信頼できないネットワークを介して通信する場合は、サービスの前段に TLS リバースプロキシ（例：nginx）を置き、ポータルの設定では `https://` の URL を使用してください。

4) systemd サービス `/etc/systemd/system/jibri-recordings-api.service`：

```ini
[Unit]
Description=Jibri Recordings API (portal-only)
After=network.target

[Service]
EnvironmentFile=/etc/jibri-recordings-api.env
ExecStart=/usr/bin/python3 /opt/jibri-recordings-api/server.py
Restart=always
RestartSec=3
# サンドボックス：書き込み可能なのは /srv/recordings のみ（クリーンアップ / 削除 / 設定ファイル）
ProtectSystem=strict
ReadWritePaths=/srv/recordings
ProtectHome=true
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

5) 有効化します：

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now jibri-recordings-api
systemctl is-active jibri-recordings-api
```

6) ポータルで **システム設定 → 録画設定 → Jibri 録画サービス** を開き、`http://<jibri ホストの IP>:9080` と上記のトークンを入力して「保存してテスト」をクリックします。「接続済み」と表示されれば、ナビゲーションバーに「録画記録」タブが表示されます。

> **セキュリティ**：このサービスは「送信元 IP 許可リスト + Bearer トークン」の 2 要素で検証し、ポータルからの接続のみを受け付けます。ポータル側でも、ストリームをプロキシする前に管理者ログインを要求します。`ProtectSystem=strict` + `ReadWritePaths=/srv/recordings` により、サービスが読み書きできるのは録画ディレクトリのみに制限されます。

### 保持ポリシー（既定ではすべて無効）

ポータルの録画設定カードで設定します。設定はサービスに送られ、バックグラウンドスレッドによって 1 時間ごとに適用されます：

- **期間**：直近 N 日分を保持し、それより古い録画は自動的に削除されます。
- **容量**：最低限の空き容量を確保する（`min_free_gb`）か、録画の合計使用量に上限を設けます（`max_used_gb`）。どちらも古いものから削除します。
- **残骸のクリーンアップ**：異常終了した会議が残した未完成の録画 / 断片を N 時間後に削除します。
- 録画中のファイル（直近 5 分以内にまだ書き込まれているもの）は **決してクリーンアップされません**。クリーンアップの操作は `/srv/recordings/.api-cleanup.log` に記録されます。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 7. トラブルシューティング

| 症状 | 対処 |
|---|---|
| 録画をクリックしても何も起きない / 保留のまま | jibri が動いていない、`snd-aloop` がロードされていない、または空いている jibri がない（すべて録画中）→ ループバックカードを追加し、jibri をスケールする |
| 録画内の中国語が四角 / 文字欠けになる | jibri イメージに CJK フォントがない → 第 5 節の `jibri-cjk` イメージを使用する |
| 2 つの会議を録画できない / 2 つ目が録画機を確保できない | `snd-aloop` デバイスまたは jibri コンテナが 2 未満 → 第 2 節と第 4 節に従って両方を 2 に増やす（リソースも十分であること） |
| `modprobe snd-aloop` が失敗する | このマシンは VM ではなく LXC コンテナ → VM を使用する（第 1 節を参照） |
| 録画ファイルが真っ黒 / 無音 | `/dev/snd` がコンテナにマウントされていない、snd-aloop の不具合、またはホストのリソース不足 |
| ログに `Failed to run finalize script /path/to/finalize` と表示される | **無害** です。finalize スクリプトを設定していない場合の既定のプレースホルダーパスで、録画停止時に実行を試みて失敗しますが、**録画の出力には影響しません**。表示を消したい場合は、`JIBRI_FINALIZE_RECORDING_SCRIPT_PATH` に実在するスクリプト（または空の `.sh`）を設定してください。 |
| 録画がいつまでも「録画中」のまま | ポータルは `metadata.json` の有無で録画が完了したかどうかを判定します（Jibri は finalize の後にのみこれを書き込みます）。finalize が失敗して書き込まれなかった場合、「録画中」のままになります → jibri のログで finalize のエラーを確認する |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 8. アップグレード SOP

アップグレード時、Jibri は Jitsi と同じ `stable-<version>` に揃える必要があります。第 5 節のカスタム `jibri-cjk` イメージを使っている場合は、**新しいバージョン番号で再ビルドすることを忘れないでください**（そうしないと CJK フォント入りイメージが旧バージョンのまま残ります）。`snd-aloop`（第 2 節）はバージョンに依存しないため、やり直す必要はありません。

```bash
cd docker-jitsi-meet

# (1) 設定と既存の録画をバックアップ
cp -a ~/.jitsi-meet-cfg ~/.jitsi-meet-cfg.bak-$(date +%Y%m%d)

# (2) 新しいバージョンを取得（対象のタグに置き換える）
git fetch --tags
git checkout stable-<new-version>
#   .env に JITSI_IMAGE_VERSION がある場合は、stable-<new-version> と一致していることを確認する

# (3) CJK 版 jibri イメージを再ビルド（新しいバージョン番号で）
sed -i 's/stable-[0-9]*/stable-<new-version>/' jibri-cjk/Dockerfile
docker build -t jibri-cjk:stable-<new-version> ./jibri-cjk
#   jibri.yml / override の jibri サービスの image: も jibri-cjk:stable-<new-version> に変更する

# (4) 残りの公式イメージを取得して再起動（jibri コンテナは 2 台のまま）
docker compose -f docker-compose.yml -f jibri.yml pull
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=2
```

アップグレード後の確認：

```bash
docker compose -f docker-compose.yml -f jibri.yml ps     # web/prosody/jicofo/jvb + jibri 2 台がすべて Up
```

- 会議を開始して録画をクリック → 録画できること、そして **中国語が豆腐の四角にならないこと**（第 5 節）を確認します。
- 2 つの会議室を同時に録画 → 同時録画（第 4 節）がアップグレードの影響を受けていないことを確認します。

> 本ガイドは `stable-10888` を基準にしています。上記の `<new-version>` をアップグレード先のタグに置き換えるだけです。
> `snd-aloop` はカーネルモジュールでありイメージのバージョンとは無関係なので、アップグレード時に再設定する必要はありません（VM を再インストールした場合を除く）。

---

<br>
<br>
<br>
<br>
<br>
<br>

## 9. Jibri 専用 VM（Jitsi と分離）：手順

本番環境では Jibri 専用の VM を推奨します（「デプロイ構成」を参照）。以下は実機で検証済みの完全な手順で、次の前提に基づきます：

- メインの Jitsi ホスト：`10.0.0.10`（docker-jitsi-meet は `/opt/docker-jitsi-meet`、公開 URL は `meet.example.com`）。
- Jibri VM：別のマシンで、**LXC ではなく KVM**。同じサブネット上にあり、メインホストに到達できること。

### 9-1 メインの Jitsi ホスト（`10.0.0.10`）での作業

```bash
cd /opt/docker-jitsi-meet
# (1) 録画を有効化
sed -i 's/^#\?ENABLE_RECORDING=.*/ENABLE_RECORDING=1/' .env || echo "ENABLE_RECORDING=1" >> .env

# (2) prosody の c2s ポート 5222 を Jibri VM に開放する（スタンドアロン Jibri はここに接続する）
#     docker-compose.override.yml で公開し、ホストの LAN IP にバインドする（外部には公開しない）
cat >> docker-compose.override.yml <<'EOF'
  prosody:
    ports:
      - "10.0.0.10:5222:5222"
EOF
#     注意：override に書ける services: ブロックは 1 つだけ。ほかのサービスがすでにある場合は prosody をそこにまとめる

docker compose up -d prosody jicofo     # 録画 + 5222 を反映
```

Jibri VM で必要になる値を控えておきます（**パスワードを漏らさないこと**）：

```bash
grep -E '^JIBRI_XMPP_PASSWORD=|^JIBRI_RECORDER_PASSWORD=' .env   # 2 つのパスワード。後ほど Jibri VM にコピーする
# XMPP ドメイン（イメージの既定値で問題なし。このバージョンでは次のとおり）：
#   XMPP_DOMAIN=meet.jitsi  AUTH=auth.meet.jitsi  INTERNAL_MUC=internal-muc.meet.jitsi
#   RECORDER(hidden)=hidden.meet.jitsi   brewery=jibribrewery
# prosody から確認可能：docker exec docker-jitsi-meet-prosody-1 sh -c 'grep -E "^VirtualHost|^Component" /config/conf.d/jitsi-meet.cfg.lua'
```

### 9-2 Jibri VM での作業

1) まず **第 2 節（snd-aloop ×N）** を完了し、Docker をインストールします。

2) リポジトリを取得して `.env` を作成します（XMPP はメインホストを指し、パスワードはメインホストと一致させる）：

```bash
cd /opt && git clone https://github.com/jitsi/docker-jitsi-meet.git
cd docker-jitsi-meet && git checkout stable-10888
cp env.example .env && ./gen-passwords.sh        # まず全項目を埋める
mkdir -p ~/.jitsi-meet-cfg/jibri/recordings

# 設定（自分のホスト IP / ドメインに置き換える。JIBRI_*_PASSWORD はメインホストの 2 つの値にする）
cat >> .env <<'EOF'
PUBLIC_URL=https://meet.example.com
TZ=Asia/Taipei
ENABLE_RECORDING=1
XMPP_SERVER=10.0.0.10
XMPP_PORT=5222
XMPP_TRUST_ALL_CERTS=1
XMPP_DOMAIN=meet.jitsi
XMPP_AUTH_DOMAIN=auth.meet.jitsi
XMPP_INTERNAL_MUC_DOMAIN=internal-muc.meet.jitsi
XMPP_MUC_DOMAIN=muc.meet.jitsi
XMPP_RECORDER_DOMAIN=hidden.meet.jitsi
JIBRI_BREWERY_MUC=jibribrewery
JIBRI_XMPP_USER=jibri
JIBRI_RECORDER_USER=recorder
JIBRI_RECORDING_DIR=/config/recordings
JIBRI_XMPP_PASSWORD=<メインホストの JIBRI_XMPP_PASSWORD を貼り付け>
JIBRI_RECORDER_PASSWORD=<メインホストの JIBRI_RECORDER_PASSWORD を貼り付け>
EOF
```

> **ポイント**：`XMPP_SERVER` はメインホストを指し、`XMPP_TRUST_ALL_CERTS=1`（prosody の内部証明書は自己署名のため）、`JIBRI_*_PASSWORD` はメインホストと **完全に** 一致させる必要があります（jibri / recorder アカウントはメインホストの prosody に登録されています）。XMPP ドメインはメインホストと同じです。

3) CJK イメージをビルドします（第 5 節。**`USER jibri` を追加しないよう注意**）。

4) スタンドアロン用の compose ファイルを作成します（jibri のみを動かし、専用の `/dev/snd` と `extra_hosts` を持たせる）：

```yaml
# docker-compose.jibri-standalone.yml
services:
  jibri:
    image: jibri-cjk:stable-10888
    restart: unless-stopped
    volumes:
      - ${CONFIG}/jibri:/config:Z
    shm_size: "2gb"
    cap_add: [ SYS_ADMIN ]
    devices: [ "/dev/snd:/dev/snd" ]
    extra_hosts:
      - "meet.example.com:10.0.0.10"   # 録画用 Chrome がメインホストの内部 IP に名前解決するようにする
    environment:
      - PUBLIC_URL
      - TZ
      - XMPP_SERVER
      - XMPP_PORT
      - XMPP_TRUST_ALL_CERTS
      - XMPP_DOMAIN
      - XMPP_AUTH_DOMAIN
      - XMPP_INTERNAL_MUC_DOMAIN
      - XMPP_MUC_DOMAIN
      - XMPP_RECORDER_DOMAIN
      - JIBRI_XMPP_USER
      - JIBRI_XMPP_PASSWORD
      - JIBRI_RECORDER_USER
      - JIBRI_RECORDER_PASSWORD
      - JIBRI_BREWERY_MUC
      - JIBRI_RECORDING_DIR
      - DISPLAY=:0
```

5) 録画機を 2 台起動します：

```bash
docker compose -f docker-compose.jibri-standalone.yml up -d --scale jibri=2
```

### 9-3 確認

```bash
# Jibri VM：2 台のコンテナがどちらも running で、ログに "Joined MUC: jibribrewery@internal-muc.meet.jitsi" が表示される
docker compose -f docker-compose.jibri-standalone.yml ps
docker logs <jibri-container> 2>&1 | grep -E "Authenticated|Joined MUC"

# メインホストの jicofo：brewery に jibri インスタンスが 2 つあり、available = true と表示されるはず
docker logs docker-jitsi-meet-jicofo-1 2>&1 | grep -i "brewery instance"
```

最後に会議を開始し、録画をクリックして実際にテストします（中国語が正しく表示され、2 つの会議室を同時に録画できること）。

> よくある落とし穴：① CJK の Dockerfile の最後に誤って `USER jibri` を追加する → コンテナが再起動を繰り返す（s6 の権限）。② `JIBRI_*_PASSWORD` がメインホストと一致しない → ログに認証失敗が表示される。③ メインホストの prosody の 5222 番ポートが Jibri VM に開放されていない → 接続できない。④ Jibri VM が `meet.example.com` を内部 IP に名前解決できない → `extra_hosts` または内部 DNS で解決する。
