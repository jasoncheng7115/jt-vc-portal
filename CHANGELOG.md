# Changelog

> 繁體中文: [CHANGELOG_zh-TW.md](CHANGELOG_zh-TW.md) · 日本語: [CHANGELOG_ja.md](CHANGELOG_ja.md)

## v1.22.1 — PHP security update

- Follows the PHP security advisory of 2026-10-21 (patched in 8.4.27 / 8.3.36): the Docker and Release images now use PHP 8.4.27 and turn off `session.upload_progress`, which this app does not use.
- Direct installs: update PHP to 8.4.27 / 8.3.36 or later and set `session.upload_progress.enabled=Off` in `php.ini`.
- Test T86.

## v1.22.0 — Shared slides stay sharp, no recording IDs in the audit log

- **Screen sharing back to 5 frames per second (sharp text)**: it was set to 15–30 fps, which makes Jitsi treat the share as motion and lower the resolution first when the presenter's upload is short, so slide text blurred (it happened to a participant sharing only a PowerPoint window). Measured with the presenter limited to 1 Mbps upload: at 5 fps guests still received 1920×1080, at 15–30 fps only 960×540; with enough bandwidth both are full size. Settings → meeting room customization gains "Screen sharing": sharp text (default) / smooth motion (for videos).
- **The audit log no longer shows recording UUIDs**: entries read like "room "tung-graylog", the 10/15 21:40 recording (47 min)"; older entries are shown the same way, and deleted recordings appear as "recording (ID c97c8526)". The full ID is still stored, searchable, and included in the text sent to the SIEM.
- Tests T83, T84, T85 (production screen-share quality, with tc simulating limited upload).

## v1.21.0 — Waveform for long recordings, clickable HTML minutes, names instead of S1 in summaries

