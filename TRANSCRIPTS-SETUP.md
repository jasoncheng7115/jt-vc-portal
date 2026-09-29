# Meeting Transcripts and Summaries Setup (jt-live-whisper)

> 繁體中文: [TRANSCRIPTS-SETUP_zh-TW.md](TRANSCRIPTS-SETUP_zh-TW.md) · 日本語: [TRANSCRIPTS-SETUP_ja.md](TRANSCRIPTS-SETUP_ja.md)

When a recording finishes, jt-vc-portal can hand it to the self-hosted speech service **[jt-live-whisper](https://github.com/jasoncheng7115/jt-live-whisper) (JTLW)** and get back a **speaker-labelled transcript** and a **meeting summary** (key points, decisions and action items, events, risks, open questions, topics and speaker statistics — every item citing a time in the recording), which can be exported as PDF / DOCX / ODT / HTML.

This guide covers everything from preparing JTLW, configuring the portal, the background job and permissions, to day-to-day use and troubleshooting.

---

## Contents

1. [How it works](#1-how-it-works)
2. [Requirements](#2-requirements)
3. [Preparing JTLW](#3-preparing-jtlw)
4. [Portal settings](#4-portal-settings)
5. [Background job](#5-background-job)
6. [Who can use it (permissions)](#6-who-can-use-it-permissions)
7. [Day-to-day use](#7-day-to-day-use)
8. [Export formats](#8-export-formats)
9. [Data retention and privacy](#9-data-retention-and-privacy)
10. [Troubleshooting](#10-troubleshooting)
11. [Upgrading from an older version](#11-upgrading-from-an-older-version)

---

## 1. How it works

```
 Jitsi Meet + Jibri ──recording──▶ jibri-recordings-api (Jibri host)
                                        │  portal fetches the file with a token
                                        ▼
                                 jt-vc-portal ──streamed upload + job──▶ jt-live-whisper (GPU host)
                                        ▲                                  │ recognition → speakers → correction → summary
                                        │                                  │ (summary uses the language model configured in JTLW)
                                        └──── webhook / scheduled polling ◀┘
                                 fetch transcript and summary, write to disk → tell JTLW to delete its copy
```

- **The portal itself never talks to a language model.** Recognition, speaker separation, correction and summarising all happen in JTLW; which model is used is configured there.
- Submitting work is done by a **background job** (every minute): it uploads one recording at a time, follows progress and fetches the results.
- When JTLW finishes it can notify the portal through a **webhook** (optional). Without the webhook everything still works — the job checks progress every minute.

> **Self-hosted Jitsi Meet + Jibri only.** In 8x8 JaaS mode recordings live in the 8x8 cloud and the portal cannot reach the files; 8x8's separately billed live captions are disabled by default in this system and are never stored here.

## 2. Requirements

| Item | Details |
|---|---|
| Recording retrieval | Self-hosted Jitsi Meet + Jibri, with `jibri-recordings-api` running on the Jibri host and connected under **Settings → Recording settings** (see section 6 of [JIBRI-SETUP.md](JIBRI-SETUP.md)). |
| jt-live-whisper | REST API `api_revision` **2.4 or later** (includes summaries). Usually runs on a GPU host; it processes one job at a time and queues the rest. |
| Network | Portal host → JTLW API port (HTTPS `8790` by default). Optional: JTLW → the portal's `/jtlw-webhook` (completion notifications). |
| Portal | The PHP `curl` extension (included in the Docker image); a background job that runs every minute (section 5). |

## 3. Preparing JTLW

Run these on the JTLW host (the jt-live-whisper documentation is authoritative for the exact commands).

1. **Issue an API key for the portal** with the scopes `jobs:write`, `jobs:read`, `jobs:cancel` and `profiles:read`:

   ```bash
   cd <jt-live-whisper directory>
   venv/bin/python -m jtlw_api.keys add jtvc jobs:write jobs:read jobs:cancel profiles:read
   ```

   The key is shown only once — keep it safe (JTLW stores only its sha256). Use one key per system; a key only sees the jobs it submitted. **Do not grant the `admin` scope.**

2. **Allow the portal host to connect**: add the portal host's IP to `api.allowed_hosts` in JTLW's `config.json`.

   After adding a key or changing the configuration, restart the JTLW API service for it to take effect:

   ```bash
   sudo systemctl restart jtlw-api
   ```

3. **Get the API's TLS certificate.** JTLW uses a self-signed certificate by default; obtain its PEM and check the SHA-256 fingerprint:

   ```bash
   openssl x509 -in jtlw-api.crt -noout -fingerprint -sha256
   ```

4. **Make sure summaries work**: summaries need a language model configured in JTLW. Meeting summaries support **Chinese and English** meetings; Japanese and Korean get a transcript only.

## 4. Portal settings

**Settings → Transcript and summary**:

| Field | Details |
|---|---|
| Enable transcripts and summaries | Turns the feature on. The time it is first enabled is recorded — **automatic generation only processes meetings recorded after that**, so old recordings are not all sent at once. |
| Speech service (JTLW) URL | e.g. `https://10.0.0.30:8790` (without `/api/v1`). |
| API key | The key from section 3 itself (without `Bearer `). It is never shown again after saving; leave it empty to keep the current one. |
| Speech service certificate | For a self-signed certificate paste its PEM. The portal **always verifies the certificate** and never turns the check off; the SHA-256 fingerprint is shown underneath for you to compare. Leave empty for a certificate from a public CA. |
| Meeting language | Set it when known (Chinese / English / Japanese / Korean). "Auto" decides from roughly the first 30 seconds, so if someone opens in another language the whole meeting may be recognised wrongly. |
| Recognition mode | `meeting.balanced` (recommended) or `meeting.detailed` (slower, finer). |
| Also generate a meeting summary | Decisions, action items, risks and topics, each with a time. |

Then click, in order:

1. **Save and test connection** — checks the URL, certificate, key and scopes.
2. **Register webhook** — registers the completion URL `https://<your site>/jtlw-webhook` with JTLW. The signing secret JTLW returns (only once) is stored automatically, and every notification is then verified by HMAC. Skip this step if JTLW cannot reach the portal.

## 5. Background job

Submitting, checking progress and fetching results are all done by a job that runs **every minute**:

- **Docker** (on the host; use the container name from your `docker run --name`):

  ```
  # /etc/cron.d/jtvc-transcribe
  * * * * * root docker exec -u www-data jt-vc-portal php /var/www/html/transcribe-worker.php >/dev/null 2>&1
  ```

- **Direct install** (replace the path with your installation directory):

  ```
  # /etc/cron.d/jtvc-transcribe
  * * * * * www-data php /var/www/jt-vc-portal/transcribe-worker.php >/dev/null 2>&1
  ```

Only one instance runs at a time (file lock); while a large upload is in progress the next run simply skips. To run it by hand and see what it does:

```bash
docker exec -u www-data jt-vc-portal php /var/www/html/transcribe-worker.php -v
```

If the job has not run for more than 10 minutes, the **Settings → Transcript and summary** card shows a warning.

## 6. Who can use it (permissions)

| Level | Where | Details |
|---|---|---|
| Account | **Account management → Transcript permission** | **Not allowed** (default) / **Manual** (may press "Generate transcript" on meetings they hosted) / **Auto** (generated when their recordings finish). |
| Meeting | "Generate transcript and summary after recording" when creating a room | A host with permission can switch it on or off for that meeting, overriding the account default (but never beyond the account permission). |
| Admin | — | Can generate, cancel, regenerate or delete transcripts for any meeting. |

Hosts only see recordings and transcripts of meetings they hosted.

## 7. Day-to-day use

The **Transcript** column on the **Recordings** page shows each meeting's status:

| Shown | Meaning |
|---|---|
| ✓ View transcript & summary (green) | Done — opens the viewer. |
| Waiting to submit / Processing ✕ | Queued or in progress; click ✕ to cancel. |
| Failed / Waiting to retry | Hover, or expand the row, for the reason (see section 10). |
| ✦ Generate transcript (dashed) | Not produced yet; click to queue it. |

While a recording is still being made, play, download and delete are greyed out until it finishes.

**The transcript viewer**:

- **Meeting summary**: key points, decisions and action items (with owner and due date), events, risks, open questions, a topic timeline and speaker statistics. Every item shows a time and speaker — click it to play the recording from there, with the matching transcript lines highlighted.
- **Player**: the whole waveform, with a tooltip showing who is speaking at that point; the video can be shown too.
- **Transcript**: coloured by speaker, scrolls with playback, click a time to jump.
- **Renaming**: speaker labels (S1, S2…) are voice clusters, not names. Click a name to rename it — by default every line of that label changes; you can also change just one line.
- **Speaker suggestions**: the host's meeting page records Jitsi's "current speaker" timeline, and the viewer uses it to suggest which participant S1, S2… is (with the share of overlapping time); apply them all with one click. **Keep the host's meeting page open for the whole meeting** so the timeline is complete.
- **Redo summary / Regenerate / Delete transcript**: after a failed summary you can redo just the summary; regenerating discards the current results and renames.

## 8. Export formats

| Format | Content |
|---|---|
| **PDF** | The whole record (meeting details, summary, topics, speaker statistics, transcript). Only the glyphs used are embedded (bundled Noto Sans TC, SIL OFL), so Chinese and Japanese display correctly and the text can be selected and searched; **Korean glyphs are not included**. |
| **DOCX** / **ODT** | The same, editable in Microsoft Word / LibreOffice. |
| **HTML** | The same as a single file (styles inline, no scripts) that opens in any browser, reads well on a phone and prints cleanly. |
| Other formats | Plain text (`[mm:ss]` at the start of each line), SRT subtitles, JSON, and the summary as Markdown. |

Every format uses the renamed speakers; labels follow the interface language of whoever downloads. Everything is generated by the portal itself — no LibreOffice or browser engine is needed on the server.

## 9. Data retention and privacy

- The recording is streamed to JTLW; JTLW deletes the upload once processed, and after the portal has written the results to disk it tells JTLW to delete its job record too.
- Results live in the data directory under `transcripts/<recording id>/` and **follow the recording**: deleting the recording, or the Jibri retention policy removing it, deletes its transcript and summary as well.
- The audit log records who generated, viewed, downloaded, renamed or deleted a transcript — **never the transcript content**.
- The API key and webhook secret are stored in the settings and never shown back on the page; a settings export includes them, so keep export files safe.

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Test connection fails: certificate | The pasted PEM is wrong or expired; compare the SHA-256 fingerprint. The portal will not turn verification off for this. |
| Test connection fails: 401 / 403 | Wrong key, `Bearer ` pasted along with it, or missing scopes (`jobs:write`, `jobs:read`, `jobs:cancel`, `profiles:read` are needed). |
| Test connection fails: cannot connect | The portal host's IP is not in JTLW's `allowed_hosts`, or a firewall blocks the API port. |
| Stuck at "Waiting to submit" | The background job is not running (Settings shows a warning) — see section 5. |
| "The transcript is empty" | The recording has no recognisable speech (nobody spoke, microphones muted). This is the correct result, and the JTLW record has been deleted. |
| Failed with "speech service unreachable" | JTLW could not be reached for more than 24 hours; click "Regenerate" once it is back. |
| Summary failed, will retry | The language model was temporarily unavailable; the summary is redone automatically after 10, 30 and 60 minutes (the transcript is kept). Only if all of those fail do you need "Redo summary". |
| "Meeting summaries support Chinese and English only" | Japanese and Korean meetings get a transcript only. |
| No speaker suggestions | That meeting has no Jitsi speaker timeline (the host's meeting page was closed part-way, or it was recorded by an older version); you can still rename by hand. |
| "Your login has timed out" when playing | You were signed out after 30 minutes of inactivity; follow the link to sign in again. |
| Downloading PDF / DOCX / ODT reports missing components | A direct install is missing PHP `zlib` or the bundled font; see the notice at the top of Settings. |

## 11. Upgrading from an older version

- **From before v1.12.0**: add the background job from section 5; a direct install also needs PHP `curl`.
- **From before v1.14.0**: PDF / DOCX / ODT export needs PHP `zlib` (built into the Debian / Ubuntu PHP packages) and the bundled font `lib/fonts/NotoSansTC-Regular.ttf` (comes with `git pull`).
- The Docker and Release images already contain everything. After upgrading, open **Settings**: missing components or a background job that is not running are shown on the page.
