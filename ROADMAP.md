# jt-vc-portal Roadmap

> 繁體中文: [ROADMAP_zh-TW.md](ROADMAP_zh-TW.md)

> **Author**: Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　Project [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

This document tracks planned features for jt-vc-portal. For features already released, see [README.md](README.md); this roadmap only lists items that are "not yet started or in progress". The order does not indicate priority, and the actual schedule will be adjusted according to demand.

> Current stable version: **v1.7.0**.

---

## Planned features

### 1. AD / LDAP authentication integration

Allow host accounts to connect to an organization's existing directory service, eliminating the need for a separate set of credentials in this system.

- Support for Active Directory and standard LDAP (including LDAPS / StartTLS encrypted connections).
- Connection settings configured in the admin UI (server, Base DN, bind account, user search filter, group mapping).
- Sign-ins are authenticated against the directory service; local accounts and AD/LDAP accounts can coexist (hybrid mode).
- Directory groups are mapped to system roles (admin / host), and a corresponding local account record is created automatically on first sign-in.
- Integrated with the existing audit log, fail2ban-style lockout and 2FA mechanisms; passwords are never stored, and the existing bcrypt local account logic is unchanged.

### 2. jt-live-whisper meeting speech transcription integration

Integrate with [jt-live-whisper](https://github.com/jasoncheng7115/jt-live-whisper) to provide speech-to-text and follow-up value-added content for meetings.

- **Live speech transcription**: convert speech to text during the meeting (captions / live transcript).
- **Transcripts**: produce a complete transcript after the meeting ends, viewable and downloadable in the admin UI.
- **Meeting summaries**: automatically generate key-point summaries, decisions and action items from the transcript.
- Integrated with "recording retrieval": transcripts / summaries are stored alongside the corresponding recording, and hosts can access the results of the meetings they hosted.
- Reuses the existing permission model (hosts can only access their own sessions) and retention policies.

### 3. More portal interface languages

v1.7.0 ships Traditional Chinese / English (locale resources, browser-language detection, per-user preference). Next:

- Add more interface languages (e.g. Simplified Chinese, Japanese): add a `lang/<code>/*.php` dictionary and extend `I18n::SUPPORTED`.
- A site-wide default language setting (used when the browser language cannot be matched).

---

<br>
<br>
<br>
<br>
<br>
<br>

## Completed (highlights)

The following features were released in v1.4–v1.7; see the README, CHANGELOG and the setup guides for details:

- **v1.7.0**: bilingual portal interface (Traditional Chinese / English, detected from the browser, switchable from the account menu or profile; Jitsi meeting language can follow the interface; default email templates follow the language).
- **v1.7.0**: security hardening (atomic locked writes, host heartbeat ownership + CSRF, per-account lockout, nonce CSP, bundled Jitsi API with SRI, …), full test checklist and ZAP release gate.

- Self-hosted Jibri recording retrieval (online playback / download / deletion, host storage capacity, retention policies, hosts can access their own sessions).
- Meeting participant statistics (peak concurrent participants + join/leave timeline), meeting duration ranking Top 25.
- Login page path disguise, audit log CSV export, error pages that follow the site theme.
- 60 Jitsi UI languages, meeting room video bandwidth-saving toggle, custom confirmation dialogs.

---

> Feedback on requirements and new feature suggestions are welcome via GitHub Issues.
