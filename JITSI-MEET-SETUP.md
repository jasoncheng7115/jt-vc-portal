# Self-Hosted Jitsi Meet × jt-vc-portal Integration Setup

> 繁體中文: [JITSI-MEET-SETUP_zh-TW.md](JITSI-MEET-SETUP_zh-TW.md) · 日本語: [JITSI-MEET-SETUP_ja.md](JITSI-MEET-SETUP_ja.md)

> **Author**: Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　Project [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

This document explains how to configure an **official Docker-based Jitsi Meet** deployment so it works together with the jt-vc-portal "authentication gateway".

> Applies to: [docker-jitsi-meet](https://github.com/jitsi/docker-jitsi-meet) **`stable-11031`** (released 2026-06-08). The steps are the same for other stable releases; just substitute the version number.

---

## Table of Contents

**Concepts**

- [Architecture Overview](#architecture-overview)
- [Prerequisites](#prerequisites)
- [Ports and NAT Configuration (incl. Media Fallback / TURN)](#ports-and-nat-configuration)

**Installation**

- [1. Get the Official docker-jitsi-meet](#1-get-the-official-docker-jitsi-meet)
- [2. Basic External Settings (`.env`)](#2-basic-external-settings-edit-env)
- [3. Enable JWT Authentication (Recommended)](#3-enable-jwt-authentication-recommended)
- [4. Start Jitsi](#4-start-jitsi)
- [5. jt-vc-portal Settings](#5-jt-vc-portal-settings)
  - [Bandwidth reference (computers, server, phones)](#bandwidth-reference-computers-server-phones)

**Advanced / Operations**

- [6. Troubleshooting](#6-troubleshooting)
- [7. Recording (Jibri)](#7-recording-jibri-optional)
- [8. Branding Logo and Hidden Recorder](#8-branding-logo-and-hidden-recorder-server-configjs)
- [9. Upgrading Jitsi](#9-upgrading-jitsi)

---

<br>
<br>
<br>
<br>
<br>
<br>

## Architecture Overview

```
User ──▶ jt-vc-portal (vc.example.com) ──── embedded IFrame ────▶ Self-hosted Jitsi Meet (meet.example.com)
          ‧Login / roles / lobby / audit                            ‧The actual audio/video meeting
          ‧Issues JWT (HS256, optional)                             ‧Browsers connect directly to this domain for media
```

- **jt-vc-portal**: handles the entry point (login, permissions, scheduling, lobby, auditing) and embeds Jitsi using the IFrame API.
- **Jitsi Meet**: handles the meeting itself. Browsers connect directly to `meet.example.com` to load `external_api.js` and to exchange media.
- Two authentication options: **no JWT (open)** or **HS256 JWT (recommended)** — only tokens issued by jt-vc-portal can enter a meeting room.

> **Operational boundary (self-hosted vs. JaaS)**: The ports, NAT, media traversal / TURN (including `turns/443`) described here are **only something you have to handle yourself in self-hosted mode**. When using **8x8 JaaS**, media and NAT traversal are handled entirely by the 8x8 cloud, so you don't need to open these ports or run coturn — in JaaS mode jt-vc-portal only does authentication (signs JWTs), and media never passes through your host.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Prerequisites

- A publicly reachable host with DNS, e.g. `meet.example.com` (must be a different domain or subdomain from jt-vc-portal).
- Open to the outside: `443/tcp` (web / signaling), `10000/udp` (JVB media).
- A valid HTTPS certificate (Jitsi's built-in Let's Encrypt can be used).
- Docker and Docker Compose installed.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Ports and NAT Configuration

### Ports to open

| Port | Protocol | Purpose | Required? |
|---|---|---|---|
| 443 | TCP | HTTPS web + meeting signaling (BOSH / WebSocket) | Required |
| 80 | TCP | HTTP→HTTPS redirect + Let's Encrypt certificate issuance | Required when using built-in LE |
| 10000 | UDP | JVB media (audio/video RTP), **main media channel** | Required |
| 4443 | TCP | JVB media TCP fallback (for users whose network blocks UDP) | Optional |

- Media almost always goes over **UDP/10000**; only in the few networks that block UDP does it rely on the **TCP/4443** fallback. Opening both is recommended for stability.
- Your firewall / cloud Security Group must allow the above as **inbound**.
- Signaling (join, chat) uses 443/TCP; media (audio and video) uses 10000/UDP — missing either one results in "can join, but black screen / no audio".

> **Note (different from older versions)**: Modern Jitsi (including `stable-11031`) JVB multiplexes on a **single UDP port 10000** — all participants' media share this one port. You **no longer need** to open "a whole 10000–20000 range". That was the approach of old versions many years ago (dynamic port range via `org.ice4j.ice.harvest.MIN/MAX_PORT`), and it is obsolete in the docker single-port mode. Just allow `UDP 10000` (+ optionally `TCP 4443`).

### Behind NAT / firewall (host has a private IP)

By default JVB tells browsers "the IP it sees for itself"; if the host has a private IP (behind NAT), guests receive the private IP and cannot reach the media. You must make JVB **advertise its public IP**.

**(1) Edit `.env`** so JVB advertises the public IP (comma-separate multiple values; listing both the public and the private host IP lets both external and internal clients connect):

```ini
JVB_ADVERTISE_IPS=<public-IP>,<host-private-IP>
```

**(2) Port-forward on the router / firewall to the Jitsi host:**

- `UDP 10000` → host:10000 (**most important**)
- `TCP 443` → host:443
- `TCP 80` → host:80 (during Let's Encrypt issuance / renewal)
- (optional) `TCP 4443` → host:4443

**(3) Cloud hosts** (GCP / AWS / Azure, etc.): allow `UDP 10000`, `TCP 443`, `TCP 80` (and `TCP 4443`) in the VPC firewall / Security Group, and put the public IP into `JVB_ADVERTISE_IPS`.

> **Most common failure**: can enter the meeting room but black screen / no audio → in 80% of cases `UDP 10000` is not allowed / not forwarded, or `JVB_ADVERTISE_IPS` is not set to the public IP.

### Automatic media transport fallback (UDP 10000 → (optional TCP 4443) → TURN, incl. turns/443)

The browser's **ICE** mechanism **automatically** tries guests' audio/video media paths in order "from fastest to best at traversing firewalls", picking the first working channel with the highest priority — **you don't need to write any decision logic, you just need to have the channels ready**:

| Priority | Channel | Scenario | Difficulty |
|---|---|---|---|
| 1 | **UDP 10000** → direct to JVB | Normal networks (best quality) | Available by default |
| 2 | **TCP 4443** → direct to JVB | UDP blocked, arbitrary outbound TCP allowed | Optional / legacy |
| 3 | **TURN**: `turn` (udp/tcp 3478) + `turns` (tls 443) → relayed via coturn | UDP blocked, or even **only** 443 allowed | Requires a separate coturn |

> Most users work fine with **layer 1 UDP 10000** alone. A fallback is only needed when "the guest's network is very strict"; and **TURN (layer 3) is a single component that covers both "UDP unavailable" and "only 443 left"**, so it's recommended to go straight to TURN and skip layer 2.
>
> Performance trade-off: the further down the list, the better it traverses firewalls, but the slower it gets. `turns/443` is TCP + relay + an extra TLS layer, with the highest latency, and coturn becomes a central bottleneck — use it only as "the last lifeline"; don't rely on it when UDP 10000 works (Google Meet does the same: prefers UDP, falls back to TCP/443 only when UDP is fully blocked, and officially states that TCP degrades quality).

#### Layer 1: UDP 10000 (default, best quality)

As described above: allow `UDP 10000` and set `JVB_ADVERTISE_IPS`.

#### Layer 2: TCP 4443 (direct to JVB, optional / legacy)

Modern Jitsi **disables** JVB's built-in TCP harvester **by default**; upstream now uses TURN to handle fallback uniformly, and the `stable-11031` docker `.env` has **no** corresponding switch. Forcing it on requires overlaying a custom config to re-enable the TCP harvester and opening `TCP 4443` — **not needed in most scenarios; just go straight to layer 3 TURN**.

#### Layer 3: TURN (coturn, incl. turns/443)

How it works: run a **TURN server (coturn)** that provides both `turn` (udp/tcp 3478) and `turns` (TLS 443). ICE automatically "tries UDP relay first, then TCP relay, and finally turns/443", stepping down until something works; coturn then relays the media to JVB over UDP. `docker-jitsi-meet` **does not include coturn**, but it's easy to start with the official Docker image.

**(1) coturn configuration** `coturn/turnserver.conf`:

```ini
listening-port=3478
tls-listening-port=5349           # Topology A can set 443 directly (see below)
fingerprint
use-auth-secret
static-auth-secret=<long random string, shared with prosody>
realm=meet.example.com
cert=/etc/coturn/certs/turn.crt
pkey=/etc/coturn/certs/turn.key
min-port=49152
max-port=65535
external-ip=<this-host-public-IP>
no-multicast-peers
no-cli
```

**(2) Start coturn with Docker** (official image `coturn/coturn`). coturn needs a large range of UDP relay ports, so **host networking** is the easiest; here is a compose file that sits alongside Jitsi's compose:

```yaml
# docker-compose.coturn.yml
services:
  coturn:
    image: coturn/coturn:4.6          # pin a version; don't use latest
    restart: unless-stopped
    network_mode: host                # many relay ports; host networking is simplest
    volumes:
      - ./coturn/turnserver.conf:/etc/coturn/turnserver.conf:ro
      - ./coturn/certs:/etc/coturn/certs:ro
    command: ["-c", "/etc/coturn/turnserver.conf"]
```

```bash
docker compose -f docker-compose.coturn.yml up -d
```

**(3) Where to put 443 — pick one of two topologies:**

- **Topology A (simple, recommended): coturn has its own hostname + IP** (a separate small host, or a second public IP on the same machine). Set `tls-listening-port=443` directly in turnserver.conf; coturn owns 443 by itself and **no nginx splitting is needed at all**. Just point DNS `turn.meet.example.com` at that IP.
- **Topology B (sharing the same IP's 443 with Jitsi)**: only then do you need nginx `stream` + `ssl_preread` at the front edge to split traffic by **SNI** (without terminating TLS, passing it through as-is):

```nginx
stream {
  map $ssl_preread_server_name $upstream {
    turn.meet.example.com  127.0.0.1:5349;   # TURN/TLS → coturn
    default                127.0.0.1:8443;    # everything else → Jitsi web container
  }
  server {
    listen 443;
    listen [::]:443;
    ssl_preread on;
    proxy_pass $upstream;
  }
}
```

> In Topology B, since nginx occupies 443, the Jitsi web container must move to another port (set `HTTPS_PORT=8443` in `.env`) and be reverse-proxied by nginx; both `turn.meet.example.com` and `meet.example.com` point to this host.

**(4) Have prosody advertise TURN to browsers** (XEP-0215 `external_services`) so browsers know TURN is available. In the docker version this is done by overlaying prosody config, equivalent to:

```lua
external_services = {
  { type = "turn",  host = "turn.meet.example.com", port = 3478, transport = "udp", secret = "<same as coturn static-auth-secret>" };
  { type = "turns", host = "turn.meet.example.com", port = 443,  transport = "tcp", secret = "<same as coturn static-auth-secret>" };
};
```

**(5) Verify**: open `https://webrtc.github.io/samples/src/content/peerconnection/trickle-ice/`, enter `turns:turn.meet.example.com:443?transport=tcp`, and a `relay` candidate should appear; then restrict the test client's network to only 443 and confirm the meeting still works (video / audio OK).

> This layer involves certificates, SNI splitting (for Topology B), and keeping coturn and prosody secrets consistent, so environments vary a lot. If needed, I can write a separate step-by-step version for your actual topology (same host / separate host, certificate source).

---

<br>
<br>
<br>
<br>
<br>
<br>

## 1. Get the Official docker-jitsi-meet

```bash
git clone https://github.com/jitsi/docker-jitsi-meet.git
cd docker-jitsi-meet
git checkout stable-11031

cp env.example .env
./gen-passwords.sh         # generate random passwords for the internal components (written back to .env)

mkdir -p ~/.jitsi-meet-cfg/{web,transcripts,prosody/config,prosody/prosody-plugins-custom,jicofo,jvb,jigasi,jibri}
```

> `JITSI_IMAGE_VERSION` in `.env` should be `stable-11031` (matching the checked-out tag) so the corresponding images are pulled.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 2. Basic External Settings (Edit `.env`)

```ini
# Public URL (= the address to enter as the "Service domain" in jt-vc-portal)
PUBLIC_URL=https://meet.example.com

# Let's Encrypt automatic certificate
ENABLE_LETSENCRYPT=1
LETSENCRYPT_DOMAIN=meet.example.com
LETSENCRYPT_EMAIL=you@example.com

# External ports
HTTP_PORT=80
HTTPS_PORT=443
JVB_PORT=10000

TZ=Asia/Taipei

# Lobby: lets jt-vc-portal's "lobby mode" (toggleLobby) take effect
ENABLE_LOBBY=1
```

### TLS certificate options (choose one)

**(A) Let's Encrypt automatic certificate** (the example above): `ENABLE_LETSENCRYPT=1` + `LETSENCRYPT_DOMAIN` + `LETSENCRYPT_EMAIL`; the container requests and renews the certificate automatically. Requires `TCP 80` to be reachable from outside.

**(B) Your own SSL certificate** (you already have a certificate / corporate CA / wildcard certificate): disable Let's Encrypt and put the certificate into the web container's keys directory —

```ini
ENABLE_LETSENCRYPT=0
```

```bash
# Put the certificate into keys/ of the web config volume (CONFIG defaults to ~/.jitsi-meet-cfg)
mkdir -p ~/.jitsi-meet-cfg/web/keys
cp your-fullchain.pem ~/.jitsi-meet-cfg/web/keys/cert.crt   # full certificate including the intermediate chain
cp your-private.key   ~/.jitsi-meet-cfg/web/keys/cert.key   # matching private key
# Restart so the web container picks it up
docker compose up -d
```

> The file names are fixed: **`cert.crt` (full chain)** and **`cert.key` (private key)**, placed in `~/.jitsi-meet-cfg/web/keys/` (`/config/keys/` inside the container). Keep `HTTPS_PORT=443` unchanged. Before the certificate expires, replace the files and run `docker compose restart web`.

**(C) TLS handled by a front reverse proxy** (certificate lives on nginx / Traefik / HAProxy): the container serves HTTP only and TLS is left to the reverse proxy —

```ini
ENABLE_LETSENCRYPT=0
DISABLE_HTTPS=1
HTTP_PORT=8000
```

The reverse proxy forwards `https://meet.example.com` to the container's `HTTP_PORT`, and must forward **WebSocket** (required for meeting signaling) as well as the `X-Forwarded-*` / `Host` headers.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 3. Enable JWT Authentication (Recommended)

Make Jitsi **accept only tokens issued by jt-vc-portal**, so nobody can barge in just by guessing a meeting room name. In self-hosted mode jt-vc-portal signs tokens with an **HS256 shared secret**.

> **Important — once JWT is enabled, the official mobile apps cannot join directly**
> With `ENABLE_AUTH=1` (JWT), meeting rooms only accept tokens issued by jt-vc-portal. **The official Jitsi Meet mobile apps (iOS / Android) don't go through this portal and can't obtain a token, so they will be unable to join** (an authentication / token error will appear).
> On mobile devices, open the invitation link in the **mobile browser** instead and join via jt-vc-portal. If native apps absolutely must be able to join rooms directly, the only option is to stay in "no-JWT anonymous mode" (see below), which is less secure.

Set in `.env`:

```ini
ENABLE_AUTH=1
AUTH_TYPE=jwt
ENABLE_GUESTS=0                       # only token holders may enter (jt-vc-portal guards the entrance)

JWT_APP_ID=jt-vc-portal               # ← corresponds to jt-vc-portal's "App ID"
JWT_APP_SECRET=<generate a long random string>  # ← corresponds to jt-vc-portal's "app_secret (HS256 shared secret)"
JWT_ACCEPTED_ISSUERS=jt-vc-portal     # same as App ID
JWT_ACCEPTED_AUDIENCES=jt-vc-portal   # same as App ID

# === Moderator permission control (very important; see below — all three are required) ===
ENABLE_AUTO_OWNER=0                            # don't make "the first person to join" a moderator automatically
XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass   # moderator from the token; hosts can skip the lobby
JICOFO_ENABLE_AUTH=0                           # the module above decides who is a moderator; jicofo no longer grants it
```

Mapping (**all three sides must match**):

| jt-vc-portal (Settings → Connection mode) | Jitsi `.env` | Notes |
|---|---|---|
| App ID | `JWT_APP_ID` / `JWT_ACCEPTED_ISSUERS` / `JWT_ACCEPTED_AUDIENCES` | Tokens signed by jt-vc-portal have `iss` = `aud` = App ID |
| app_secret | `JWT_APP_SECRET` | HS256 shared secret, identical on both sides |
| JWT sub | (subject validated by prosody) | **Leave empty** (defaults to sending `*`, suitable for a single domain without tenants); fill in the tenant name only for multi-tenant setups |

> **Generating the secret**: `openssl rand -hex 32`, and paste the same value on both sides.
>
> Why sub defaults to `*`: in a standard single-domain docker-jitsi-meet (non-tenant) setup, prosody token validation only accepts `sub` of `*` or a tenant name; this system sends `*` by default, so it works out of the box.

### Moderator permission control (important: otherwise everyone is a moderator)

**When docker-jitsi-meet uses JWT, by default "anyone holding a valid token is a moderator"** — even if the token carries `context.user.moderator: false`, it is not enforced. The consequence is that **guests are moderators too**: they can kick people, end the meeting for everyone, and **bypass the lobby**. To make "only designated hosts are moderators", the three lines in `.env` above are **all required**:

| Setting | Effect |
|---|---|
| `ENABLE_AUTO_OWNER=0` | Turns off "the first person to join automatically becomes owner". |
| `XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass` | Enables the prosody module that sets the user to `owner` (moderator) or `member` (regular participant) based on the token's `moderator` flag. Since `stable-11031` it is part of Jitsi itself (`/prosody-plugins/mod_token_affiliation.lua`). `token_lobby_bypass` (a community module shipped in the image) lets anyone whose token carries `lobby_bypass: true` skip the lobby — **without it, in a room with the lobby on, a host who disconnects and rejoins is held in the lobby, and if only guests remain nobody can let them in**. Since v1.16.1 jt-vc-portal sets this flag for hosts only; guests still knock. |
| `JICOFO_ENABLE_AUTH=0` | Turns off jicofo's authentication-based granting. With it on, jicofo promotes **everyone holding a valid token** to moderator, overriding the module above — **without this line guests are still moderators and can start recording, kick people and end the meeting**. This is the approach recommended by the Jitsi maintainers ([#16297](https://github.com/jitsi/jitsi-meet/issues/16297), [#16905](https://github.com/jitsi/jitsi-meet/issues/16905)). Joining a meeting still requires a valid token (prosody validation is unaffected). |

> **Upgrading from stable-10888 or earlier**: older versions of this guide used the community module (`/prosody-plugins-contrib/token_affiliation`) plus `GLOBAL_CONFIG=disable_cascading_set = false`. Since `stable-11031` the community module is gone and the built-in one is used, so that line no longer has any effect — **after upgrading, switch to `JICOFO_ENABLE_AUTH=0` above**, or guests become moderators again. After upgrading, check with a guest that there is no "Start recording" and they cannot kick people.

After configuring, run `docker compose up -d` (this recreates prosody / jicofo). On the jt-vc-portal side: host tokens carry `moderator: true` and guest tokens carry `moderator: false` (handled automatically by this system), so **host = owner / moderator, guest = member** (cannot kick people / end the meeting, and is held by the lobby).

> **There is no need to split hosts and guests into different domains / tenants** — the same entry point and the same token, distinguished by the `moderator` flag, is enough.
>
> Verification: join the meeting as a guest; their participants panel / "⋯" menu should **not** show "Mute everyone / End meeting / Kick"; only the host has these.

### Access behavior after enabling JWT (anonymous access blocked by default)

With JWT enabled, the front end at `https://meet.example.com/` still loads, but **without a token issued by jt-vc-portal nobody can create or join any meeting room** (an authentication failure appears) — **anonymous room creation is already blocked**, as are official mobile apps hitting the domain directly. Only people coming through jt-vc-portal (with a token) can get in, so **security is not a concern**.

> **The following is purely cosmetic and optional; skipping it does not affect security.** Only change this if you care that "visiting `meet.example.com` directly still shows the Jitsi UI / random room / app install prompt" and want meet to be a pure back end:
>
> **(1) Hide the welcome page** (`.env`): `ENABLE_WELCOME_PAGE=0`. Note that once disabled, visiting `/` directly auto-generates a random room name, and phones still show the "Join in the app" deep-link page (tapping it is also blocked by JWT).
>
> **(2) Cleaner — redirect the root to the portal**: using the web container's existing `include /config/nginx-custom/*.conf;`, drop in a config that redirects only `/` (room URLs, `external_api.js`, and IFrame embedding are unaffected):
>
> ```bash
> mkdir -p ~/.jitsi-meet-cfg/web/nginx-custom
> cat > ~/.jitsi-meet-cfg/web/nginx-custom/redirect-root.conf <<'EOF'
> location = / {
>     return 302 https://vc.example.com/;   # replace with your jt-vc-portal URL
> }
> EOF
> docker exec docker-jitsi-meet-web-1 nginx -s reload
> ```
>
> Now visiting `meet.example.com` directly redirects to the portal; only requests embedded via the portal (`/<room>` + `external_api.js`) are served as usual.

### Not using JWT (open mode)

Just set `ENABLE_AUTH=0` and choose "No" for "Self-hosted requires JWT" in jt-vc-portal — the front end sends no token, and anyone who knows a room name can join. **Less secure; suitable only for internal networks / testing.**

---

<br>
<br>
<br>
<br>
<br>
<br>

## 4. Start Jitsi

```bash
docker compose up -d
docker compose ps
```

Verify: opening `https://meet.example.com/external_api.js` in a browser should return the JS (jt-vc-portal loads it when embedding).

---

<br>
<br>
<br>
<br>
<br>
<br>

## 5. jt-vc-portal Settings

Log in to jt-vc-portal → **System settings → Connection mode settings**, switch to "Self-hosted Jitsi Meet", and fill in:

| Field | Example value |
|---|---|
| Mode | Self-hosted Jitsi Meet |
| Service domain | `meet.example.com` (without `https://`) |
| This system's public URL | `https://vc.example.com` |
| Self-hosted requires JWT | Yes (corresponds to `ENABLE_AUTH=1`) / No (`ENABLE_AUTH=0`) |
| App ID | `jt-vc-portal` (= `JWT_APP_ID`) |
| app_secret (HS256) | (= `JWT_APP_SECRET`) |
| JWT sub | Leave empty (defaults to sending `*`); fill in the tenant name only for multi-tenant setups |

After saving, create a meeting room from jt-vc-portal and start hosting; the self-hosted Jitsi will be embedded and (if enabled) the token passed automatically.

> **Meeting room top-left logo**: now configured centrally in the Jitsi server's `config.js` (consistent between live meetings and recordings); see [Section 8](#8-branding-logo-and-hidden-recorder-server-configjs). jt-vc-portal no longer overrides the logo via IFrame, and the logo option has been removed from "Meeting room customization".

> **All of the following are passed in by jt-vc-portal when joining a meeting; no Jitsi changes needed:**
> - **Disable the "Join in the app" deep link** (`configOverwrite.disableDeepLinking = true`): on phones, joining via the portal opens directly in the browser, without jumping to the official app install/open page (that app can't connect because of JWT).
> - **Meeting room customization** (System settings → Meeting room customization, self-hosted mode): join muted / camera off, maximum video quality, **default view (speaker / gallery)**, receive quality for small tiles ([data reference below](#small-tile-receive-quality-and-data-usage)), and per-item toolbar toggles — all applied when joining.
> - **Lobby mode**: check it when creating a meeting room; it is enabled automatically when the host joins (after obtaining moderator), and guests must be approved one by one to enter.
> - **Codec preference** (`videoQuality.codecPreferenceOrder = VP9, H264, VP8, AV1`): desktop-only meetings use VP9 (good quality at low bandwidth); **meetings that include iPhone/iPad automatically switch to hardware H.264** (iOS Safari doesn't support VP9, otherwise it would fall back to VP8, which has the worst quality).

> **Viewing quality on mobile**: remote video looking blurry on a phone over a cellular network is mainly due to mobile downlink bandwidth + adaptive bitrate (the LAN side has plenty of bandwidth, so it's sharp). VP9/H.264 already improve this as much as possible; doing better requires the native app (which this architecture can't use because of JWT), or ensuring media goes directly over UDP 10000 rather than a TCP relay.

### Bandwidth reference (computers, server, phones)

These numbers were measured in October 2026 in real meetings on self-hosted Jitsi Meet stable-11031. Test conditions: everyone sends 1280×720 camera video at 15 frames per second that changes constantly (so the encoder runs at its limit — a conservative basis for sizing lines); computers are 1440×900 browser windows using VP9; one person has the microphone on, everyone else is muted. All figures are in **Mbps (megabits per second)** — the same unit as line plans (e.g. 300M / 100M), so you can compare them directly.

#### Bandwidth each computer needs

| People | Upload | Download: tile view (default quality) | Download: tile view (Higher / High) | Download: speaker view |
|---|---|---|---|---|
| 2 (direct peer-to-peer) | ~1.3 | ~1.3 | ~1.3 | ~1.3 |
| 3 | ~1.3 | ~0.4 | ~1.3 | ~0.8 |
| 4 | ~1.3 | ~0.6 | ~2.0 | ~0.9 |
| 5 | ~1.3 | ~0.8 | ~2.5 | ~1.0 |
| 6 | ~1.3 | ~1.0 | ~3.4 ※ | ~1.2 |

- **Upload**: whoever others are viewing large (speaker view) has to send 720p, about 1.3 Mbps. Anyone can be the one shown large, so plan 1.3 Mbps for every computer. With tile view only and "Default" quality it is about 0.4 Mbps.
- **Download** depends on how many people you see and how big each tile is. "Higher / High" is System settings → Meeting room customization → "Receive quality for small tiles"; on a computer both receive 720p in tile view, so the numbers are the same. Speaker view is one large picture (720p) plus small pictures of the others (180p) and is not affected by that setting.
- **2-person meetings** connect directly peer-to-peer without the server; they only go through the server if the direct connection fails.

#### Bandwidth the Jitsi server needs

The server (JVB) **receives the sum of everyone's uploads and sends the sum of everyone's downloads**. As more people join, the server's **upload** grows fastest — check the upload speed when ordering a line.

| People | Tile view (default) in / out | Tile view (Higher / High) in / out | Speaker view in / out |
|---|---|---|---|
| 2 | almost 0 (peer-to-peer) | almost 0 | almost 0 |
| 3 | ~1.2 / 1.3 | ~3.9 / 4.2 | ~2.8 / 2.5 |
| 4 | ~1.6 / 2.6 | ~5.3 / 8.3 | ~2.9 / 3.9 |
| 5 | ~2.0 / 4.4 | ~6.3 / 13.2 | ~3.1 / 5.4 |
| 6 | ~2.4 / 6.6 | ~7.5 / 22 ※ | ~3.2 / 7.2 |

※ With 6 people at "Higher / High", the single test computer ran out of CPU and the encoders dropped to 540p by themselves, so the download and server "in" figures are calculated from the per-stream rates and server "out" uses the larger measured value. In real use each person has their own computer, so this does not happen.

#### Example: 4 people, all on computers

- **Each computer**: upload about 1.3 Mbps, download about 2 Mbps (tile view, Higher / High; about 0.6 with default quality, about 0.9 in speaker view).
- **Server**: upload about 8.3 Mbps, download about 5.3 Mbps (tile view, Higher / High; about 2.6 / 1.6 with default quality).
- **Leave headroom**: other people using the same line, unstable Wi-Fi and lots of motion on screen all push the numbers up, so plan for 1.5–2× the table values — e.g. 5 Mbps or more per computer and 20 Mbps or more of server upload is comfortable.

#### Small-tile receive quality and data usage

**System settings → Meeting room customization → "Receive quality for small tiles (phones, tile view with 3+ people)"** sets the quality each small tile requests from Jitsi in meetings with 3 or more people. Jitsi picks a quality layer from the tile size (there are only three layers — 180p / 360p / 720p — so there is no 480p option); phone screens are small, so the tiles are small too and get only 180p by default. 2-person meetings (direct peer-to-peer, already 720p) and the large speaker view are not affected.

Phone, 4 people in tile view (phone = Chromium emulating a Pixel 7):

| Level | Each tile on a phone | Each tile on a computer | Phone download | Data per second | About per hour of meeting |
|---|---|---|---|---|---|
| Default (Jitsi automatic) | 180p | 360p | ~0.3 Mbps | ~38 KB | ~135 MB |
| Higher | 360p | 720p | ~1.2 Mbps | ~150 KB | ~540 MB |
| High | 720p | 720p | ~2 Mbps | ~250 KB | ~900 MB |

**Which one to choose**

- Mostly computers, or phone data usage matters: keep **Default**.
- People often join 3–4-person meetings from phones and find others' video blurry: choose **Higher**.
- Phones on Wi-Fi and you want the sharpest picture: choose **High** (more battery use and heat on phones, and more server upload bandwidth).

#### How the numbers are calculated

- **Mbps means megabits per second** — bits, not bytes; 1 byte = 8 bits. Line plans are quoted in bits, file sizes and mobile data usage in bytes.
- **Each computer**: the `bytesSent` / `bytesReceived` of the `transport` entries in the browser's WebRTC statistics (`RTCPeerConnection.getStats()`) — audio, video and control packets all included — sampled 10 seconds apart: `(after − before) × 8 ÷ seconds` = bits per second. The phone table counts received video only (`bytesReceived` of `inbound-rtp`, 4 seconds apart).
- **Server**: the received / sent byte counters of the Jitsi host's network interface (`/sys/class/net/<interface>/statistics/rx_bytes`, `tx_bytes`), also 10 seconds apart; this includes IP / UDP headers, i.e. what actually runs over the line.
- **Data per second** = rate ÷ 8 (1.2 Mbps → 150 KB); **per hour** = data per second × 3,600 (150 KB → about 540 MB, with 1 MB = 1,000 KB).
- **Estimating for more people**: each video stream is about 0.65 Mbps at 720p, 0.2 at 360p and 0.1 at 180p. Download per computer ≈ per-stream rate × (people − 1) (speaker view = 0.65 + 0.1 × (people − 2)); server out ≈ download per computer × people, server in ≈ upload per computer × people. 7 or more people were not measured; with more people the tiles get smaller and Jitsi switches to lower layers, so real numbers are usually below the estimate.
- **Real numbers vary** with how much moves on screen, network conditions, the number of people and Jitsi's bandwidth estimation; these are reference values, not guarantees. Jitsi caps the bitrate for each resolution, so ordinary webcams normally stay at or below the table values.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 6. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| `external_api.js` 404 | `PUBLIC_URL` / HTTPS not set up correctly; check that the containers and certificate are working |
| Token / authentication error when joining a meeting | App ID, `JWT_APP_SECRET`, `iss`, `aud` don't match; or in a multi-tenant environment the tenant name must be filled in "JWT sub" (for a single domain leave it empty to send `*`) |
| Lobby has no effect | `.env` needs `ENABLE_LOBBY=1`, and "Lobby mode" must be checked when creating the meeting room |
| Black screen / media not flowing | `10000/udp` not open, or NAT; set `JVB_ADVERTISE_IPS=<host-public-IP>` in `.env` |
| Some guests (strict networks) can't connect media | That guest's network blocks UDP / allows only 443 → see "Automatic media transport fallback" and set up TURN (coturn, incl. turns/443) |
| Want a unified domain experience | The jt-vc-portal address bar always shows `vc.example.com`; `meet.example.com` is only visible in F12 / connection details (normal) |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 7. Recording (Jibri, Optional)

Meeting recording requires deploying **Jibri** separately (dedicated resources; one instance records one meeting at a time). For the complete steps — including **recording multiple meeting rooms simultaneously** and **CJK text display in recordings (CJK fonts)** — see **[JIBRI-SETUP.md](JIBRI-SETUP.md)**.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 8. Branding Logo and Hidden Recorder (Server config.js)

Both of these must be set in **the Jitsi server's own `config.js`**, for the same reason:

- **Jibri recording** uses its own browser to **connect directly to the Jitsi server**, **not through the portal's IFrame** — so settings passed by the portal have no effect on recordings; only the server `config.js` affects both "live + recording".
- Hiding the recorder (`hiddenDomain`) via the IFrame `configOverwrite` does not reliably take effect; setting it on the server side is the most reliable.

Every time the docker-jitsi-meet container starts, it automatically **appends** `~/.jitsi-meet-cfg/web/custom-config.js` and `custom-interface_config.js` to the generated config (see `/etc/cont-init.d/10-config` in the web container), so placing these two files makes the settings persistent.

**Configuration (two files):**

```bash
# (1) config.js override: top-left logo + hidden recorder
cat > ~/.jitsi-meet-cfg/web/custom-config.js <<'JS'
config.defaultLogoUrl = "https://vc.example.com/logo";   // replace with your portal URL + /logo
config.hiddenDomain   = "hidden.meet.jitsi";             // recorder login domain, hidden from participant list/count
JS

# (2) interface_config.js override: watermark logo
cat > ~/.jitsi-meet-cfg/web/custom-interface_config.js <<'JS'
interfaceConfig.DEFAULT_LOGO_URL     = "https://vc.example.com/logo";
interfaceConfig.JITSI_WATERMARK_LINK = "https://vc.example.com";
interfaceConfig.SHOW_JITSI_WATERMARK = true;
JS
```

**Apply (restarts the web container; live service is interrupted for a few seconds):**

```bash
docker restart docker-jitsi-meet-web-1
```

**Key points:**

- Replace `https://vc.example.com/logo` with your jt-vc-portal public URL + `/logo` (the portal serves the logo uploaded under "System settings → Site settings" here, returning a PNG; the Jibri host must also be able to reach this URL).
- After that, changing the logo image in the portal takes effect automatically (the URL stays the same, **no need to restart Jitsi again**).
- `hidden.meet.jitsi` is docker-jitsi-meet's recorder domain (`XMPP_RECORDER_DOMAIN`) and is usually this value; you can confirm with `docker exec docker-jitsi-meet-prosody-1 grep -i VirtualHost /config/conf.d/*.lua`.

**Verify:**

```bash
docker exec docker-jitsi-meet-web-1 grep -E "defaultLogoUrl|hiddenDomain" /config/config.js
```

Then record a test clip: on playback, confirm the top-left shows the custom logo (not the default Jitsi watermark), and that the participant list/count does **not** include the recorder.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 9. Upgrading Jitsi

Before upgrading, make sure **nobody is in a meeting and nothing is being recorded** (the upgrade interrupts all meetings):

```bash
docker exec docker-jitsi-meet-jicofo-1 curl -s http://127.0.0.1:8888/stats   # conferences and participants must both be 0
```

Back up your settings (so you can go back if something breaks):

```bash
cd docker-jitsi-meet
tar czf ~/jitsi-backup-$(date +%Y%m%d).tgz .env docker-compose*.yml -C ~ .jitsi-meet-cfg
git describe --tags > ~/jitsi-backup-$(date +%Y%m%d).version     # note the current version for rollback
```

Upgrade:

```bash
git fetch --tags
git checkout stable-<new-version>
docker compose pull
docker compose up -d
```

JWT and integration settings don't need to change (`custom-config.js` / `custom-interface_config.js` are kept and automatically appended again).

- **Upgrade the Jitsi host and the Jibri host to the same version** (see the upgrade section of [JIBRI-SETUP.md](JIBRI-SETUP.md)); if the versions drift too far apart, recording may fail to connect.
- Rollback: `git checkout <previous-version>`, then `docker compose up -d` (the old images are still on the host).
- After upgrading, hold a test meeting, check that two people can hear each other, and record a short clip to confirm it plays back.
- This guide was verified on `stable-11031`. **`stable-11146` and later is a structural change** (base moved to Debian 13, containers run as non-root, images moved to the GitHub Container Registry, the web container's internal ports and WebSocket settings changed). It is not just a version bump: read the official release notes and rehearse in a test environment before upgrading, and re-check your custom `jibri-cjk` image.