- **Waveform on the transcript page**: recordings over 60 MB (meetings longer than about 15 minutes) used to get no waveform, because the browser would have to download and decode the whole file. The recording host's `jibri-recordings-api` now computes 1,200 loudness values (on first request, cached afterwards), so long recordings get a waveform too; with an older recording service, files up to 60 MB are still decoded in the browser and larger ones show why there is no waveform (following jtdt 1.16.93). **Self-hosted Jibri: update `server.py`** (see JIBRI-SETUP).
- **Downloaded HTML minutes** now look like the web page: citations are chips (time · speaker, in the speaker's colour) that jump to that part of the transcript and highlight it; the topic timeline and speaker statistics have bars; the transcript is coloured by speaker; a "back to top" button. Still a single file with no script — the same offline, forwarded or printed.
- **Speaker codes inside summary text** (e.g. "send it to S1 for review") are replaced by the names you assigned: summary text, decision / action text, owner and due date, topic titles — on the web page and in PDF / DOCX / ODT / HTML / Markdown exports. Only renamed codes are replaced, never when glued to other letters or digits (S12 is not hit by S1), and product names like "AWS S3" or "Amazon S3" are left alone; the stored data is not rewritten, so renaming again stays correct.
- Tests T80, T81, T82.

## v1.20.0 — Transcript badge in the room list, site-styled language picker

- Recent rooms list and the "created" panel: rooms whose recordings will be transcribed automatically show a badge such as "Transcript and summary · Mainly Chinese (Mandarin)" (with the meeting's main language; for Japanese and Korean meetings the speech service makes no summary, so it says "Transcript" only). The rule matches what actually happens: the per-meeting switch wins, otherwise the host account's "automatic" setting; no badge when the host has no transcript permission or transcripts are turned off.
- "Main meeting language" now uses a site-styled dropdown instead of the browser's native list: mouse or keyboard (↑↓, Home / End, Enter, Esc), consistent in dark themes; submitting without a language turns the button border red and the browser's message still appears.
- Fix: guests who joined within 15 seconds before the host left were missing from the meeting record (the participant list in meeting statistics was short). Joins and leaves are now reported within about 2 seconds, and hanging up or closing the tab sends the current roster with the leave notice; repeated leave notices don't add an extra session.
- Docs (pushed after v1.19.0): in-meeting screenshots on Pages; a meeting bandwidth reference (measured upload / download per computer and for the Jitsi server, phone data usage, how the numbers are calculated) and a link to the online bandwidth calculator; minimum PHP raised to 8.3 (PHP 8.2 security support ends 2026-12-31); README warns not to add security headers for the portal domain on the reverse proxy (a Permissions-Policy stops the embedded meeting from using the camera and microphone), with a matching JITSI-MEET-SETUP troubleshooting row.
- Tests T77 (real Chrome through the reverse proxy: no duplicate headers, the embedded Jitsi actually gets the camera and microphone), T78, T79.

## v1.19.0 — Up to 720p on a phone in a 4-person tile view

- Room customization's "Keep higher quality on small tiles" becomes a three-level "Receive quality for small tiles": **Default** (Jitsi automatic, about 180p on a phone with 4 people in tile view) / **Higher** (about 360p) / **High** (about 720p). Measured on a phone with 4 people in tile view: Higher receives about 1.2 Mbps and High about 2 Mbps; on a computer both give about 720p in tile view; 2-person meetings and speaker view are unaffected. Sites that ticked the option in v1.18.0 become "Higher" after upgrading.
- Jitsi only has 180p / 360p / 720p layers (measured to stay the same even with a 1080p camera), so the step above 360p is 720p.
- Fix: a threshold that equals one of Jitsi's built-in values (such as 180) is updated in place by Jitsi and then overridden by a later entry, so it had no effect; 190 is used now.
- Tests T74 and T75 now cover the three levels.

## v1.18.0 — Video quality option for phones and multi-person meetings

- Room customization adds "Keep higher quality on small tiles (phones, 3+ person tile view)" (off by default). Jitsi chooses the receive quality from each tile's size: on phones, or in tile view with 3 or more people, tiles are small and get only 180p, which looks blurry on a phone. With the option on, in a 4-person tile view a phone can receive 360p per person (was 180p) and a desktop 720p (was 360p); 2-person meetings are unaffected (measured). The trade-off is more bandwidth for every participant and more battery use and heat on phones.
- Measured on production with an emulated phone browser: a 2-person meeting connects peer to peer and the phone sends and receives 720p; from 3 people the media goes through the video bridge, and by default the phone receives 180p per tile and 360p for the main speaker in speaker view.
- For JTLW API 2.6: when speaker detection falls back to the previous method, the transcript page now explains why (more than 8 speakers / the speech service could not use the newer speaker detection this time), in our own wording and the interface language rather than the speech service's Chinese note.
- Tests T74, T75, T76.

## v1.17.0 — New speaker detection (JTLW API 2.5)

- **More accurate speakers**: transcript jobs now request the speech service's newer speaker detection (`hints.diarize_engine: "auto"`, NVIDIA Nemotron). In JTLW's test a 7-person panel went from "3 speakers" to "7 speakers" and wrongly attributed segments dropped from 28% to 3%; speaker statistics and citations in the meeting summary improve with it. Taiwanese meetings are not split by speaker and are unaffected.
- System settings → Transcripts and summaries adds "Speaker detection": Automatic (recommended, default) / Previous method. Speech services before API 2.5 do not accept the field, so the portal checks the version first and leaves it out — jobs never fail because of it.
- With more than 8 speakers the speech service falls back to the previous method, and the transcript page notes that the speaker grouping may be less accurate.
- As JTLW recommends, the number of speakers is still not sent (the participant count is not the number of people who spoke in the recording).
- Tests T72, T73.

## v1.16.3 — Transcript and summary ticked by default

- When creating a room, "Generate transcript and summary after recording" is now **ticked by default** (it only appears for accounts allowed to use transcripts); the main meeting language must still be chosen, and the form is blocked until it is. Untick it for meetings that don't need it. Previously it was ticked by default only for accounts whose transcript permission is "automatic".
- Test T71.

## v1.16.2 — Audit log shown in the viewer's language

- **Audit log details follow the viewer's language**: details used to be fixed in the language of whoever wrote them, and background jobs (transcript done / failed / automatic retries) and command-line actions have no user language, so they were always written in English and showed up in English on the Chinese UI. Entries now keep the original string and its values, and the page, keyword search and CSV export translate them into the viewer's language; SIEM forwarding still carries the text as written. Entries recorded before the upgrade keep their original text.
- Entries from scheduled jobs and the command line now show the account `system` and an empty source IP (previously `0.0.0.0`).
- Traditional Chinese wording: the audit log column 「詳情」 is now 「說明」, and 「點擊」 is now 「點選」 throughout.
- **Transcript waveform**: before playback, and before the cursor moved over the waveform, an empty dark box appeared in the top-left corner (the time tooltip's frame — its style overrode the hidden attribute). Fixed, plus a site-wide rule so any element marked hidden is never displayed.
- **Meeting minutes export**: an extra blank line before each section heading (Summary, Decisions and action items, Events and impact…), consistent across PDF / DOCX / ODT / HTML.
- **Self-hosted Jibri (stable-11031): pressing record waited a long time and then said "All recorders are currently busy"**: the new Jibri's Selenium contacts the internet when a recording starts, and a slow outbound connection exceeds jicofo's 15-second wait. [JIBRI-SETUP.md](JIBRI-SETUP.md) adds the `SE_AVOID_STATS=true` / `SE_OFFLINE=true` settings and troubleshooting (including what to do when "Stop recording" does nothing).
- The production entry-path test now covers recording: starts within 12 seconds, stop really stops, two rooms recording at once, recording files produced, both Jibris back to idle; the meeting simulation also checks start time and stop.
- Tests T67–T70.

## v1.16.1 — Self-hosted Jitsi moderator permissions (for stable-11031)

- **Guests became moderators after upgrading self-hosted Jitsi to `stable-11031`**: Jitsi moved the `token_affiliation` module into Jitsi itself and changed how it works, so the `GLOBAL_CONFIG=disable_cascading_set = false` line from the old guide no longer has any effect, and jicofo promotes everyone holding a token to moderator — guests could start recording, kick people and end the meeting. Switched to the approach recommended by the Jitsi maintainers: `JICOFO_ENABLE_AUTH=0`, so moderator status comes only from the token. **Self-hosted Jitsi users: update `.env` as described in "Moderator permission control" in [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md).**
- **Host rejoining a lobby room**: with the new module, a host who disconnects and rejoins a room with the lobby on was held in the lobby (and if only guests remained, nobody could let them in). Host tokens now carry `lobby_bypass: true`, which together with the `token_lobby_bypass` module shipped in the Jitsi image lets the host straight in with moderator rights restored; guests do not carry it and still knock. JaaS mode is unaffected.
- **Docs**: the Jitsi / Jibri setup guides now target `stable-11031`; the upgrade sections add pre-checks (no meetings, Jibri idle), backup, keeping both hosts on the same version, rollback, and a note that `stable-11146` and later is a structural change.
- Tests T63–T66: the host token flag, plus a walk through every entry path and gate against the real Jitsi (roles, guests cannot record, lobby, host rejoin, waiting / countdown / ended, create in 3 languages), required on every release and after every Jitsi / Jibri upgrade.

## v1.16.0 — Main meeting language (including Taiwanese Hokkien)

- **Main meeting language is required**: when "Generate transcript and summary" is ticked while creating a room, you must choose the meeting's main language (mainly Chinese / English / Japanese / Korean / Taiwanese Hokkien); the speech service picks its recognition model from it. The Recordings list has a new "Main language" column, and "Generate transcript" asks for the language when the meeting did not set one. Regenerating keeps the previous language.
- **Taiwanese Hokkien**: uses the speech service's dedicated Taiwanese profile (MediaTek Breeze-ASR-26, outputs Chinese characters). That profile does not separate speakers; the transcript page explains this and you can label speakers by hand. Meeting summaries support Chinese, English and Taiwanese Hokkien.
- Following JTLW's guidance: choose Taiwanese Hokkien whenever the meeting has Taiwanese (the dedicated model also understands mixed-in Mandarin), and Mainly Chinese when there is only the occasional Taiwanese sentence; summaries of Taiwanese meetings are marked "for reference only", and the transcript page hides the empty speaker column, speaker statistics and speaker suggestions (exports carry the same note).
- **Meeting statistics charts redesigned**: "Activity in the last 30 days" is now a stacked bar chart (the three curves used to overlap near zero and overshoot the real values); the duration ranking uses gradient bars, shows the longest meeting on top (it was upside down) and labels each bar with its duration; faint horizontal grid only, level date labels, dot legends, dark tooltips, and dark-theme aware. The meeting-duration timeline is collapsed by default and opens on click.
- **Video service health**: the admin dashboard shows a "System status" bar — Jitsi Meet (web page and XMPP meeting endpoint) and Jibri (recording service, each recorder's own health report, disk space) — in green / orange / red with the reason; checked after the page loads, cached for 30 seconds, with a refresh button.
- **Friendly message when Jitsi is down**: hosts and guests joining a meeting while Jitsi Meet is unreachable see a "Video service temporarily unavailable" page (their invitation link stays valid, the page retries every 30 seconds) instead of a meeting that never loads; if the meeting frame does not respond within 20 seconds or Jitsi reports a fatal error, a "Cannot connect to the meeting" message with a Retry button appears.
- **Automatic retry of failed recognition** (for JTLW v2.25.3): a retryable recognition failure (e.g. the speech service's GPU server temporarily down) is retried automatically after 10, 30 and 60 minutes without uploading the recording again; the Recordings list shows "Waiting to retry". When retries run out, or on Regenerate, the job and the recording JTLW kept are deleted.
- `meeting.detailed` has been retired by JTLW; it is switched to `meeting.balanced` automatically.
- Fix: with self-hosted Jitsi set to "no JWT", pressing "Start hosting" sent the host back to the dashboard, so the meeting could never be entered.
- Fix: jobs without speaker separation stayed "processing" forever (the 400 from the speakers layer was treated as a temporary error).
- Jibri recording service (`jibri-recordings-api/server.py`) hardened following GitHub CodeQL: a recording id only maps to an existing regular directory inside the recordings folder (no hidden directories or symlinks), and download file names keep only safe characters to prevent header injection. **Update server.py on your Jibri host.**
- Tests T51–T56; the Jibri service test also covers hidden directories, symlinks, `..` deletion and download file names.

## v1.15.0 — HTML export, transcripts setup guide and Pages refresh

- **HTML export**: the meeting minutes can also be downloaded as a single HTML file (styles inline, no scripts, no external resources) that opens in any browser, reads well on a phone and prints cleanly.
- **DOCX export**: the blue bar left of each section heading was drawn outside the left page margin; headings are now indented so the bar sits inside the margin (Word and LibreOffice).
- The "Who spoke how much" section is now called "Speaker statistics" (page and exports).
- Download buttons show only the file extension: PDF / DOCX / ODT / HTML.
- The restore command shown under System settings → Login path now uses the container name `jt-vc-portal`, matching the docs.
- **Docs**: new [TRANSCRIPTS-SETUP.md](TRANSCRIPTS-SETUP.md) (complete setup guide for transcripts and meeting summaries: JTLW key and certificate, background job, permissions, export, troubleshooting); Pages now covers single sign-on, transcripts and summaries, speaker suggestions and minutes export, the comparison table adds transcripts / summary / export, all screenshots were retaken with fictional demo data plus four new ones; the security section uses the official OWASP Top 10:2025 category names and covers the SSO, transcript and export controls; "atomic writes" are explained; the ROADMAP reason for not binding AD / LDAP directly is rewritten.
- Test T45 also checks the heading indent, element order and HTML safety; `test_txexport.php` now prints its totals and exit code (a failure there used to not fail the unit tests).

## v1.14.0 — Export meeting minutes as PDF / DOCX / ODT

- **Export meeting minutes**: the transcript page downloads the whole record — meeting details, summary (decisions and action items with owner, due date and sources; events, risks, open questions; topic timeline; who spoke how much) and the full transcript with renamed speakers and speaker colours — as **PDF**, **DOCX** or **ODT**. Plain text, SRT, JSON and the Markdown summary moved into an "Other formats" menu.
- Generated entirely by the portal in PHP — no LibreOffice or browser engine on the server, no new package in the Docker image. The PDF embeds only the characters it uses from the bundled Noto Sans TC font (SIL OFL 1.1), so Chinese and Japanese display correctly and the text can be selected and searched.
- **Clear playback errors**: when the login has timed out, pressing play now says so and offers a sign-in link (it used to say the recording file was gone). A deleted file, an unplayable format and an unreachable recording service each get their own message.
- **Upgrade checks**: System settings lists missing PHP extensions (openssl, curl, mbstring, fileinfo, json, zlib) and what they affect, and the Transcripts card warns when the background job has not run for 10 minutes. The README has a per-version upgrade table for direct installs, lists `curl` and `zlib` in the requirements, and the cron line for direct installs points to the installation directory.
- Docs: example commands use the container name `jt-vc-portal` (as in the `docker run` examples) instead of `jaas-auth`.
- Tests T44–T50.

## v1.13.1 — Transcript resilience and status display

- **JTLW unreachable while processing**: after 24 hours without reaching the speech service a job is marked failed (with a readable reason) instead of staying "processing" for ever; "Regenerate" starts again.
- **Summary auto-retry**: when the summary fails because the language model is temporarily unavailable, it is redone automatically after 10, 30 and 60 minutes (transcript kept, no re-recognition); only after that does it wait for a manual "Redo summary".
- **Recordings list**: transcript status shown as chips the same height as the buttons (failed, waiting, cancelled, processing), every column vertically centred, the reason on hover and in the expanded row.
- **Recordings in progress**: play, download and delete are greyed out while a recording is still being made (the server refuses them too); the expanded row explains that the participant record comes after the meeting.
- **Easier to tell apart**: a finished transcript is a green "✓ View transcript & summary" button, one not yet produced is a dashed "✦ Generate transcript" button; cancelling a job in progress is a small ✕ at the end of its status chip.
- **Tooltips**: each action button in the recordings list shows its own tooltip (it used to show the row's "click to expand").
- Tests T38–T41; a production meeting simulation (two speakers, Jibri recording, real transcript and summary, speaker suggestions) is now part of every release check.

## v1.13.0 — Speaker suggestions from Jitsi

- **Who is S1?** During the meeting the host's page records Jitsi's own "current speaker" (dominant speaker) timeline and stores it with the meeting. On the transcript page each speaker id (S1, S2…) gets suggested participant names with the share of overlapping time (e.g. "Amy 86%"); click one, or "Apply the most likely person to all". The recording start is estimated from the file time, so the best offset within ±10 seconds is searched automatically. Suggestions only — nothing is renamed until you click.
- **Pick from the participant list** when renaming a speaker.
- Tests T32–T37 (timeline cleaning, storing with the meeting, alignment with a time offset, ambiguous and missing timelines, suggestion panel, apply all).

## v1.12.0 — Meeting transcripts and summaries (jt-live-whisper)

- **Transcripts and meeting summaries**: finished Jibri recordings can be sent to jt-live-whisper (JTLW, `api_revision` 2.4) — streamed upload, one job for recognition, speakers, correction and summary, webhook (hex HMAC-SHA256, 300-second window, deduplicated by event id) with a per-minute background worker as fallback, results written to disk before JTLW is told to delete its copy. Summary: key points, decisions and action items, events, risks, open questions, topic timeline and speaking time, every item citing the recording time.
- **Permissions**: per-account transcript permission (off / manual / automatic), a per-meeting switch when creating a room (cannot exceed the account permission), admins can generate for any meeting; hosts only see their own meetings. Automatic generation only covers meetings recorded after the feature was enabled.
- **Transcript page** (following Jason Tools Doc Tools): waveform player with speaker tooltip, click a time or a citation to jump there, current segment highlighted, speaker colours, rename a speaker everywhere or just one segment, TXT / SRT / JSON / Markdown download, notices for uncorrected text, trailing silence and missing speaker separation.
- **Retention and audit**: results follow the recording (deleted with it or by the Jibri retention policy, together with the JTLW job record); audit events for generate / done / failed / cancel / delete / view / download / rename, never the content.
- **Language menu**: the top-right language switch now shows only the current language and opens a menu on click.
- **Tests**: 16 unit tests and 62 integration / browser tests against the JTLW mock server and a stub Jibri service (T01–T31).

## v1.11.1 — Settings sidebar, SSO display name, fixes

- **Settings page**: the section index is now a sidebar on the left (one entry per line, short labels, stays in place while scrolling; a scrollable row on narrow screens). Changing only the `#` part of the URL switches the card too.
- **Recordings page**: the "System settings → Recording settings" links open the recording settings card directly.
- **SSO display name**: `configure-realm.sh` now maps AD `givenName` and `displayName` and sends `display_name`; set the portal's display name claim to `display_name` to show the directory display name (previously only the surname could appear). New test S30.

## v1.11.0 — Settings section index; Keycloak kit improvements

- **Settings page section index**: a row of chips at the top lists every settings card; click one to show only that card (the URL becomes `/settings#id`, so it can be bookmarked or shared), "All" shows everything. The selection survives reloads and saving. Cards hidden by the connection mode are hidden in the index too.
- **Keycloak kit / SOP**: the Keycloak 26 admin console needs a browser secure context and showed "Something went wrong" over `http://<IP>:8080`. It is now served over **HTTPS on 8443** (self-signed certificate in `keycloak/certs/`); `KC_ADMIN_URL` in `realm.env` makes `configure-realm.sh` set the **master realm Frontend URL** to the internal admin URL, and the nginx example refuses `/realms/master`. New test S27.
- **Keycloak UI languages**: `configure-realm.sh` enables internationalization (`LOCALES`, default `en,zh-Hant,ja`; `DEFAULT_LOCALE`) for the portal realm and the admin console, so login pages follow the browser language, and the language menu shows "繁體中文" / "Traditional Chinese (繁體中文)" instead of the raw code `zh-Hant`. New test S28.

## v1.10.0 — Single sign-on (OIDC) with Keycloak

- **OIDC single sign-on** for hosts and admins (Keycloak, Microsoft Entra ID or any OIDC IdP): Authorization Code + PKCE S256, state and nonce, id_token signature verification against the IdP's JWKS (RS256/384/512 only), iss / aud / azp / exp / nonce checks. Design follows the sibling projects jt-doc-tools and jt-ipam.
- **This system never connects to AD / LDAP directly.** On-premises AD is federated by Keycloak on its own host; a complete deployment SOP (`KEYCLOAK-SETUP.md`, EN / zh-TW / ja) and ready-to-use scripts (`keycloak/`: docker compose, idempotent `configure-realm.sh`, nginx example) are included.
- **Groups → roles**: admin / host groups from the IdP; users in no group are refused. Accounts are bound by (issuer, sub) and never auto-merged with local accounts that share a username or email.
- **SSO-only mode**: local password sign-in can be restricted to an emergency admin from allowed IP ranges; enabling it requires an enabled local admin. Emergency CLI `sso-cli.php` restores password sign-in.
- **Sign-out** also signs out of the IdP (RP-initiated logout with id_token_hint). SSO accounts have no local password or portal 2FA (MFA is enforced at the IdP).
- **Keycloak hardening in the provided realm script**: OTP required for everyone, brute-force detection below the AD lockout threshold, only members of the two portal groups visible, admin console kept off the Internet.
- **Tests**: 24 unit tests (forged / tampered / `alg=none` / HS256-confusion tokens, claims, state replay, mini IdP flow), 45 real-Keycloak browser tests (OTP enrolment and TOTP sign-in, group mapping, conflicts, disabled accounts, SSO-only, brute-force lockout, PKCE and redirect-URI enforcement, logout), ZAP scan with SSO enabled. Checklist items S01–S26.

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
