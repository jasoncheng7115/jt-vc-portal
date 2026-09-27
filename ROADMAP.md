# jt-vc-portal Roadmap

> 繁體中文: [ROADMAP_zh-TW.md](ROADMAP_zh-TW.md) · 日本語: [ROADMAP_ja.md](ROADMAP_ja.md)

> **Author**: Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　Project [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

This document tracks planned features for jt-vc-portal. For features already released, see [README.md](README.md); this roadmap only lists items that are "not yet started or in progress". The order does not indicate priority, and the actual schedule will be adjusted according to demand.

> Current stable version: **v1.10.0**.

---

## Planned features

### 1. Company accounts (AD / LDAP) — done in v1.10.0 via SSO

Delivered as **OIDC single sign-on**: Keycloak (on its own host) federates Active Directory over LDAPS and jt-vc-portal trusts Keycloak via OIDC — see [KEYCLOAK-SETUP.md](KEYCLOAK-SETUP.md). AD groups map to admin / host roles, accounts are created on first sign-in, MFA and brute-force protection live in Keycloak.

- **Direct AD / LDAP binding from the portal will not be implemented**: the portal is Internet-facing, and binding directly would expose AD password checks (and AD account lockouts) to the Internet.

### 2. jt-live-whisper meeting speech transcription integration

Integrate with [jt-live-whisper](https://github.com/jasoncheng7115/jt-live-whisper) to provide speech-to-text and follow-up value-added content for meetings.

- **Live speech transcription**: convert speech to text during the meeting (captions / live transcript).
- **Transcripts**: produce a complete transcript after the meeting ends, viewable and downloadable in the admin UI.
- **Meeting summaries**: automatically generate key-point summaries, decisions and action items from the transcript.
- Integrated with "recording retrieval": transcripts / summaries are stored alongside the corresponding recording, and hosts can access the results of the meetings they hosted.
- Reuses the existing permission model (hosts can only access their own sessions) and retention policies.

### 3. More portal interface languages

v1.7.0 shipped Traditional Chinese / English and v1.9.0 added Japanese (locale resources, browser-language detection, per-user preference). Next:

- More interface languages (e.g. Simplified Chinese, Korean): add a `lang/<code>/*.php` dictionary and extend `I18n::SUPPORTED`.
- A site-wide default language setting (used when the browser language cannot be matched).

---

<br>
<br>
<br>
<br>
<br>
<br>

## Completed (highlights)

The following features were released in v1.4–v1.10; see the README, CHANGELOG and the setup guides for details:

- **v1.10.0**: OIDC single sign-on (Keycloak + AD), SSO-only mode with emergency local admin, Keycloak deployment SOP and scripts.
- **v1.9.0**: Japanese interface and documentation (portal UI, all Markdown docs `_ja.md`, GitHub Pages), Pages doc-card layout cleanup.
- **v1.8.0**: delete / cancel rooms with calendar cancellation, sign-out-everywhere on password change, audit log retention, license changed to GPL-3.0.
- **v1.7.0**: bilingual portal interface (Traditional Chinese / English, detected from the browser, switchable from the account menu or profile; Jitsi meeting language can follow the interface; default email templates follow the language).
- **v1.6.3**: security hardening (atomic locked writes, host heartbeat ownership + CSRF, per-account lockout, nonce CSP, bundled Jitsi API with SRI, …), full test checklist and ZAP release gate.

- Self-hosted Jibri recording retrieval (online playback / download / deletion, host storage capacity, retention policies, hosts can access their own sessions).
- Meeting participant statistics (peak concurrent participants + join/leave timeline), meeting duration ranking Top 25.
- Login page path disguise, audit log CSV export, error pages that follow the site theme.
- 60 Jitsi UI languages, meeting room video bandwidth-saving toggle, custom confirmation dialogs.

---

> Feedback on requirements and new feature suggestions are welcome via GitHub Issues.
