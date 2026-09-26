# Changelog

> 繁體中文: [CHANGELOG_zh-TW.md](CHANGELOG_zh-TW.md) · 日本語: [CHANGELOG_ja.md](CHANGELOG_ja.md)

## v1.9.0 — Japanese

- **Japanese interface**: the portal UI is now available in Traditional Chinese, English and **Japanese** (`lang/ja/*.php`, ~675 strings). Browsers preferring `ja*` get Japanese automatically; switch from the account menu, sign-in / guest page header, or Profile → Interface language.
- **Jitsi meeting language** "Follow interface language" maps Japanese users to the Japanese meeting UI; default email templates and default names also follow Japanese.
- **Docs**: every Markdown document now has a `_ja.md` Japanese version, and each language line links the other two languages; in-app doc links open the Japanese guides for Japanese users.
- **GitHub Pages**: Chinese / English / Japanese switching with browser detection; the appendix doc cards now show one button for the current language, equal-height cards with bottom-aligned buttons.
- **Tests**: `check-i18n.php` checks every language (missing keys, placeholders, Traditional-only characters in Japanese); unit, integration and browser e2e runs cover Japanese.

## v1.8.0 — Room deletion, session revocation, license change to GPL-3.0

- **License**: the project is now licensed under the **GNU GPL v3.0** (v1.7.0 and earlier remain Apache-2.0).
- **Delete / cancel a room** from the dashboard (owner or admin, with confirmation). If the room had invitees and SMTP is enabled, a calendar **cancellation** (`METHOD:CANCEL`) is emailed so it disappears from their calendars; a running host session is recorded first. New audit action `room_delete`.
- **Calendar invites**: each resend increases `SEQUENCE` so calendars replace the old event; UIDs include the room's creation time (a later room with the same name no longer collides); line folding never splits a UTF-8 character.
- **Sessions**: changing a password (by the user or an admin) signs that account out on all other devices; admins can also force-sign-out an account ("Sign this account out of all devices").
- **Sign-out** is POST + CSRF only (a cross-site link can no longer sign users out).
- **Audit log**: configurable retention (default 365 days, 30–3650) with automatic pruning; field length limits against oversized input.
- **Recordings list** reads meeting records / rooms once per request (faster for hosts with many recordings).
- **Jibri recordings API**: out-of-range `Range` requests return 416; docs now recommend a firewall / TLS proxy for the token-authenticated HTTP service.
- **Warnings**: the settings page warns when self-hosted mode runs without JWT; the create-room form explains that easy-to-guess room names can be guessed. README lists the known Jitsi limitations.

## v1.7.0 — Bilingual interface (Traditional Chinese / English)

- **Portal UI i18n**: every page, message, email default and audit label is translatable (`t()` / `th()`, keys are the original zh-TW text; English in `lang/en/*.php`). About 660 strings translated.
- **Language detection**: `?lang=` → per-user preference → cookie → browser `Accept-Language` → English. Switch from the account menu, the header of sign-in / guest pages, or Profile → Interface language (Auto / 繁體中文 / English).
- **Jitsi meeting language**: new default "Follow interface language" (each host / guest gets the meeting UI in their own language); explicit languages still work.
- **Email invitations**: default subject / body follow the sender's language; custom templates are used as-is. Default site / recorder / sender names follow the language unless customized.
- **Links to docs** point to the English or `_zh-TW` document according to the interface language.
- **Tests**: `tests/check-i18n.php` gate (no untranslated strings, no missing keys, placeholder consistency), i18n unit tests, bilingual integration and browser e2e runs.

## v1.6.3 — Security hardening

- **Data integrity**: all data files are now written atomically with locking; concurrent host heartbeats, room creation and settings changes can no longer wipe or overwrite each other.
- **Access control**: host heartbeat / leave endpoints are POST + CSRF only and restricted to the room's owner (or an admin); `/start` is POST-only (dashboard "Enter" buttons are now forms), so a cross-site link can no longer mark a host as present.
- **Recordings**: a host can no longer see recordings of an earlier meeting that used the same room name as one they created later.
- **Authentication**: per-account lockout (10 failures in 15 minutes, across IPs) in addition to per-IP lockout; unauthenticated requests to `/verify`, `/twofa`, `/twofa-verify` return 404 and `/logout` no longer reveals a disguised login path.
- **Secrets**: the self-hosted JWT shared secret and SMTP password are no longer echoed into the settings page (leave blank to keep).
- **JWT**: guest tokens explicitly disable recording / live streaming / transcription / outbound calls; host tokens last 12 h and guest tokens 6 h (long meetings can reconnect), with `nbf`.
- **Meeting statistics**: a crashed browser no longer inflates the next session's duration (stale sessions are closed at the last heartbeat).
- **Content Security Policy**: nonce-based, no `'unsafe-inline'` for scripts or style elements; meeting pages now send a CSP that only allows the Jitsi domain as an iframe.
- **Supply chain**: the Jitsi IFrame API (`external_api.js`) is bundled and pinned with SRI instead of being loaded live from a third-party domain (`tools/update-jitsi-external-api.sh` refreshes it).
- **Tests**: new unit, integration, browser e2e (Playwright) and OWASP ZAP scripts under `tests/`; bilingual release checklist `TEST_CHECKLIST.md`.
- **Docs**: all Markdown documents are now English by default with `_zh-TW.md` Chinese versions; the GitHub Pages site switches between Chinese and English automatically.

## v1.6.2 — Security hardening

Trusted reverse-proxy allow-list (`JTVC_TRUSTED_PROXIES`), session idle / absolute timeouts, CSP on admin pages, SRI for CDN scripts, SIEM log-injection protection, TOTP replay protection, constant-time login for unknown accounts, `room-status` requires an invite, 10-character minimum passwords, and the Apache `headers` module fix.

## v1.6.1 and earlier

See the [GitHub Releases](https://github.com/jasoncheng7115/jt-vc-portal/releases) page.
