# jt-vc-portal Roadmap

> 繁體中文: [ROADMAP_zh-TW.md](ROADMAP_zh-TW.md)

> **Author**: Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　Project [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)

This document tracks planned features for jt-vc-portal. For features already released, see [README.md](README.md); this roadmap only lists items that are "not yet started or in progress". The order does not indicate priority, and the actual schedule will be adjusted according to demand.

> Current stable version: **v1.6.3**.

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

### 3. Multi-language portal interface

Allow the admin interface itself (not the meeting room) to support multiple languages, with users able to switch the display language.

- The admin interface is currently in Traditional Chinese; the plan is to extract UI strings into locale resources to support switching between languages.
- Provide a per-user language preference (remembering each user's choice) and a site-wide default language setting.
- Separate from the existing "meeting room interface language (60 Jitsi languages)": this item covers localization of the portal's admin interface itself.

---

<br>
<br>
<br>
<br>
<br>
<br>

## Completed (highlights)

The following features were released in v1.4–v1.6; see the README and the setup guides for details:

- Self-hosted Jibri recording retrieval (online playback / download / deletion, host storage capacity, retention policies, hosts can access their own sessions).
- Meeting participant statistics (peak concurrent participants + join/leave timeline), meeting duration ranking Top 25.
- Login page path disguise, audit log CSV export, error pages that follow the site theme.
- 60 Jitsi UI languages, meeting room video bandwidth-saving toggle, custom confirmation dialogs.

---

> Feedback on requirements and new feature suggestions are welcome via GitHub Issues.
