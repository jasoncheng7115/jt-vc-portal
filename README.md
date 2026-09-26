<p align="center"><img src="docs/images/icon.svg" alt="jt-vc-portal" width="96" height="96"></p>

# jt-vc-portal v1.9.0 — Meeting Management System

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
| PHP extensions | `openssl`, `fileinfo`, `json`, `mbstring` | Same |
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
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.9.0/jt-vc-portal-1.9.0-docker-amd64.tar.gz
curl -LO https://github.com/jasoncheng7115/jt-vc-portal/releases/download/v1.9.0/jt-vc-portal-1.9.0-docker-amd64.tar.gz.sha256

# 2) Verify integrity (should print OK)
sha256sum -c jt-vc-portal-1.9.0-docker-amd64.tar.gz.sha256

# 3) Load the image (creates the jt-vc-portal:1.9.0 and :latest tags)
docker load < jt-vc-portal-1.9.0-docker-amd64.tar.gz

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
docker exec -u www-data jaas-auth php /var/www/html/login-path.php show     # show the current path
docker exec -u www-data jaas-auth php /var/www/html/login-path.php reset    # restore to /jt-login
docker exec -u www-data jaas-auth php /var/www/html/login-path.php set xxx  # set a new path directly

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

## Security

Follows OWASP Top 10:2025, item by item:

- **A01 Access control**: unauthorized pages return 404, rooms are isolated by owner (including host heartbeat / leave and recording access), every state change is POST + CSRF token.
- **A02 Security configuration**: error display and version disclosure disabled, security headers, access to sensitive paths denied.
- **A03 Supply chain**: zero external PHP packages; front-end CDN resources use SRI integrity checks; the Jitsi IFrame API (`external_api.js`) is bundled and pinned with SRI instead of being loaded live from a third party; the latest OS security updates are applied when the image is built.
- **A04 Cryptography**: bcrypt passwords, JWT signing, webhook HMAC, secure session cookies.
- **A05 Injection**: output escaping, input sanitization, email header injection protection, nonce-based Content Security Policy (no inline-script allowance) on every page including the meeting pages.
- **A06 Secure design**: gateway architecture, secure by default (guests must give a name, entry only while the host is online), least-privilege roles.
- **A07 Authentication**: TOTP 2FA, fail2ban-style login lockout by real source IP **plus per-account lockout** against distributed guessing, session idle / absolute timeouts, optional login page path disguise (no redirect leaks it).
- **A08 Data integrity**: atomic, locked writes for all data files (no lost updates under concurrency); webhooks are verified by HMAC signature, and duplicate events are removed via idempotency keys.
- **A09 Logging and alerting**: complete audit log + real-time SIEM forwarding.
- **A10 Exception handling**: fail-safe degradation — read failures fall back to defaults, email / forwarding failures do not block the main flow, errors are not leaked.
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
