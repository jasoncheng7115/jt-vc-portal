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
- [ ] README / every `.md` (English, `_zh-TW` and `_ja`) / Pages match this release's features, and the version number has been updated; new features have a Pages feature card, a row in the JaaS / self-hosted comparison and a setup guide; **screenshots of changed screens have been retaken with the screenshot script (fictional data)**, and no screenshot shows real accounts, site names, addresses or IPs.

## 1. Automated tests

- [ ] PHP syntax: `docker run --rm -v "$PWD/app":/app -w /app php:8.4-cli sh -c 'for f in $(find . -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'`
- [ ] Unit tests: `tests/run-unit.sh` is all green.
- [ ] Integration tests: `tests/run-integration.sh` is all green.
- [ ] i18n check: `tests/check-i18n.php` reports no untranslated strings and no missing keys (from v1.7.0).
- [ ] Run everything above at once: `tests/run-all.sh` (lint → i18n → unit → integration).
- [ ] Browser e2e: `tests/run-e2e.sh` passes in both zh-TW and English (host joins meeting, guest joins, no CSP / SRI errors).
- [ ] Production entry paths and gates (T64–T66): `tests/prod-entry-gates.sh` (maintainer, after deployment; also after Jitsi / Jibri upgrades).

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
- [ ] While the source IP is locked, the login page is display-only: a lock message and disabled fields, no form that posts to `/verify` (v1.12.0).

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
- [ ] T63 Self-hosted Jitsi: the host JWT carries `lobby_bypass: true`; guests never do (it is removed even if passed in).
- [ ] T64 Real Jitsi: guests are not moderators, and a guest sending "Start recording" does not start a recording; a guest admitted from the lobby is not a moderator either.
- [ ] T65 Real Jitsi, lobby mode: the lobby turns on when the host joins, and guests need the host's approval to enter; when the host disconnects and rejoins (with a guest still in the room) they are not held in the lobby and are still the moderator.
- [ ] T66 Real Jitsi, every entry path and gate: "Enter" from the recent list, dashboard "Create → Host now" (3 languages), `/room/` and the legacy `/invite?room=` links, waiting page with no Jitsi loaded before the host arrives, automatic opening once the host arrives, name required, host sees the guest's name, correct participant count, Jitsi UI language follows the portal, countdown page, ended page, back to waiting after the host leaves, session written to meeting records. **Run on every release and after every Jitsi / Jibri upgrade.**
- [ ] T70 Real Jitsi recording: after the host presses start, recording begins within 12 seconds (jicofo waits only 15), and stop really stops it; two rooms recording at once (two Jibris) both start and stop, both recording files are produced, and both Jibris return to idle afterwards. **Run on every release and after every Jitsi / Jibri upgrade.**
- [ ] T71 Create room: for accounts allowed to use transcripts, "Generate transcript and summary after recording" is ticked by default, and the required main meeting language is shown right away with nothing selected; submitting without a language is blocked; unticking it removes the language requirement.

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
- [ ] Settings page section index (v1.11.0): a chip for every visible settings card (cards hidden by the connection mode are hidden in the index too); clicking one shows only that card and sets `#id` in the URL; reloading or `/settings#id` keeps it; after saving you stay on the same card; "All" shows every card; no CSP errors.
- [ ] Login path obfuscation: after changing the path, the old path returns 404 and the new path allows login; the `login-path.php show|reset|set` CLI works, and web access returns 404.

### 3.8 Audit log
- [ ] Every action (login success / failure / lockout, logout, create / enter meeting room, guest join, send invitation, account CRUD, settings changes, password / 2FA, recording download / delete / cleanup, export) is logged.
- [ ] Filter by action type, keyword and date; pagination; CSV export uses the current filters, includes a UTF-8 BOM, and has formula-injection protection.
- [ ] Each entry is forwarded to the SIEM in real time (when enabled).
- [ ] Audit log retention (default 365 days) works: older entries are pruned when the audit page is opened; oversized account / detail strings are truncated.
- [ ] T67 Audit log details are shown in the viewer's language (transcript entries written by the scheduled job appear in Chinese on the Chinese UI and in English on the English UI); keyword search and CSV export also use the viewer's language; entries from scheduled jobs / the command line show the account `system` and an empty source IP.

