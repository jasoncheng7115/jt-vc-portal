<p align="center"><img src="docs/images/icon.svg" alt="jt-vc-portal" width="96" height="96"></p>

# jt-vc-portal v1.17.0 — Meeting Management System

> 繁體中文: [README_zh-TW.md](README_zh-TW.md) · 日本語: [README_ja.md](README_ja.md)

> A meeting portal built on Jitsi Meet with **dual-mode** support for [8x8 JaaS](https://jaas.8x8.vc/)[^8x8] (cloud-hosted) and **[self-hosted Jitsi Meet](https://github.com/jitsi/jitsi-meet)**.
> After signing in, hosts can create meeting rooms and generate invite links (with QR codes / `.ics` calendar invitations); guests join through the invite link.
> Built-in enterprise features include multiple accounts / roles / 2FA, a complete audit log with SIEM forwarding, fail2ban-style lockout, scheduled time slots, and more.

[^8x8]: **8x8** has been the company developing and maintaining Jitsi / Jitsi Meet since 2018; **8x8 JaaS (Jitsi as a Service)** is its official cloud-hosted Jitsi service.

**Project page / Demo:** <https://jasoncheng7115.github.io/jt-vc-portal/>

![License](https://img.shields.io/badge/License-GPL--3.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4.svg)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED.svg)
![OWASP](https://img.shields.io/badge/OWASP-Top_10_2025-success.svg)
![Dependencies](https://img.shields.io/badge/PHP_deps-zero-brightgreen.svg)

---

## Features

- **Dual connection modes**: 8x8 JaaS (cloud-hosted, RS256 + kid) or self-hosted Jitsi Meet (HS256 or no JWT), switchable with one click in the admin UI — no code changes required.
- **Meeting room management**: create / enter / delete rooms (invitees get a calendar cancellation), random room names, recent-rooms list, one-click invite copying, QR code pop-up.
- **Scheduled time slots**: set an open time window; before it starts, guests see a flip-clock countdown, and the room opens automatically if the host joins early.
- **Guest flow**: guests must enter a display name before joining; outside the open window they see a waiting / countdown / ended page.
- **Lobby mode**: optional when creating a room; it is enabled automatically when the host joins, and guests must be admitted one by one by the host.
- **Email invitations**: enter attendee email addresses to send invitations with an `.ics` attachment (METHOD:REQUEST) that can be added to a calendar in one click.
- **Multiple accounts / roles / 2FA**: admins see all rooms, hosts see only the rooms they created; TOTP two-factor authentication supported.
- **Single sign-on (OIDC)**: hosts and admins can sign in with their company account through Keycloak / Entra ID (AD groups → roles, MFA at the IdP); the portal never connects to AD / LDAP directly. SSO-only mode with an IP-restricted emergency admin. Setup: [KEYCLOAK-SETUP.md](KEYCLOAK-SETUP.md).
- **Meeting transcripts and summaries (jt-live-whisper)**: after a self-hosted Jibri recording finishes, the portal can send it to [jt-live-whisper](https://github.com/jasoncheng7115/jt-live-whisper) (JTLW) to produce a speaker-labelled transcript and a meeting summary — key points, decisions and action items, events, risks, open questions, topics and speaker statistics, every item citing the time in the recording. Per-account permission (off / manual / automatic), a per-meeting switch, and admins can generate for any meeting. Viewer with a waveform player, click-to-seek, speaker renaming and speaker suggestions (from Jitsi's speaker timeline); the whole record exports as PDF / DOCX / ODT / HTML (plus TXT / SRT / JSON / Markdown). The portal itself never connects to a language model. Full setup: [TRANSCRIPTS-SETUP.md](TRANSCRIPTS-SETUP.md).
- **Auditing and security**: complete audit log of user actions (sign-ins, room creation, invitations, settings changes…) + real-time forwarding via syslog / CEF / GELF; fail2ban-style login lockout; CSRF protection; follows OWASP Top 10:2025.
- **Recording retrieval** (self-hosted Jibri): connects to a recording service on the Jibri host for online listing / playback / download / deletion, host storage capacity, and retention policies (age / capacity / leftovers, disabled by default); hosts can access recordings of the meetings they hosted.
- **Multilingual interface**: the portal UI is available in Traditional Chinese, English and Japanese — detected from the browser, switchable from the account menu or per user in the profile; the Jitsi meeting language can follow the interface language.
- **Customizable appearance**: 60 Jitsi UI languages, 22 themes, customizable site name and logo, and a disguisable login page path.
- **Meeting statistics**: meeting duration ranking, peak concurrent participants and participant join/leave timelines; in JaaS mode, MAU can also be tracked via the USAGE webhook.
- **Zero external PHP packages**: the core is entirely hand-written with no composer dependencies (the front end only uses qrcodejs / flatpickr from a CDN).

---

<br>
<br>
<br>
<br>
<br>
<br>

## Requirements

| Item | Minimum | Recommended |
|---|---|---|
| PHP | 8.2 | **8.4** |
| Web server | Apache + `mod_rewrite` (`AllowOverride All`) | Same |
| PHP extensions | `openssl`, `fileinfo`, `json`, `mbstring`, `curl`, `zlib` | Same |
| Other | A writable data directory; the 8x8 private key for JaaS mode | Docker 24+ |

> Self-hosted Jitsi Meet mode additionally requires a working Jitsi Meet server (see "Connection modes").

---

<br>
<br>
<br>
<br>
<br>
<br>

## Installation option 1: Direct install (Apache + PHP)

```bash
# 1) Get the code
git clone https://github.com/jasoncheng7115/jt-vc-portal.git
cd jt-vc-portal

# 2) Enable the Apache module and .htaccess (Debian/Ubuntu example)
a2enmod rewrite
# Make sure the site config uses AllowOverride All and DocumentRoot points to this folder

# 3) Enable .htaccess (shipped as dot.htaccess in this project to avoid accidental activation)
cp dot.htaccess .htaccess

# 4) Create the persistent data directory (default /var/jaas-data; adjustable via DATA_DIR in config.php)
sudo mkdir -p /var/jaas-data
sudo chown www-data:www-data /var/jaas-data

# 5) (JaaS mode only) Place the 8x8 private key
sudo mkdir -p keys
sudo cp /path/to/your/private.key keys/private.key
```

Example Apache site config (`/etc/apache2/sites-available/jt-vc-portal.conf`):

```apache
<VirtualHost *:80>
    ServerName vc.example.com
    DocumentRoot /var/www/jt-vc-portal

    <Directory /var/www/jt-vc-portal>
        Options -Indexes +FollowSymLinks
        AllowOverride All          # Required for .htaccess to take effect
        Require all granted
    </Directory>

    # Hide the server version
    ServerTokens Prod
    ServerSignature Off

    ErrorLog  ${APACHE_LOG_DIR}/jt-vc-portal-error.log
    CustomLog ${APACHE_LOG_DIR}/jt-vc-portal-access.log combined
</VirtualHost>
```

Enable the site and the required modules:

```bash
a2enmod rewrite headers
a2ensite jt-vc-portal
systemctl reload apache2
```

> For production, use `*:443` with a Let's Encrypt certificate, or put a reverse proxy in front to handle HTTPS.
> If you forward through a reverse proxy, preserve the `X-Real-IP` header (used by the fail2ban-style lockout and the audit log to get the real source IP), and set `JTVC_TRUSTED_PROXIES` (see "Security essentials for public deployment" below).

Key settings (`config.php`):

- `DATA_DIR`: persistent data directory (default `/var/jaas-data`).
- `JWT_PRIVATE_KEY_PATH`: path to the JaaS RS256 private key (default `keys/private.key`, relative to the docroot).
- Initial administrator: can be set via the environment variables `JTVC_ADMIN_USERNAME` / `JTVC_ADMIN_EMAIL` / `JTVC_ADMIN_PASSWORD`; if not provided, a random password is generated on first start and written to `DATA_DIR/INITIAL_ADMIN_PASSWORD.txt` (delete it after signing in).
- Reverse proxy trust: `JTVC_TRUSTED_PROXIES` (comma-separated IPs/CIDRs). Once set, `X-Real-IP` / `X-Forwarded-*` are trusted only when coming from these sources, preventing source-IP spoofing to bypass the fail2ban-style lockout; leaving it empty = compatibility mode (headers are trusted blindly, must be combined with container port isolation). See "Security essentials for public deployment".
- Session timeouts: `JTVC_SESSION_IDLE` (idle seconds, default 1800), `JTVC_SESSION_ABSOLUTE` (absolute seconds, default 43200).

PHP hardening (recommended in `php.ini` or conf.d): `display_errors=Off`, `expose_php=Off`, `session.cookie_httponly=1`, `session.cookie_samesite=Lax`, `session.use_strict_mode=1`.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Installation option 2: Docker deployment

```bash
git clone https://github.com/jasoncheng7115/jt-vc-portal.git
cd jt-vc-portal

# Build (--pull recommended to get the latest base image)
docker build --pull -t jt-vc-portal .

# Prepare persistent directories on the host (www-data UID defaults to 33)
mkdir -p /opt/jt-vc-portal/keys /opt/jt-vc-portal/data
chown 33:33 /opt/jt-vc-portal/data
# JaaS mode: place the 8x8 private key
cp /path/to/private.key /opt/jt-vc-portal/keys/private.key

# Run (-p binds to 127.0.0.1: only the local reverse proxy can reach it; the container port is not exposed directly)
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="CHANGE_ME_strong_password" \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal
```

The container listens on `:58189`; it is recommended to put an nginx / Apache reverse proxy in front to add HTTPS:

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

> **Security essentials for public deployment (must do)**
> The system uses `X-Real-IP` (then `X-Forwarded-For`) to determine the real source IP for the fail2ban-style lockout and the audit log. To prevent attackers from forging this header to bypass the lockout:
> - Set `JTVC_TRUSTED_PROXIES` (comma-separated IPs/CIDRs, IPv4/IPv6 supported). **Only** sources on this list have their `X-Real-IP` / `X-Forwarded-*` trusted; everything else uses the actual connection IP (`REMOTE_ADDR`).
> - Bind the container port to localhost with `-p 127.0.0.1:58189:58189`, or restrict it with a firewall, so that **only the reverse proxy can reach it**.
> - Do at least one of these; doing both is recommended. **If `JTVC_TRUSTED_PROXIES` is left empty = compatibility mode (headers trusted blindly)**: this is fine when the port is isolated, but if the container port is directly reachable from outside, an attacker can forge the source IP to bypass the fail2ban-style lockout and pollute the audit log.
> - When behind Cloudflare, have the reverse proxy populate `X-Real-IP` from `CF-Connecting-IP`.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Installation option 3: Load the prebuilt image from GitHub Releases

Don't want to build it yourself? Download the packaged image (`linux/amd64`) attached to the [Release](https://github.com/jasoncheng7115/jt-vc-portal/releases), then `docker load` it and run.

```bash
# 1) Download the image and checksum file from the Release page (use the latest version number)
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.17.0/jt-vc-portal-1.17.0-docker-amd64.tar.gz
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.17.0/jt-vc-portal-1.17.0-docker-amd64.tar.gz.sha256

# 2) Verify integrity (should print OK)
sha256sum -c jt-vc-portal-1.17.0-docker-amd64.tar.gz.sha256

# 3) Load the image (creates the jt-vc-portal:1.17.0 and :latest tags)
docker load < jt-vc-portal-1.17.0-docker-amd64.tar.gz

# 4) Prepare persistent directories on the host (www-data UID defaults to 33)
mkdir -p /opt/jt-vc-portal/keys /opt/jt-vc-portal/data
chown 33:33 /opt/jt-vc-portal/data
cp /path/to/private.key /opt/jt-vc-portal/keys/private.key   # JaaS mode only

# 5) Run (same parameters as option 2)
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_ADMIN_EMAIL="admin@example.com" \
  -e JTVC_ADMIN_PASSWORD="CHANGE_ME_strong_password" \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal:latest
```

> The image contains only the application itself — **no keys or settings**; the 8x8 private key is provided at runtime through the `keys/` mounted volume.
> Only `linux/amd64` is provided; for other architectures (e.g. arm64), build it yourself using [option 2](#installation-option-2-docker-deployment).
> HTTPS reverse proxy setup is the same as option 2.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Updating / Upgrading

> All settings and data (accounts, rooms, audit log, usage, etc.) are stored in `DATA_DIR` (direct install) or the mounted volume (Docker), so **updates do not lose data**; JSON structures are upgraded automatically and compatibly. It is still recommended to back up the data directory and `keys/` before updating.

### Before you upgrade: what each version needs

The Docker image and the Release image already contain everything — nothing to install. For a **direct install**, check the rows for the versions you are skipping over:

| Upgrading from | What to add |
|---|---|
| before v1.10.0 | Single sign-on (optional) needs the PHP `curl` and `openssl` extensions (Debian / Ubuntu: `apt install php-curl`). |
| before v1.12.0 | Transcripts and summaries (optional) need `curl` and a **background job that runs every minute** — see "Meeting transcripts and summaries". Docker installs need it too (on the host). |
| before v1.16.0 | With a self-hosted Jibri, also update `jibri-recordings-api/server.py` on the Jibri host (path and header hardening) and `systemctl restart jibri-recordings-api`. |
| before v1.16.1 | Self-hosted Jitsi Meet on `stable-11031` or later: in `.env` set `XMPP_MUC_MODULES=token_affiliation,token_lobby_bypass`, add `JICOFO_ENABLE_AUTH=0`, and disable the old `GLOBAL_CONFIG=disable_cascading_set = false`, then `docker compose up -d`. Otherwise guests become moderators (they can record and kick people). See "Moderator permission control" in [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md). |
| before v1.16.2 | Self-hosted Jibri on `stable-11031` or later: add `SE_AVOID_STATS=true` and `SE_OFFLINE=true` to the jibri service `environment` and recreate the containers; otherwise, when the Jibri host's outbound connection is slow, starting a recording waits a long time and then says "All recorders are currently busy". See section 7 of [JIBRI-SETUP.md](JIBRI-SETUP.md). |
| before v1.14.0 | Exporting meeting minutes as PDF / DOCX / ODT needs the PHP `zlib` extension (built into the Debian / Ubuntu PHP packages) and the bundled font `lib/fonts/NotoSansTC-Regular.ttf`, which comes with `git pull`. |

Check with `php -m | grep -iE 'curl|mbstring|openssl|zlib|fileinfo|json'`. After upgrading, open **System settings**: any missing component is listed at the top, and the Transcripts card warns if the background job is not running.

### Method 1: Updating a direct install

```bash
cd /var/www/jt-vc-portal        # your installation directory

# 1) Back up (recommended)
sudo cp -a /var/jaas-data /var/jaas-data.bak-$(date +%Y%m%d)

# 2) Pull the latest code
git pull

# 3) If .htaccess was updated, re-apply it
cp dot.htaccess .htaccess

# 4) Reload (clears opcache)
sudo systemctl reload apache2
```

Afterwards, sign in and confirm the version number at the top left of the topbar (next to the site name) has been updated.

### Method 2: Updating Docker

```bash
cd /path/to/jt-vc-portal

# 1) Back up the data volume (recommended)
cp -a /opt/jt-vc-portal/data /opt/jt-vc-portal/data.bak-$(date +%Y%m%d)

# 2) Get the latest code and rebuild the image (--pull also updates the base image)
git pull
docker build --pull -t jt-vc-portal .

# 3) Replace the container (data / private key live in mounted volumes and are unaffected)
docker stop jt-vc-portal && docker rm jt-vc-portal
docker run -d --restart unless-stopped \
  -p 127.0.0.1:58189:58189 \
  -e JTVC_TRUSTED_PROXIES="127.0.0.1,172.16.0.0/12" \
  -v /opt/jt-vc-portal/keys/:/var/www/html/keys \
  -v /opt/jt-vc-portal/data/:/var/jaas-data \
  --name jt-vc-portal jt-vc-portal

# 4) Verify
docker ps --filter name=jt-vc-portal
```

> The initial administrator environment variables (`JTVC_ADMIN_*`) are only used when the account is first created and can be omitted when updating; however, `JTVC_TRUSTED_PROXIES` (and the optional `JTVC_SESSION_*`) take effect on every run, so **pass them with every `docker run`**.
> The version number is shown at the top left of the topbar after signing in, next to the site name (click it to open this project's GitHub page); use it to confirm you are on the new version.

### Method 3: Updating from a Release image

```bash
# 1) Back up the data volume (recommended)
cp -a /opt/jt-vc-portal/data /opt/jt-vc-portal/data.bak-$(date +%Y%m%d)

# 2) Download the new image, verify, and load it
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/latest/download/jt-vc-portal-<new-version>-docker-amd64.tar.gz.sha256
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/latest/download/jt-vc-portal-<new-version>-docker-amd64.tar.gz
sha256sum -c jt-vc-portal-<new-version>-docker-amd64.tar.gz.sha256
docker load < jt-vc-portal-<new-version>-docker-amd64.tar.gz

# 3) Replace the container (data / private key live in mounted volumes and are unaffected)
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

## Initial setup

1. Open the site → sign in at `/jt-login` as the initial administrator (password: see above).
2. Go to **System Settings**:
   - **Connection mode**: choose 8x8 JaaS or self-hosted Jitsi Meet and fill in the corresponding parameters.
   - **Site settings**: site name and logo.
   - **Login page path** (optional): change the login entry point to a secret path (see below).
   - **Meeting room interface**: default UI language (Traditional Chinese by default).
   - **Recording settings** (optional): recorder display name, connection to the self-hosted Jibri recording retrieval service, and retention policies.
   - **SMTP** (optional): send `.ics` invitation emails.
   - **Login log forwarding** (optional): syslog / CEF / GELF.
3. Go to **Profile** to change your password and enable 2FA.
4. Return to the dashboard to create meeting rooms.

### Connection modes

| | 8x8 JaaS | Self-hosted Jitsi Meet |
|---|---|---|
| Domain | `8x8.vc` | Your Jitsi domain |
| Required | App ID, Key ID (kid), RS256 private key | Service domain; (optional) JWT app_id + HS256 secret |
| Billing | Free Dev plan (25 MAU/month); usage beyond that is billed per the 8x8 plan | Self-operated |
| Recording | Built into 8x8 (plan-dependent) | With a self-hosted Jibri (browse, play and download in this system) |
| Transcripts | 8x8's separately billed live captions (per minute, files kept only 24 hours, never stored in this system); disabled by default here to avoid surprise charges | Jibri recording + a self-hosted jt-live-whisper: a speaker-labelled transcript after the meeting, stored in this system, data never leaves your premises |
| Meeting summary | Not available | Produced by jt-live-whisper with a self-hosted language model: key points, decisions and action items, risks, topics, speaker statistics — each citing a time in the recording |
| Minutes export | Not available | PDF / DOCX / ODT / HTML, plus plain text, SRT, JSON, Markdown |

If self-hosted Jitsi Meet uses JWT, token authentication must be enabled in prosody, and app_id / app_secret must match this system.

> **Full self-hosted integration steps** (from the official Docker-based Jitsi Meet all the way to working with this system): see **[JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md)**.

> **Important — mobile device access limitation (when using JWT)**
> Once JWT authentication is enabled (**always required for 8x8 JaaS**; for self-hosted Jitsi Meet when `ENABLE_AUTH=1`), meeting rooms only accept tokens issued by jt-vc-portal.
> **The official Jitsi Meet mobile apps (iOS / Android) cannot join directly** — they do not go through this portal, cannot obtain a token, and will be rejected.
> Mobile users should instead open the invite link in their **mobile browser** and join through this portal (the meeting is embedded, so the experience is the same).
> If a self-hosted deployment uses "anonymous mode without JWT" (`ENABLE_AUTH=0`), the mobile apps can join directly, but anyone who knows the room name can enter, which is **less secure**.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Login page path disguise

The default login entry point is `/jt-login`. In **System Settings → Login page path** you can change it to a secret path only you know (only letters, digits and `. _ -` allowed, length 1–64), reducing exposure to automated scans / brute-force attempts.

- After the change, the original `/jt-login` and any unmapped path return **404** directly; only the configured path shows the login page.
- The change does not write the actual path to the audit log / SIEM (to avoid leaking it).
- **Be sure to remember the new path.** If you forget it or get locked out, restore it from the server side via the CLI:

```bash
# Docker deployment
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php show     # show the current path
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php reset    # restore to /jt-login
docker exec -u www-data jt-vc-portal php /var/www/html/login-path.php set xxx  # set a new path directly

# Direct install (Apache + PHP): run in the project root directory
sudo -u www-data php login-path.php reset
```

---

<br>
<br>
<br>
<br>
<br>
<br>

## Recording retrieval (self-hosted Jibri)

When recording with self-hosted Jitsi Meet + Jibri, you can run the bundled `jibri-recordings-api` (a pure Python standard-library service) on the Jibri host, letting the portal **list / play / download / delete** recordings online and display the **recording host's storage capacity**.

- The service only accepts requests from this portal's source IP + a Bearer token; the portal in turn requires an administrator sign-in before proxying.
- Enter the service URL and token under **System Settings → Recording settings → Jibri recording service**; once detected, a "Recordings" tab appears in the navigation bar.
- **Retention policies** (all disabled by default): by age (keep N days), by capacity (keep a minimum of free space / cap total recording size, deleting oldest first), and automatic cleanup of leftover / incomplete recordings. Files still being recorded are never cleaned up.
- For service installation and systemd configuration, see **[JIBRI-SETUP.md](JIBRI-SETUP.md)**.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Meeting transcripts and summaries (jt-live-whisper)

> **Full setup guide** (JTLW key and certificate, portal settings, background job, permissions, export, troubleshooting): see **[TRANSCRIPTS-SETUP.md](TRANSCRIPTS-SETUP.md)**.

After a recording on the self-hosted Jibri host finishes, the portal can hand it to the speech service **jt-live-whisper (JTLW)**, which returns a transcript with speakers and a meeting summary. Results are stored by the portal next to the recording.

**Requirements**

- Recording retrieval set up (self-hosted Jibri + `jibri-recordings-api`, see above).
- A jt-live-whisper REST API (`api_revision` 2.4 or later) and an API key for this portal with the scopes `jobs:write`, `jobs:read`, `jobs:cancel`, `profiles:read`.
- Optional: the JTLW host must be able to reach `<your site>/jtlw-webhook` for completion notifications. Without it the portal still works — the background worker checks progress every minute.

**Setup**

1. **System Settings → Transcripts and summaries**: enter the JTLW URL (e.g. `https://10.0.0.30:8790`), the API key and, for a self-signed certificate, its PEM (it is trusted as given — certificate verification is never turned off; compare the SHA-256 fingerprint shown). Choose the meeting language (set it when known — "auto" decides from roughly the first 30 seconds) and whether to produce summaries. Click **Save and test connection**, then **Register webhook**.
2. **Background worker** — run it every minute:
   - Docker: add to the host's crontab `* * * * * docker exec -u www-data jt-vc-portal php /var/www/html/transcribe-worker.php`
   - Direct install: `/etc/cron.d/jtvc-transcribe` with `* * * * * www-data php /var/www/jt-vc-portal/transcribe-worker.php` (your installation directory)
   It uploads recordings one at a time, follows progress, retrieves the results and removes results whose recording is gone. Only one instance runs at a time.
3. **Permissions — Account management → Transcript permission** for each host: *off* (default), *manual* (can press "Generate transcript" on their own meetings) or *automatic* (generated when their recordings finish). When creating a room, a host who may use transcripts can switch it on or off for that meeting. Administrators can generate transcripts for any meeting. Automatic generation only processes meetings recorded after the feature was enabled.

**How it works and data retention**

- The portal streams the recording to JTLW, submits one job (recognition, speakers, punctuation correction, summary), waits for the webhook or polls, retrieves the transcript and the summary (JSON + Markdown), writes them to disk and only then tells JTLW to delete its copy. JTLW deletes the uploaded recording after processing.
- Results live in the data directory (`transcripts/<recording id>/`) and follow the recording: deleting a recording, or the Jibri retention policy removing it, deletes its transcript and summary too.
- Hosts only see transcripts of meetings they hosted; the audit log records who generated, viewed, downloaded or renamed — never the transcript content.
- Summaries are available for Chinese and English meetings; for Japanese and Korean only the transcript is produced. Speaker ids (S1, S2…) are voice clusters, not names — rename them on the transcript page.
- **Export meeting minutes (v1.14.0)**: the transcript page downloads the whole record — meeting details, summary (decisions and action items with their sources, risks, open questions, topics, speaker statistics) and the transcript with renamed speakers — as **PDF, DOCX, ODT or HTML** (HTML since v1.15.0: a single file that opens in any browser and prints cleanly). Plain text, SRT subtitles, JSON and the Markdown summary are under "Other formats". Everything is generated by the portal itself (no LibreOffice or browser engine on the server); the PDF embeds only the characters it uses from the bundled Noto Sans TC font (SIL Open Font License), so Chinese and Japanese display correctly and the text can be searched. Korean characters are not in that font.
- **Speaker suggestions (v1.13.0)**: the host's meeting page records Jitsi's "current speaker" timeline; the transcript page suggests which participant each speaker id is (with the share of overlapping time) and lets you apply it with one click or pick from the participant list. Keep the host's meeting page open for the whole meeting so the timeline is complete.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Security

Follows OWASP Top 10:2025, item by item:

- **A01 Broken Access Control**: Unauthorized pages always return 404 (no entry points revealed); rooms, recordings and transcripts are isolated by owner, so hosts only see meetings they hosted; transcripts also have a per-account permission (off / manual / automatic) and a per-meeting switch; single sign-on users get their role only from IdP groups, and anyone outside the configured groups is refused; every state change is POST + CSRF token.
- **A02 Security Misconfiguration**: Error display and version disclosure are off; every response carries a Content Security Policy (strictest by default, replaced by a nonce-based one on pages) and security headers; sensitive paths (`lib/`, `keys/`, `*.json`) are denied; `X-Real-IP` is trusted only from allow-listed reverse proxies; secrets are never shown back in the settings page; System settings lists missing components and a background job that is not running.
- **A03 Software Supply Chain Failures**: Zero external PHP packages: OIDC, PDF / DOCX / ODT generation and ZIP are implemented in-house, with no LibreOffice or other large packages; front-end CDN resources use SRI; the Jitsi IFrame API (`external_api.js`) is bundled at a pinned version with SRI instead of being loaded live from a third party; the PDF font is bundled (SIL OFL); the image build applies the latest OS security updates; Release images are built by CI from the tagged source and published with a sha256.
- **A04 Cryptographic Failures**: Passwords are bcrypt-hashed; JWTs are signed (RS256 / HS256) with lifetimes; single sign-on id_tokens are verified against the IdP public keys (JWKS), accepting only RS256 / RS384 / RS512 and rejecting `none` and algorithm confusion; the TLS certificate of the speech service is always verified (a self-signed certificate is trusted through its pasted PEM — verification is never turned off); webhooks are HMAC-signed; session cookies are HttpOnly, SameSite and Secure.
- **A05 Injection**: All output escaped and input sanitized; protection against email header injection and CSV formula injection; line breaks stripped from forwarded syslog to prevent forged entries; exported DOCX / ODT / HTML content is fully escaped with control characters removed; a nonce-based Content Security Policy on every page (including the meeting page) forbids inline script.
- **A06 Insecure Design**: Gateway architecture, secure by default (guests must give a name, entry only while the host is online), least-privilege roles; the portal never handles AD passwords — company accounts always go through an OIDC identity provider, which provides MFA and brute-force protection; nor does the portal talk to a language model — transcripts and summaries come from a self-hosted speech service, which is told to delete its copy once the results are stored here.
- **A07 Authentication Failures**: OIDC single sign-on: Authorization Code + PKCE (S256) + state + nonce, accounts bound by iss + sub and never merged into local accounts by email; an "SSO only" mode with an emergency local admin restricted by IP; local accounts have TOTP two-factor authentication (replay-protected) and login lockout by real source IP **plus per account** (against password guessing from many IPs); idle / absolute session timeouts, with sessions revoked on password change or forced sign-out; an optional disguised login path (never leaked by redirects).
- **A08 Software or Data Integrity Failures**: every data file is written *atomically* — the new content is written in full to a temporary file and then swapped in with a single rename, so a crash or power loss mid-write leaves the previous complete file rather than a broken one — and *under a lock*, so simultaneous changes are applied one after another instead of overwriting each other; webhooks (8x8 usage, speech service) are verified by HMAC signature and time window, with duplicates removed by idempotency key / event ID; jobs sent to the speech service carry an Idempotency-Key so a resend is never processed twice; the speech service is told to delete its copy only after the results are safely on disk; settings import uses a whitelist.
- **A09 Security Logging & Alerting Failures**: A complete audit log: sign-ins, single sign-on success / failure, and every action on rooms, accounts, settings, recordings and transcripts (never the transcript content); every entry forwarded to your SIEM in real time (syslog / CEF / GELF) with line breaks stripped to prevent forged entries.
- **A10 Mishandling of Exceptional Conditions**: Fail-safe degradation: read failures fall back to defaults, mail / log-forwarding failures never block the main flow, errors are not leaked; any failed single sign-on check means refusal (fail-closed); an unreachable speech service is retried with back-off and marked failed with a readable reason after 24 hours, and failed summaries are redone automatically; missing components produce a readable message instead of an error 500; playback failures distinguish a timed-out login, a missing file and an unavailable service.
- Private keys, settings and runtime data are all stored in mounted volumes and **never enter version control** (see `.gitignore`).
- **Known limitations (by design of Jitsi):** the room name is part of the invite link, so use random names or lobby mode for sensitive meetings; in self-hosted mode without JWT anyone who knows a room name can join directly on the Jitsi domain (enable JWT — the settings page warns about this); a guest who has already received a meeting token can rejoin directly within its lifetime (6 h) — use lobby mode if that matters.
- Every release must pass unit, integration, browser e2e tests and an OWASP ZAP scan with **zero High / Medium alerts** — see [TEST_CHECKLIST.md](TEST_CHECKLIST.md).

---

<br>
<br>
<br>
<br>
<br>
<br>

## License

This project is released under the [GNU General Public License v3.0](LICENSE) (GPL-3.0-only).

> Versions up to and including v1.7.0 were published under the Apache License 2.0; starting with v1.8.0 the project is licensed under GPL-3.0.

The bundled font `lib/fonts/NotoSansTC-Regular.ttf` (Noto Sans TC, used for PDF export) is licensed under the SIL Open Font License 1.1 — see `lib/fonts/OFL.txt`.

<br>
<br>
<br>
<br>
<br>
<br>

## Disclaimer

This software is provided "as is", without warranty of any kind, express or implied. Users are solely responsible for the security and compliance of their deployment environment (including the terms and fees of third-party services such as 8x8 JaaS). The author shall not be liable for any direct or indirect damages arising from the use of this software.

<br>
<br>
<br>
<br>
<br>
<br>

## Author / Links

- Author: Jason Cheng ([jasoncheng7115](https://github.com/jasoncheng7115))
- Project: <https://github.com/jasoncheng7115/jt-vc-portal>
- Bug reports: via GitHub Issues
