# jt-vc-portal Release Test Plan and Checklist

> 繁體中文: [TEST_CHECKLIST_zh-TW.md](TEST_CHECKLIST_zh-TW.md) · 日本語: [TEST_CHECKLIST_ja.md](TEST_CHECKLIST_ja.md)

> Author: Jason Cheng · GitHub [@jasoncheng7115](https://github.com/jasoncheng7115) · Project [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

> **Rule: before bumping `APP_VERSION` in `app/config.php`, run this entire checklist once; only release when everything passes.**
> If any item fails (including any High / Medium finding from ZAP), do not release — fix it first, then re-run.

**Release flow**: run this checklist → all green → bump the version → deploy → sync `github/` → commit / push → tag → package and upload that version's Docker image to the Release → verify the download and SHA-256 via the public link.

**Every item must have a corresponding test**: whenever you add or change a feature, add the matching items to this checklist (both the Chinese and English versions) at the same time, and add anything that can be automated to `tests/`.

---

## Table of Contents

0. [Pre-release and secret checks](#0-pre-release-and-secret-checks)
1. [Automated tests](#1-automated-tests)
2. [ZAP vulnerability scan (release gate)](#2-zap-vulnerability-scan-release-gate)
3. [Functional tests](#3-functional-tests)
4. [Internationalization (i18n)](#4-internationalization-i18n)
5. [Security tests](#5-security-tests)
6. [Deployment hardening verification](#6-deployment-hardening-verification)
7. [OWASP Top 10:2025 item-by-item review](#7-owasp-top-102025-item-by-item-review)
8. [Penetration spot checks](#8-penetration-spot-checks)
9. [Release and release assets](#9-release-and-release-assets)
10. [Automated test mapping](#10-automated-test-mapping)

---

## 0. Pre-release and secret checks

- [ ] `APP_VERSION` rule: patch++ for fixes, minor++ for features.
- [ ] Every change in this release has been reviewed item by item against OWASP Top 10:2025 (section 7).
- [ ] **Public GitHub content must not contain secrets / private data / credentials** (code, `.md` files, Pages, screenshots and test scripts all count):
  - [ ] `git -C github ls-files | grep -iE '\.(key|pem|pk|pub|p12|pfx|jsonl)$|private\.|settings\.json|users\.json|auto-allow|INITIAL_ADMIN|logo\.img|^release/'` returns nothing.
  - [ ] `grep -rIl "BEGIN.*PRIVATE KEY" github/` returns nothing.
  - [ ] No real JaaS tenant (`vpaas-magic-cookie-` followed by a real ID), internal domains, internal IPs, real email addresses, or real passwords / tokens (example values must be obviously fake).
  - [ ] Screenshots have accounts, IPs, meeting room names and other identifying information masked.
- [ ] No secrets inside the Docker image: `docker run --rm --entrypoint sh <image> -c 'find / \( -name private.key -o -name "*.json" -path "*jaas*" -o -name users.json \) 2>/dev/null'` returns no results.
- [ ] README / every `.md` (English, `_zh-TW` and `_ja`) / Pages match this release's features, and the version number has been updated.

## 1. Automated tests

- [ ] PHP syntax: `docker run --rm -v "$PWD/app":/app -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'`
- [ ] Unit tests: `tests/run-unit.sh` is all green.
- [ ] Integration tests: `tests/run-integration.sh` is all green.
- [ ] i18n check: `tests/check-i18n.php` reports no untranslated strings and no missing keys (from v1.7.0).
- [ ] Run everything above at once: `tests/run-all.sh` (lint → i18n → unit → integration).
- [ ] Browser e2e: `tests/run-e2e.sh` passes in both zh-TW and English (host joins meeting, guest joins, no CSP / SRI errors).

## 2. ZAP vulnerability scan (release gate)

- [ ] Run `tests/zap/run-zap.sh <version>`: against a throwaway local container, perform two rounds — "unauthenticated" and "authenticated" — of spider + passive + active scans.
- [ ] Both reports (`zap-reports/zap-<version>-anon.md`, `-authed.md`) have **High = 0 and Medium = 0**.
- [ ] Review each Low / Informational finding and record the "accepted / false positive" rationale in the release notes (e.g. the test container is HTTP; Secure cookies / HSTS are provided by the production reverse proxy).
- [ ] Reports are kept only in the local `zap-reports/` and never committed to GitHub (they may contain internal information).

## 3. Functional tests

### 3.1 Authentication and accounts
- [ ] An admin is created automatically on first start: uses `JTVC_ADMIN_PASSWORD` if set; otherwise a random password is generated and written to `INITIAL_ADMIN_PASSWORD.txt`.
- [ ] Log in with username or email (case-insensitive); wrong credentials show a generic message and the number of attempts remaining.
- [ ] 2FA: enable (scan QR / manual key) → login requires a code → disabling requires the password; the same code cannot be reused.
- [ ] Profile: change display name; changing the password requires the old password, a new password of at least 10 characters, and matching confirmation.
- [ ] Account management (admin): create / edit / disable / delete / change role / reset password; you cannot delete or disable yourself; at least one enabled admin must remain.
- [ ] Once an account is disabled, its existing session is invalidated on the next request.
- [ ] Own password change: sessions on all other devices end, the current session stays; an admin password reset ends all sessions of that account.
- [ ] Account management → "Sign this account out of all devices" ends all of that account's sessions without affecting other accounts.
- [ ] Sign-out is POST + CSRF only: `GET /logout` does not sign out; the header "Sign out" button and the 2FA page "Cancel and sign in again" work.
- [ ] Session idle timeout (default 30 minutes) and absolute timeout (default 12 hours) take effect.
- [ ] Role-based tabs: a host sees only "Meeting Rooms" + (when Jibri is configured) "Recordings"; an admin sees everything.

### 3.2 Meeting rooms (host)
- [ ] Create a meeting room: custom name / random name; non-ASCII characters are removed automatically and spaces become `-`.
- [ ] Entering an existing name in the creation form → blocked, with form contents preserved.
- [ ] Recent list: shows schedule, host status badge, invitee count; copy invite link, QR dialog.
- [ ] "Enter / Host now / Enter from QR dialog" submit via POST forms and enter the meeting correctly; they do not wipe the existing lobby setting or invitee list.
- [ ] Entering a meeting room owned by someone else is blocked (except for admins).
- [ ] Delete room: owner / admin can delete (custom confirm dialog); others cannot; with invitees and SMTP enabled a calendar cancellation is sent (Google / Outlook / Apple remove the event); audit has `room_delete`.
- [ ] The Jitsi IFrame API is served from the bundled `assets/vendor/jitsi-external-api.js` with SRI; the meeting page CSP `frame-src` allows only the Jitsi domain. After updating it with `tools/update-jitsi-external-api.sh`, run the e2e test.
- [ ] Meeting page: Jitsi loads correctly, the language is correct, the share button (QR / copy) works, and recording start / stop notices appear.
- [ ] Host heartbeat reports every 15 seconds; closing the tab sends a leave notification; 45 seconds without a heartbeat counts as offline.
- [ ] Lobby mode: the lobby is enabled automatically once the host joins, and guests must be admitted one by one.
- [ ] "Disable video bandwidth saving" (on by default): other participants' video is not turned off automatically due to bandwidth.
- [ ] Long meetings (over 1 hour) can reconnect after a mid-meeting disconnect (host JWT is valid for 12 hours).

### 3.3 Scheduling, email and .ics
- [ ] Set start / end times; an end time earlier than the start time is rejected.
- [ ] With SMTP enabled, enter attendee emails → an invitation with an `.ics` is sent, and Google / Outlook / Apple can add it to the calendar.
- [ ] Invalid emails are rejected with a message.
- [ ] Re-sending invites for the same room: `.ics` SEQUENCE increases, UID stays, calendars replace the old event; long Chinese titles still display correctly after folding.
- [ ] SMTP test email succeeds; failure messages are displayed correctly.

### 3.4 Guests
- [ ] Both the invite link `/room/<name>` and the legacy `/invite?room=` work.
- [ ] Status pages: waiting for host (spinner), countdown (flip clock), ended; checks automatically at the configured interval and joins automatically once the host arrives.
- [ ] When joining is allowed, a name must be entered first; clicking the invite link again requires re-entering the name.
- [ ] Guest JWT disables recording / live streaming / transcription / outbound calls; the toolbar does not show transcription or live streaming.

### 3.5 Meeting records and statistics
- [ ] When the host leaves, `meetings.jsonl` is written (duration, peak concurrent participants, participant join / leave times).
- [ ] Browser crash without a leave notification: on the next heartbeat / join, or when the room is purged, the session is settled using the last heartbeat time, so the duration does not balloon.
- [ ] `/usage`: stat cards, last-30-days activity chart, meeting duration timeline, Top 25 by duration, host leaderboard, participant details; JaaS mode additionally shows MAU and historical trend.
- [ ] The record retention days setting takes effect (cleanup does not lose new records written concurrently).

### 3.6 Recordings (self-hosted Jibri)
- [ ] Jibri service URL / token settings, connection status, and recorder count display.
- [ ] Recording list, search (meeting room / host / participant), expand participants, online playback (Range seeking), download.
- [ ] Delete, cleanup by policy, capacity bar, retention policy (time / capacity / leftovers, disabled by default) are admin-only.
- [ ] A host can only see and fetch recordings of sessions they hosted; after a room name is re-created by someone else, the new owner cannot see the old sessions.
- [ ] Recording status (ok / recording / incomplete / orphan) is determined correctly; recordings in progress cannot be deleted.
- [ ] Jibri recordings API: `tests/test-jibri-api.sh` passes (auth, Range 206 / 416, path traversal).

### 3.7 System settings (admin)
- [ ] In self-hosted mode with "No JWT" the settings page shows a warning; the create-room form explains the guessable-name risk.
- [ ] Connection mode: JaaS and self-hosted settings are saved separately, and switching does not overwrite either; self-hosted mode shows the requirement hints.
- [ ] Secret fields (self-hosted JWT shared secret, SMTP password, Jibri token) are not echoed back to the page; submitting empty keeps the existing value; SMTP has a checkbox to clear the password.
- [ ] Site name / logo upload (PNG / JPEG / WebP / GIF, up to 2MB) / restore default.
- [ ] Switching among the 22 themes works, and dark theme styling is correct.
- [ ] Meeting room interface: Jitsi language, record retention days, guest check interval (seconds).
- [ ] Meeting room customization (self-hosted mode only): mute / camera off, resolution, default view, toolbar toggles, bandwidth saving toggle.
- [ ] Recording settings: recorder display name.
- [ ] Plan MAU limit, billing cycle start day, manual correction of current-period usage (JaaS only).
- [ ] SIEM forwarding: syslog / CEF / GELF × UDP / TCP; test send succeeds.
- [ ] Settings export / import: whitelisted keys, including logo; round-trip is consistent.
- [ ] Login path obfuscation: after changing the path, the old path returns 404 and the new path allows login; the `login-path.php show|reset|set` CLI works, and web access returns 404.

### 3.8 Audit log
- [ ] Every action (login success / failure / lockout, logout, create / enter meeting room, guest join, send invitation, account CRUD, settings changes, password / 2FA, recording download / delete / cleanup, export) is logged.
- [ ] Filter by action type, keyword and date; pagination; CSV export uses the current filters, includes a UTF-8 BOM, and has formula-injection protection.
- [ ] Each entry is forwarded to the SIEM in real time (when enabled).
- [ ] Audit log retention (default 365 days) works: older entries are pruned when the audit page is opened; oversized account / detail strings are truncated.

### 3.9 USAGE webhook (JaaS)
- [ ] Correct Authorization or HMAC signature → counted toward the current period's unique devices; the same idempotencyKey is not counted twice.
- [ ] Non-USAGE events return 200 and are ignored; bad signature / expired timestamp → 403.

### 3.10 Other UI
- [ ] Error pages (400 / 401 / 403 / 404 / 500 / 502 / 503) use the site theme.
- [ ] The custom confirmation dialog replaces the browser's native confirm (delete account, delete recording, etc.).
- [ ] Click-to-sort table columns, collapsible cards, top-right account menu (closes on outside click / Esc).
- [ ] Layout is usable at mobile width.

## 4. Internationalization (i18n)

(From v1.7.0)
- [ ] UI language detection priority: `?lang=` → logged-in user's profile setting → cookie → browser Accept-Language → English.
- [ ] Language switcher in the top right / on guest pages (繁體中文 / English / 日本語) takes effect immediately and is remembered; for logged-in users it is saved to their profile.
- [ ] Walk through every page with an English browser (login, 2FA, dashboard, meeting, each guest state, accounts, profile, audit, statistics, recordings, settings, error pages): no leftover Chinese (except the language name "繁體中文").
- [ ] Walk through every page with a Chinese browser: text matches the pre-change version, with no leftover English.
- [ ] Walk through every page with a Japanese browser: no leftover Chinese / English UI text (from v1.9.0; except language names such as "繁體中文" / "English").
- [ ] Dynamic JS text (copy success, confirmation dialogs, countdown units, recording notices) follows the language.
- [ ] The language switch endpoint `/set-lang?l=&r=` only redirects to same-site relative paths (`//evil` and `https://…` always go to `/`); direct access to the dictionary directory `/lang/` → 403.
- [ ] Profile → "Interface language" offers Auto / 繁體中文 / English / 日本語 and applies right after sign-in.
- [ ] Default site name / recorder name / sender name follow the language when not customized; saving settings in the English UI does not store the English defaults as custom values.
- [ ] The Jitsi meeting language can be set to "Follow UI language".
- [ ] Default email / .ics templates follow the sender's language; custom templates are used as-is.
- [ ] `tests/check-i18n.php`: every `t()` key has a translation in every language (English, Japanese) with matching placeholders, Japanese contains no Traditional-only characters, and there are no Chinese strings in the code that are not wrapped in `t()`.
- [ ] Every `.md` has an English (default), a `_zh-TW.md` and a `_ja.md` version; each header's language line links the other two.
- [ ] GitHub Pages: Chinese / English / Japanese switching, automatic detection by browser language, and `?lang=` can specify the language and is remembered; each appendix doc card shows exactly one button for the current language, both cards are equal height with buttons aligned at the bottom.

## 5. Security tests

### 5.1 Access control (A01)
- [ ] Unauthenticated access to `/dashboard`, `/accounts`, `/settings`, `/recordings`, `/usage`, `/audit-log`, `/profile`, `/meeting` always returns 404.
- [ ] A host hitting admin endpoints (`/accounts`, `/settings`, `/audit-log`, `/audit-export`, `/usage`, `/account-save`, `/account-delete`, `/save-settings`, `/save-site`, `/set-theme`, `/settings-export`, `/settings-import`, `/recordings-action`) → 404.
- [ ] A host requesting `/recordings-file` with someone else's recording id → 404.
- [ ] `/host-heartbeat`, `/host-left`: GET → 405; unauthenticated → 403; missing CSRF → 403; non-owner → 403.
- [ ] `/start`: GET makes no changes; POST without CSRF → 403.
- [ ] `/room-status`: without an invitation to that room and not logged in → only returns `unknown`.

### 5.2 CSRF
- [ ] Every state-changing POST validates `_csrf` (or `X-CSRF-Token`); missing / wrong → 403.
- [ ] Cross-site pages using links / forms / fetch to trigger meeting room creation, host presence, or settings changes all fail.

### 5.3 XSS / injection
- [ ] Display names, guest names, participant names, site name, and audit keywords containing `<script>`, `"`, `</script>`, `'` → correctly escaped on every page.
- [ ] Dynamic values in JS on the meeting page / statistics page go through `json_encode`.
- [ ] CSV export prefixes values starting with `= + - @`.
- [ ] CR / LF are stripped from email headers and SIEM records.

### 5.4 Authentication (A07)
- [ ] 5 failures from the same IP within 10 minutes → locked for 30 minutes; 3 consecutive lockouts → 24 hours; 2FA failures also count.
- [ ] 10 failures on the same account (across IPs) within 15 minutes → account locked for 15 minutes; behavior is identical for non-existent accounts; the lockout file does not store usernames in plaintext.
- [ ] Whether an account exists cannot be distinguished from messages or response times.
- [ ] Session id is rotated after login; the cookie has HttpOnly, SameSite=Lax, and Secure under HTTPS.
- [ ] Login path obfuscation does not leak: when not logged in, `/verify` (GET), `/twofa`, `/twofa-verify` → 404; `/logout` → redirects to `/`.

### 5.5 Data integrity
- [ ] Concurrent writes (multiple hosts' heartbeats + meeting room creation + settings changes) lose no data; readers never see an empty file (covered by unit tests).
- [ ] Settings import only accepts whitelisted keys with correct types.
- [ ] Webhook signatures are compared in constant time, with a ±5-minute replay window.

### 5.6 Files and paths
- [ ] Logo upload: MIME whitelist, 2MB, fixed filename; disguised extensions are rejected; served with an image Content-Type + nosniff.
- [ ] Direct access to `/lib/`, `/keys/`, `*.key|pem|pk|pub|json` → 403.
- [ ] Recording ids are validated with a regular expression, preventing path traversal (on both the portal and the Jibri API).

## 6. Deployment hardening verification

- [ ] The container port is reachable only by the reverse proxy (bind to `127.0.0.1` on the same host; on a separate host rely on `JTVC_TRUSTED_PROXIES` + firewall).
- [ ] `JTVC_TRUSTED_PROXIES` is set; a spoofed `X-Real-IP` from an untrusted source does not affect rate limiting or audit IPs.
- [ ] HTTPS end to end; the reverse proxy sends HSTS; `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and CSP are in effect.
- [ ] Host permissions: `keys/` 750 (group www-data), `private.key` 600 (www-data); `data/` 750, files 640; no `private.key` in the build context.
- [ ] Inside the container, `www-data` can read the private key and write to the data directory.
- [ ] `INITIAL_ADMIN_PASSWORD.txt` has been deleted after the first login.
- [ ] `docker build --pull`; Trivy scan with `--ignore-unfixed` shows 0 HIGH / CRITICAL.

## 7. OWASP Top 10:2025 item-by-item review

For every change in this release:
- [ ] **A01 Broken Access Control**: new endpoints have requireLogin / requireAdmin and resource ownership checks; state changes are POST-only + CSRF.
- [ ] **A02 Security Misconfiguration**: errors are not displayed, versions are not exposed, file permissions are minimized, secrets are not echoed back to pages.
- [ ] **A03 Software Supply Chain Failures**: no new PHP dependencies; CDN resources have SRI; base image pulled with `--pull`.
- [ ] **A04 Cryptographic Failures**: passwords use bcrypt; JWT signing and lifetimes are reasonable; transport is HTTPS.
- [ ] **A05 Injection**: output escaping, `json_encode`, ASCII room names, mail / log / CSV injection handling.
- [ ] **A06 Insecure Design**: rate limiting (IP + account), secure defaults, least privilege.
- [ ] **A07 Authentication Failures**: 2FA, session timeouts, generic error messages, login path not leaked.
- [ ] **A08 Software or Data Integrity Failures**: atomic writes + locking, import whitelist, webhook signature verification.
- [ ] **A09 Security Logging and Alerting Failures**: new actions have audit log entries and are forwarded to the SIEM; secrets are not logged.
- [ ] **A10 Mishandling of Exceptional Conditions**: failures fall back to defaults / explicit error pages, without interrupting the main flow or leaking stack traces.

## 8. Penetration spot checks

Spot-check on every release; test everything for major changes:
- [ ] Unauthorized enumeration of all admin endpoints and recording files.
- [ ] Host horizontal / vertical privilege escalation (others' meeting rooms, heartbeats, recordings, accounts, settings).
- [ ] CSRF PoC (cross-site links, forms, fetch).
- [ ] XSS PoC (all name fields, settings fields, audit search).
- [ ] Spoofing `X-Real-IP` / `X-Forwarded-For` to bypass rate limiting.
- [ ] Login brute force and 2FA brute force (IP-level and account-level lockout).
- [ ] Webhook forgery / replay; SIEM / mail / CSV injection.
- [ ] Uploading non-images / disguised images / oversized files; recording id path traversal.

## 9. Release and release assets

- [ ] Deploy to production: lint → build (`--pull`) → replace the container → health check (home page 200, login page, entering a meeting).
- [ ] Sync `github/` (copy only changed files; do not use `rsync --delete`), then run the section 0 secret checks again.
- [ ] commit / push; create and push the `vX.Y.Z` tag.
- [ ] After pushing the `vX.Y.Z` tag, **GitHub Actions (`.github/workflows/release.yml`) automatically** builds this version's Docker image (verifying no secrets are inside), packages `jt-vc-portal-X.Y.Z-docker-amd64.tar.gz` + `.sha256`, and creates the Release (notes taken from the CHANGELOG). Confirm the Actions run succeeded.
- [ ] Version numbers and download links in the README and Pages are updated.
- [ ] Download the Release image and `.sha256` from the public links, verify with `sha256sum -c`, and confirm `docker load` + start work.

## 10. Automated test mapping

| Item | Test |
|---|---|
| Atomic writes, concurrent read-modify-write, readers never see an empty file, JSONL cleanup loses nothing | `tests/unit/test_store.php` |
| Room owner, canHost, heartbeat / leave settlement, crashed-session settlement, concurrent room creation, settlement before purge, sanitize, evaluate | `tests/unit/test_rooms.php` |
| IP rate limiting, account-level lockout, recording ownership, JWT (guest features / lifetime / HS256), settings concurrency | `tests/unit/test_security.php` |
| Security headers, sensitive paths 403, login path not leaked, login, `/start` POST-only, heartbeat / leave CSRF + ownership, settings page does not echo secrets, account-level lockout, guest flow | `tests/run-integration.sh` |
| Language detection / Accept-Language / t() / English dictionary integrity / default email templates / meeting language follows UI | `tests/unit/test_i18n.php` |
| No leftover text per page in English / Chinese, html lang, ?lang=, cookie, /set-lang open-redirect guard, per-user language, lang/ 403 | `tests/run-integration.sh` (i18n section) |
| Untranslated strings / missing keys / placeholder consistency | `tests/check-i18n.php` |
| Real browser: host join, guest join, iframe, SRI, CSP (zh-TW + English) | `tests/run-e2e.sh` |
| .ics folding / REQUEST / CANCEL / SEQUENCE, session settlement on room delete, audit length limits and retention pruning | `tests/unit/test_ical_audit.php` |
| GET sign-out ignored, password change / force sign-out invalidates sessions, room delete permissions, audit | `tests/run-integration.sh` (v1.8.0 section) |
| Jibri recordings API auth / Range / path traversal | `tests/test-jibri-api.sh` |
| Vulnerability scan | `tests/zap/run-zap.sh` |
