# Changelog

> 繁體中文: [CHANGELOG_zh-TW.md](CHANGELOG_zh-TW.md)

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