### 3.9 USAGE webhook (JaaS)
- [ ] Correct Authorization or HMAC signature → counted toward the current period's unique devices; the same idempotencyKey is not counted twice.
- [ ] Non-USAGE events return 200 and are ignored; bad signature / expired timestamp → 403.

### 3.10 Other UI
- [ ] Error pages (400 / 401 / 403 / 404 / 500 / 502 / 503) use the site theme.
- [ ] The custom confirmation dialog replaces the browser's native confirm (delete account, delete recording, etc.).
- [ ] Click-to-sort table columns, collapsible cards, top-right account menu (closes on outside click / Esc).
- [ ] Layout is usable at mobile width.
- [ ] Language menu (v1.12.0): the top-right switch shows only the current language; clicking opens a menu listing every language with the current one marked; Esc or a click outside closes it; choosing a language switches the interface.

### 3.11 Single sign-on (SSO / OIDC, from v1.10.0)

> This system never connects to AD / LDAP directly; company accounts always go through an OIDC IdP (Keycloak on its own host — see [KEYCLOAK-SETUP.md](KEYCLOAK-SETUP.md)). Item numbers map to the tests in section 10.

- [ ] S01 Disabled by default; when disabled, `/sso-login` and `/sso-callback` return 404 and the sign-in page has no SSO button.
- [ ] S02 The issuer returned by discovery must exactly match the configured one.
- [ ] S03 IdP endpoints must use HTTPS by default (can be turned off for an internal IdP).
- [ ] S04 Cloud metadata hosts are blocked; the IdP URL may not contain credentials; redirects are not followed.
- [ ] S05 The authorization request carries PKCE S256, state, nonce, the fixed redirect_uri (`/sso-callback`) and a scope containing openid; the token exchange sends the code_verifier and client authentication.
- [ ] S06 state is single-use: a mismatch or replaying the same callback URL is rejected and audited.
- [ ] S07 The login transaction expires after 10 minutes.
- [ ] S08 id_token signature: only RS256 / RS384 / RS512 accepted; another key, tampered content, `alg=none` and HS256 confusion are all rejected; an unknown kid re-fetches the JWKS; a failed signature never falls back to userinfo.
- [ ] S09 Claim checks: any wrong iss, aud, azp, exp, iat, nonce or sub is rejected.
- [ ] S10 When the id_token has no groups, userinfo is used — but its sub must equal the id_token's sub.
- [ ] S11 Groups → role: admin groups first, then host groups; users in no group are refused; case-insensitive, Keycloak paths (/A/B) match.
- [ ] S12 Provisioning: bound by (issuer, sub); same username or email as an existing account → refused (never auto-merged); a disabled SSO account loses its sessions immediately and can't sign in again; group sync never demotes the last admin.
- [ ] S13 SSO accounts have no local password: password sign-in fails and an admin can't set one; Profile hides password and 2FA; Accounts shows an SSO badge.
- [ ] S14 SSO only: from a disallowed IP the local password form is hidden and a direct POST to `/verify` (even with a valid CSRF token) is blocked; the emergency local admin can sign in from an allowed IP; empty list = localhost only.
- [ ] S15 "SSO only" can't be enabled without an enabled local admin; IP / CIDR format is validated.
- [ ] S16 Sign-out redirects to the IdP end_session (with id_token_hint) and the portal session is cleared.
- [ ] S17 With SSO enabled, the CSP `form-action` includes the IdP origin (so sign-out can redirect to the IdP).
- [ ] S18 Audit has `sso_login` / `sso_fail`; users only see a generic error; reasons are stripped of control characters and length-limited.
- [ ] S19 SSO failures count toward the source-IP rate limit; a locked source can't start SSO.
- [ ] S20 Settings page: the client secret is never echoed and blank keeps it; "Save and test connection" retrieves the IdP configuration and signing keys; settings export includes `oidc`.
- [ ] S21 Emergency CLI `sso-cli.php show|disable-sso-only|disable` works; web access returns 404.
- [ ] S22 The ZAP scan covers `/sso-login` and `/sso-callback` with zero High / Medium.
- [ ] S23 Keycloak: the first sign-in forces OTP enrolment; later sign-ins require a TOTP code; the same code can't be reused within its time window.
- [ ] S24 Keycloak: 5 consecutive failures lock the account temporarily (threshold below AD's); the client enforces PKCE S256, authorization code flow only, confidential; requests without PKCE or with an unregistered redirect_uri are rejected.
- [ ] S25 `configure-realm.sh` can be re-run: it succeeds, the client secret stays the same and the AD bind password is kept.
- [ ] S26 Production: the Keycloak admin console (`/admin`) returns 404 from the Internet; the discovery issuer is the public https URL; only VC-Admins / VC-Hosts members can sign in; `/realms/master` returns 404 from the Internet; the admin console opens and signs in at `https://<Keycloak host>:8443/admin/` from the admin network (no "Something went wrong").
- [ ] S27 `configure-realm.sh` with `KC_ADMIN_URL`: the master realm Frontend URL (and its issuer) becomes the internal admin URL, the script still succeeds on its first run with it (re-login after the change), and the public realm issuer and client secret are unchanged.
- [ ] S28 Keycloak UI language: with `LOCALES` / `DEFAULT_LOCALE`, the login page follows the browser language (zh-TW → Traditional Chinese `zh-Hant`, ja → Japanese, en → English, unsupported → default), for the portal realm and the master (admin) realm.
- [ ] S29 Production SSO smoke test (every release, after deploying): temporary directory accounts in the admin group, the host group and no group sign in through the real IdP with OTP enrolment; roles are correct, SSO accounts have no local password / 2FA, sign-out ends both the portal and IdP sessions, the second sign-in needs only OTP, the no-group account is refused; all temporary accounts are removed from the directory, the IdP and the portal afterwards.
- [ ] S30 Display name: with the display name claim set to `display_name`, the portal account's display name equals the directory `displayName` (e.g. "Jason Cheng", not just the surname) and is refreshed on every SSO sign-in; an account without it falls back to the username. Covered by `tests/run-sso.sh` and the production smoke test (S29).

### 3.12 Meeting transcripts and summaries (jt-live-whisper, from v1.12.0)

- [ ] T01 Transcript layers are joined by seq: times from raw, text from final (raw where final is missing), speakers from the speakers layer; no final layer at all is flagged as uncorrected.
- [ ] T02 Webhook signature: hex HMAC-SHA256 over "timestamp.body" accepted; wrong secret, altered body, timestamps older than 300 seconds, non-numeric timestamps and base64 (8x8-style) signatures rejected; either signature accepted during secret rotation.
- [ ] T03 Permission levels: off cannot use transcripts; manual, automatic and administrators can; unknown values count as off.
- [ ] T04 Manual generation only for meetings the host hosted; administrators for any meeting; hosts with permission off can neither generate nor view.
- [ ] T05 Automatic generation for accounts set to automatic only.
- [ ] T06 The per-meeting switch overrides the account default (off wins over automatic, on works for manual) but cannot exceed the account permission (off stays off); disabled accounts are never processed automatically.
- [ ] T07 Queueing: a pending entry is created once; running or finished recordings are not queued again; invalid recording ids and languages are rejected / replaced by the default.
- [ ] T08 Meeting hints sent to the speech service: room, host display name, start / end and participants in UTC ISO 8601; no title.
- [ ] T09 Webhook events: only terminal events of this portal's own job (system jtvc, matching job id) mark a result as ready; each event id is handled once.
- [ ] T10 Speaker renaming: only S-ids and numeric segment numbers, control characters stripped, 40-character limit.
- [ ] T11 Deleting results removes the files and the index entry.
- [ ] T12 Error messages map known codes to readable text; settings keep the API key and webhook secret when left blank, strip `/api/v1`, record the time automatic generation was enabled, and are part of settings export.
- [ ] T13 Registering the webhook stores the endpoint id and secret.
- [ ] T14 Manual generation end to end: upload, job, completion; transcript, summary JSON and Markdown saved; JTLW content acknowledged and cleared afterwards; segments carry time, speaker and text; external_ref carries system jtvc and the recording id.
- [ ] T15 Webhook deliveries from the speech service pass signature verification and are recorded; a bad signature gets 401 and GET gets 405.
- [ ] T16 Summary failure: partial result with the transcript kept and not yet acknowledged; "Redo summary" completes it.
- [ ] T17 Recognition failure ends as failed with the error code and is not retried automatically.
- [ ] T18 Queue full (429): back to pending with a later retry time (backoff).
- [ ] T19 Cancelling a running job ends as cancelled.
- [ ] T20 Automatic generation picks up recordings of automatic accounts made after enabling; older recordings and manual accounts are skipped.
- [ ] T21 Duplicate webhook events do not break the flow.
- [ ] T22 When a recording disappears, its transcript, summary and index entry are deleted (and the speech-service job record).
- [ ] T23 Hosts with permission off see no transcript switch or column and get 404 on the transcript page and downloads.
- [ ] T24 Manual hosts: switch on the create form (off by default), transcript column, link for finished and "Regenerate" for cancelled recordings, no access to other hosts' meetings (404), no delete (404), missing CSRF gets 403.
- [ ] T25 Transcript page shows the summary with citations for every item and the transcript; clicking a citation highlights the cited segments; no CSP errors.
- [ ] T26 Renaming a speaker for all segments is saved and survives a reload.
- [ ] T27 Downloads: text with [mm:ss] and speaker names, SRT, summary Markdown, JSON with speaker names.
- [ ] T28 Another host gets 404 for a meeting they did not host.
- [ ] T29 Administrators: any meeting; settings card reachable from the section index; API key not echoed back; account management shows the permission select and a badge.
- [ ] T30 Audit log records transcript events but never transcript content.
- [ ] T31 ZAP covers /transcript, /transcript-download, /transcript-action and /jtlw-webhook (High 0, Medium 0).
- [ ] T32 Dominant-speaker timeline cleaning: control characters removed, name length limited, entries without a name, ending before they start or in the future dropped, sorted by start.
- [ ] T33 A heartbeat with the timeline stores it with the room; when the host leaves it is written to the meeting record, clipped to the session, an open last entry ending at the end of the meeting; the room's copy is cleared. The meeting page listens to Jitsi's dominantSpeakerChanged.
- [ ] T34 Speaker suggestions: each speaker id maps to the participant with the most overlapping time, including when the recording start is off by a few seconds (offset search within ±10 s).
- [ ] T35 Ambiguous speakers list several names (largest first); no timeline or too little overlap gives no suggestion; the participant list is de-duplicated.
- [ ] T36 Transcript page shows the suggestion panel (e.g. "Amy 100%"), the rename box offers the participant list, "Apply the most likely person to all" renames every speaker and survives a reload, applied suggestions are marked.
- [ ] T37 Meetings without a timeline show no suggestion panel and a note that names can be picked manually.
- [ ] T38 After a job is submitted, the speech service being unreachable keeps it waiting for up to 24 hours; after that it is marked failed (jtlw_unreachable) with a readable reason.
- [ ] T39 A summary that failed because the language model was temporarily unavailable is scheduled for automatic redo and completes when redone; the number of automatic redos is recorded.
- [ ] T40 Recordings list: the failed status is a chip the same height as the buttons, the reason is in the hover text and in the expanded row, and every column of the row is vertically centred.
- [ ] T41 A recording in progress: play, download and delete are disabled in the list, streaming / download requests get 409 and a delete request is refused with a message; each action button has its own tooltip.
- [ ] T42 Production meeting simulation (maintainer, every release): two participants talk in a real meeting while Jibri records; the recording is transcribed and summarised by the real speech service; the transcript has two speakers and the key content, the summary lists decisions and action items, and the speaker suggestions name both participants.
- [ ] T43 Recordings list: a finished transcript (green "View transcript & summary") and one not yet produced (dashed "Generate transcript") look clearly different; a job in progress is cancelled with the ✕ at the end of its status chip, which has a tooltip.
- [ ] T44 Meeting minutes export: on the transcript page PDF, DOCX, ODT and HTML (v1.15.0) download the full minutes (`<room>-<date>-meeting.<ext>`) for anyone who can view the transcript; others get 404.
- [ ] T45 Export content: meeting details, key points, decisions & action items (owner / due date, sources with time and speaker), events, risks, open questions, topic timeline, speaker statistics, and the full transcript with renamed speakers and speaker colours. The PDF shows Chinese / Japanese correctly, its text can be selected and searched, and only the glyphs used are embedded (small file); the heading bars stay inside the page margin in Word and LibreOffice; the .docx opens in Microsoft Word and the .odt in LibreOffice without a repair prompt; the HTML is a single file (styles inline, no scripts, no external resources, all content escaped) that reads well on a phone and prints cleanly.
- [ ] T46 Export language: labels and separators follow the interface language; control characters that are not allowed in XML are removed so the files never come out corrupted.
- [ ] T47 Requirements check: System settings lists any missing PHP extension (openssl, curl, mbstring, fileinfo, json, zlib) or bundled font and what it affects; exporting on a server without zlib gives a readable message instead of an error 500.
- [ ] T48 Transcript page: four buttons PDF / DOCX / ODT / HTML; the other formats (plain text, SRT, JSON, Markdown) are in an "Other formats" menu that opens only on click and closes on an outside click or Esc.
- [ ] T49 Playback failure message: when the login has timed out, pressing play says so ("your login has timed out…") with a sign-in link and keeps the player; a deleted file, an unplayable format and an unreachable recording service each get their own message. `/session-check` only returns whether you are signed in.
- [ ] T50 Background job check: with transcripts enabled, System settings warns when the transcription worker has not run for more than 10 minutes and shows the cron line for Docker and for a direct install.
- [ ] T51 Speech-service plan by language: Taiwanese Hokkien uses the dedicated `transcribe.taiwanese` profile without speaker separation; every other language uses the configured profile with speaker separation.
- [ ] T52 Job language priority: an explicit choice > the main language chosen when the room was created > the system default; invalid values are ignored.
- [ ] T53 A room remembers its main meeting language and writes it into the meeting record; unticking the transcript option clears it.
- [ ] T54 Create-room form: ticking "Generate transcript and summary" shows a required "Main meeting language" (Mainly Chinese / English / Japanese / Korean / Taiwanese Hokkien — no politically loaded names), unticking hides it.
- [ ] T55 The server refuses a room that asks for a transcript without a valid main language; the Recordings list has a "Main language" column.
- [ ] T56 A recording whose meeting had no main language asks for one when you press "Generate transcript"; choosing Taiwanese sends the Taiwanese profile without speaker separation, and the result is fetched (no endless "processing").
- [ ] T57 Taiwanese Hokkien transcript page: the summary is marked "for reference only"; no speaker column, speaker statistics or speaker suggestions; the main language is shown; exports carry the same note.
- [ ] T58 Meeting statistics page: "Activity in the last 30 days" is a stacked bar chart; the duration ranking shows the longest meeting on top with the duration at the end of each bar; the meeting-duration timeline is collapsed by default and opens on click (its title still shows the count and total); charts follow the dark theme.
- [ ] T59 Joining a meeting while Jitsi Meet is down: hosts and guests see a friendly "Video service temporarily unavailable" page (HTTP 503, retries every 30 seconds, no internal addresses) instead of a broken meeting; if the meeting frame does not respond within 20 seconds or Jitsi reports a fatal error, a "Cannot connect to the meeting" overlay with a Retry button appears; with Jitsi working, no overlay appears.
- [ ] T60 System status on the admin dashboard: Jitsi Meet (web page and XMPP/BOSH endpoint) and Jibri (service reachable, each recorder's own health, disk space) shown as green / orange / red with the reason; checked after the page loads, cached 30 seconds, refresh button; `/health` is admin-only and reveals no internal details. Also: with self-hosted Jitsi without JWT, the host can enter the meeting (fixed).
- [ ] T61 A retryable recognition failure (e.g. the speech service's GPU server temporarily down) is retried automatically after 10 / 30 / 60 minutes with `retry`, without uploading the recording again; the Recordings list shows "Waiting to retry" with the reason; when retries run out, or on Regenerate, the JTLW job (and the recording it kept) is deleted.
- [ ] T62 `meeting.detailed` (retired by JTLW) is switched to `meeting.balanced` automatically.
- [ ] T68 Transcript waveform: no time tooltip before the cursor is over the waveform (no empty box in the top-left); it shows the time on hover and hides again when the cursor leaves; site-wide, elements marked hidden are never displayed.
- [ ] T69 Meeting minutes export: an extra blank line between each section heading and the paragraph before it (PDF / DOCX / ODT / HTML).

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
- [ ] GitHub Pages: Chinese / English / Japanese switching, automatic detection by browser language, and `?lang=` can specify the language and is remembered; each appendix doc card shows exactly one button for the current language, the cards are laid out 2×2, cards in a row are equal height with buttons aligned at the bottom.

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
| Settings page section index (show one card, URL hash, reload, All) | `tests/run-e2e.sh` (`tests/e2e/meeting.cjs`) |
| .ics folding / REQUEST / CANCEL / SEQUENCE, session settlement on room delete, audit length limits and retention pruning | `tests/unit/test_ical_audit.php` |
| Audit log in the viewer's language, `system` account and empty IP T67 | `tests/unit/test_ical_audit.php` |
| GET sign-out ignored, password change / force sign-out invalidates sessions, room delete permissions, audit | `tests/run-integration.sh` (v1.8.0 section) |
| Jibri recordings API auth / Range / path traversal | `tests/test-jibri-api.sh` |
| SSO S02–S20 (signature, claims, state, PKCE URL, groups, provisioning, SSO only, logout URL, settings) | `tests/unit/test_oidc.php` |
| SSO S02 / S05 / S08 / S10 (mini IdP: token exchange, userinfo, fail-closed) | `tests/unit/test_oidc_flow.php` |
| SSO S01, S05–S07, S11–S21, S23–S25, S27, S28, S30 (real Keycloak + browser, incl. OTP) | `tests/run-sso.sh` (`tests/e2e/sso.cjs`) |
| SSO S22 | `tests/zap/run-zap.sh` (scan with SSO enabled) |
| SSO S26 | Manual: KEYCLOAK-SETUP section 7 verification commands |
| Transcripts T01–T12, T32–T35, T38, T51–T53 (layers, webhook signature, permissions, per-meeting switch, queueing, hints, events, renaming, settings) | `tests/unit/test_transcripts.php` |
| Meeting minutes export T44–T47 (PDF structure, cross-reference, font subset, ToUnicode, line breaking; .docx / .odt ZIP and XML, renaming, language, requirements) | `tests/unit/test_txexport.php` |
| Meeting minutes export T69 (space before headings) | `tests/unit/test_txexport.php` |
| Transcripts T13–T30, T36–T37, T39–T41, T43–T45, T48–T50, T54–T61 (JTLW mock + stub Jibri + real browser) | `tests/run-transcribe.sh` (`tests/e2e/transcribe.cjs`) |
| Transcripts T71 (ticked by default, language required) | `tests/run-transcribe.sh` (`tests/e2e/transcribe.cjs`) |
| Transcripts T68 (waveform time tooltip, site-wide hidden rule) | `tests/run-transcribe.sh` (`tests/e2e/transcribe.cjs`) |
| Transcripts T31 | `tests/zap/run-zap.sh` |
| Transcripts T42 | `tests/prod-meeting-sim.sh` (maintainer, production) |
| Self-hosted host JWT `lobby_bypass` T63 | `tests/unit/test_security.php` |
| Entry paths and gates T64–T66 (real Jitsi: roles, guests cannot record, lobby, host rejoin, waiting / countdown / ended, create in 3 languages) | `tests/prod-entry-gates.sh` (maintainer, production) |
| Recording start / stop / two at once T70 | `tests/prod-entry-gates.sh`, `tests/prod-meeting-sim.sh` (maintainer, production) |
| Language menu (collapsed, opens on click, Esc) | `tests/run-e2e.sh` (`tests/e2e/meeting.cjs`) |
| SSO S29 | Production smoke script run by the maintainer against the live deployment (temporary accounts, cleaned up afterwards) |
| Vulnerability scan | `tests/zap/run-zap.sh` |
