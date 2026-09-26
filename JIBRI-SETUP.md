# Jibri Recording × Self-hosted Jitsi Meet Setup

> **Author**: Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　Project [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)
>
> 繁體中文: [JIBRI-SETUP_zh-TW.md](JIBRI-SETUP_zh-TW.md)

This guide continues from [JITSI-MEET-SETUP.md](JITSI-MEET-SETUP.md) and adds **Jibri recording** to the same docker-jitsi-meet deployment, with special attention to two points: **recording multiple meeting rooms at the same time** and **rendering Chinese (CJK) text correctly in recordings**.

> Applies to: docker-jitsi-meet / `jitsi/jibri` **`stable-10888`**.
> This guide targets **2 simultaneous recordings (2 Jibri containers)**; to scale up or down, adjust every "2" in this document accordingly.

---

## Table of Contents

**Planning**

- [Deployment topology (dedicated VM recommended)](#deployment-topology-recommend-a-dedicated-vm-for-jibri)
- [1. What Jibri is, limitations and resources](#1-what-jibri-is-and-its-limitations)

**Installation (same-host setup, easiest to start with)**

- [2. Host prerequisite: snd-aloop](#2-host-vm-prerequisite-load-snd-aloop-determines-the-number-of-concurrent-recordings)
- [3. Enable recording (`.env`)](#3-enable-recording-docker-jitsi-meet-env)
- [4. Scaling recorders up or down](#4-scaling-recorders-up-or-down)
- [5. Chinese text in recordings (required)](#5-chinese-cjk-text-in-recordings-required)
- [6. Recording files and playback (jibri-recordings-api)](#6-recording-files-and-playback-jibri-recordings-api)

**Operations / Advanced**

- [7. Troubleshooting](#7-troubleshooting)
- [8. Upgrade SOP](#8-upgrade-sop)
- [9. Dedicated Jibri VM (separate from Jitsi)](#9-dedicated-jibri-vm-separate-from-jitsi-step-by-step)

---

<br>
<br>
<br>
<br>
<br>
<br>

## Deployment topology (recommend a dedicated VM for Jibri)

| Scale | Recommendation |
|---|---|
| Testing / small scale, occasionally recording 1 meeting | Jibri and Jitsi on the **same VM** (same docker-compose stack; simplest, and the default steps in this guide) |
| Production / multiple containers (2+ in this guide) | **Jibri on its own VM (or several VMs)**, separate from the Jitsi host |

**Why separate them in production:**

1. **Resource isolation (most important)**: Jibri = headless Chrome + ffmpeg, which is heavy and bursty on CPU/RAM. Sharing a host with prosody / jicofo / JVB means a busy recording can degrade the quality of **every** meeting. Once separated, even if Jibri maxes out the CPU, only recordings are affected, not meetings.
2. **Kernel requirements apply only to the Jibri VM**: only Jibri needs `snd-aloop` and "must be a VM, not LXC"; the core Jitsi services have no such restriction. After separating, only the Jibri machine needs to deal with kernel modules.
3. **Independent scaling**: for more concurrent recordings, just add Jibri VMs without touching the Jitsi host.

**How to separate them:** Jibri connects to the main stack's prosody **over XMPP** (network reachability is all it needs; it does not have to be on the same host). On a dedicated Jibri VM you still follow this guide (snd-aloop + jibri containers), but `XMPP_SERVER` / `XMPP_*_DOMAIN` / `JIBRI_*` in `.env` must point to the **main Jitsi host** and match it (this is docker-jitsi-meet's "standalone Jibri" approach).

> Sections 3–4 are written for the "same host" case, which is easiest to start with. **For a "dedicated Jibri VM" (separate from Jitsi, the recommended production setup), see [Section 9](#9-dedicated-jibri-vm-separate-from-jitsi-step-by-step) for full step-by-step instructions.** Section 2 (snd-aloop) and Section 5 (CJK fonts) are required on both.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 1. What Jibri is and its limitations

- Jibri (Jitsi Broadcasting Infrastructure) joins a meeting as a **headless Chrome** instance and uses **ffmpeg** to capture video and audio into an `.mp4` (or push an RTMP live stream).
- **One Jibri container can record only one meeting at a time**. This guide targets **2 concurrent recordings = 2 Jibri containers**.
- It needs an ALSA loopback (`snd-aloop`) virtual sound device to capture audio; **each concurrent Jibri needs its own loopback device** (2 recordings → 2 devices).
- Jibri is resource-hungry: roughly 1–2 vCPU + 1–2 GB RAM per stream (headless Chrome + ffmpeg). **For 2 concurrent streams, 4 vCPU / 8 GB is recommended** (see the "VM resource recommendations" table below).

### Must run on a "VM", not inside a container (LXC)

Jibri needs the host kernel to **load the `snd-aloop` module** and needs access to **`/dev/snd`** — which is **not possible** inside containers that share the host kernel (e.g. Proxmox LXC or other OS-level containers). Therefore:

- **Make the "Jibri host that runs Docker" a VM (KVM / full virtual machine with its own kernel)**, then run the 2 Jibri containers with Docker inside that VM.
- Example: Proxmox → create a **VM** (not an LXC) → install Linux + Docker → run `modprobe snd-aloop` inside the VM.
- "Cannot run inside a container" means the Jibri host itself must not be an LXC; the Jibri service still runs as Docker containers inside the VM (that is fine, because the VM has its own kernel that can load the module and give containers `/dev/snd`).

### VM resource recommendations (for this guide's target of "2 simultaneous recordings")

| Item | Recommendation | Notes |
|---|---|---|
| vCPU | **4 cores** (minimum 3, comfortable 6) | Jibri = headless Chrome + ffmpeg encoding, very CPU-intensive; about 1–2 vCPU per stream |
| RAM | **8 GB** (minimum 4) | Chrome + ffmpeg take about 1–2 GB per stream, plus system headroom |
| System disk | **20–30 GB** | OS + Docker + Jibri/Chrome images take about 10–15 GB |
| Recording storage | **Separate; a dedicated disk / NFS is recommended (100 GB+)** | See the capacity estimate below; `finalize.sh` can move files away automatically and delete the local copy |
| Audio | **snd-aloop ×2** (one loopback card per stream) | Must be a VM, not LXC (see above) |

**Recording capacity estimate (easily overlooked)**: 1080p30 H.264 is roughly **0.5–1 GB / hour / stream**; estimate as "concurrent streams × duration per meeting × number of copies retained". Example: 2 streams each recording 2 hours ≈ 2–4 GB. For long-term retention, put recordings on a dedicated large disk, a NAS, or object storage.

**Other notes**:
- **Network**: Jibri "downloads" the whole meeting in order to record it, so it needs stable bandwidth to Jitsi / JVB, ideally on the same subnet.
- **CPU type (Proxmox)**: Jibri is tied to the `snd-aloop` kernel module, so live migration is of little value; either `host` or `x86-64-v2-AES` works, and offline migration is fine.
- **More streams**: scale vCPU / RAM, the number of `snd-aloop` devices (Section 2), and `--scale jibri=N` (Section 4) proportionally together (e.g. 3 streams ≈ 6 vCPU / 12 GB).
- **Minimum viable** (occasionally 1 stream): 2 vCPU / 4 GB / 20 GB system disk + recording storage.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 2. Host (VM) prerequisite: load snd-aloop (determines the number of concurrent recordings)

Jibri uses ALSA's **loopback virtual sound card** (`snd-aloop`) to route meeting audio to ffmpeg; **each concurrent recording needs its own loopback card**, so 2 recordings need 2 cards. This step is done at the **operating-system level of the Jibri VM** (not inside a container).

### 1) Check that the kernel has the snd-aloop module

```bash
modinfo snd-aloop >/dev/null 2>&1 && echo "OK: module present" || echo "Module missing, install it"
```

Minimal cloud / server images often lack this module; install it before continuing (Debian / Ubuntu):

```bash
sudo apt-get update
sudo apt-get install -y "linux-modules-extra-$(uname -r)" alsa-utils
```

### 2) Load at boot + fix the number of cards (persistent)

```bash
# (a) Load snd-aloop automatically at boot
echo 'snd-aloop' | sudo tee /etc/modules-load.d/snd-aloop.conf

# (b) Fix at 2 loopback cards (list one "1" and one index per concurrent recording)
echo 'options snd-aloop enable=1,1 index=0,1' | sudo tee /etc/modprobe.d/snd-aloop.conf
```

- `enable=1,1`: enable 2 cards; `index=0,1`: assign them numbers 0 and 1.
- For 3 recordings use `enable=1,1,1 index=0,1,2`, and so on.

### 3) Reboot (recommended)

> **After editing the two files above, reboot once.**
> Reason: `snd-aloop` may well have been loaded by the system before you started (without options / with the wrong number of cards). While the module is "already in memory", options passed via `modprobe ... enable=...` are ignored. A reboot guarantees a **clean** load with the correct number of cards from `/etc/modprobe.d`.

```bash
sudo reboot
```

#### Apply immediately without rebooting (alternative)

Unload it, then reload it with options (no reboot needed):

```bash
sudo modprobe -r snd-aloop 2>/dev/null   # unload; if it reports "in use", something is holding it -> reboot instead
sudo modprobe snd-aloop enable=1,1 index=0,1
```

### 4) Verify (after reboot or reload)

```bash
lsmod | grep snd_aloop        # snd_aloop should be loaded
cat /proc/asound/cards        # should show 2 "Loopback" cards (index 0, 1)
```

Seeing **2 Loopback cards** means success.

> Scaling up or down: adjust `enable=` / `index=` here together with `--scale jibri=` in Section 4, then reboot (or reload using the alternative above).
> If `modprobe` reports the module cannot be found or permission denied, and it still fails after installing the package, this machine is most likely an **LXC container rather than a VM** — see Section 1; you must use a VM.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 3. Enable recording (docker-jitsi-meet `.env`)

> **You no longer need to hand-edit prosody / jicofo / jibri configuration files.** The old "package version (apt install)" required manually editing the `prosody` recorder vhost, the jibri brewery MUC, `jicofo` properties, `jibri.conf`, `prosodyctl register`, and so on — **in the docker version all of these are generated automatically from environment variables when the containers start**. You only need to set `.env` variables; the only thing left to do on the host is `snd-aloop` (Section 2, because it lives at the kernel level and containers cannot provide it).

```ini
ENABLE_RECORDING=1

# Account names can keep the env.example defaults (recorder / jibri); passwords are generated by gen-passwords.sh:
#   JIBRI_RECORDER_PASSWORD, JIBRI_XMPP_PASSWORD (written into .env after running ./gen-passwords.sh)
# JIBRI_RECORDER_USER=recorder
# JIBRI_XMPP_USER=jibri

# Time zone (affects recording file names / embedded timestamps / log times; shared by all containers)
TZ=Asia/Taipei

# Recording output directory (path inside the container, mapped to a host volume)
JIBRI_RECORDING_DIR=/config/recordings
# Post-recording script (optional: upload to object storage / send notifications; if unset, files are just kept)
# JIBRI_FINALIZE_RECORDING_SCRIPT_PATH=/config/finalize.sh
```

The Jibri service is started by overlaying an additional `jibri.yml` (docker-jitsi-meet convention):

```bash
docker compose -f docker-compose.yml -f jibri.yml up -d
```

The Jibri container needs access to the host's `/dev/snd` (jibri.yml already sets `devices: /dev/snd`) and depends on the snd-aloop module loaded in the previous step.

### Recording output location (host side)

- By default, jibri's config / recording volume is mounted from the host's **`~/.jitsi-meet-cfg/jibri`** → container `/config`; recordings end up under **`~/.jitsi-meet-cfg/jibri/recordings/`** on the host.
- Each meeting gets its own subfolder (containing the `.mp4` and metadata); file names / times follow the `TZ` setting.
- To store them elsewhere: in `jibri.yml`, change the host path mapped to that volume (e.g. a large disk / NFS mount point); keep `JIBRI_RECORDING_DIR` as `/config/recordings` inside the container.
- The `CONFIG` variable (docker-jitsi-meet `.env`, default `~/.jitsi-meet-cfg`) sets the host root directory for all components' configuration / data; change it to relocate everything at once.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 4. Scaling recorders up or down

A "recorder" = one jibri container, and **one container can record only one meeting at a time**. To record N meetings simultaneously = **N snd-aloop loopback cards + N jibri containers**; the two numbers must match.

> The commands below use the **same-host setup** (`-f docker-compose.yml -f jibri.yml`) as the example; for a **dedicated Jibri VM**, replace the compose arguments with `-f docker-compose.jibri-standalone.yml` (see [Section 9](#9-dedicated-jibri-vm-separate-from-jitsi-step-by-step)).

### (1) Set the number of loopback cards (determines the recording limit)

Configure this at the **Jibri VM operating-system level** (not inside a container), one card per concurrent recording. Example with 2 cards:

```bash
echo 'snd-aloop' | sudo tee /etc/modules-load.d/snd-aloop.conf
echo 'options snd-aloop enable=1,1 index=0,1' | sudo tee /etc/modprobe.d/snd-aloop.conf
sudo modprobe -r snd-aloop 2>/dev/null && sudo modprobe snd-aloop enable=1,1 index=0,1   # "in use" means a recording is in progress -> reboot instead
cat /proc/asound/cards          # should show 2 Loopback cards
```

For 3 recordings use `enable=1,1,1 index=0,1,2`, and so on. The safest option after changing it is to reboot once (options are ignored while the module is already loaded).

### (2) Add / set the number of jibri containers (card count must already be ≥ N)

```bash
cd docker-jitsi-meet
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=2
```

### (3) Reduce the number of containers (extra ones are stopped)

```bash
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=1
```

### (4) Stop / restart (keeps configuration and recordings)

```bash
docker compose -f docker-compose.yml -f jibri.yml stop jibri      # stop all
docker compose -f docker-compose.yml -f jibri.yml start jibri     # start again
docker compose -f docker-compose.yml -f jibri.yml restart jibri   # restart (stop + start)
```

### (5) Verify

```bash
docker compose -f docker-compose.yml -f jibri.yml ps              # should list the matching number of jibri containers, all running
```

Each jibri container occupies one loopback card; jicofo dispatches each recording request to an "idle" jibri, so the maximum number of simultaneous recordings = min(cards, containers).

> Keep all three equal: `snd-aloop cards = jibri containers = desired concurrent recordings`. If any is short, excess recording requests cannot get a Jibri and will fail / stay pending. Adjust both sides together when scaling, and make sure the VM has enough resources (about 1–2 vCPU + 1–2 GB per stream).

---

<br>
<br>
<br>
<br>
<br>
<br>

## 5. Chinese (CJK) text in recordings (required)

Jibri uses headless Chrome to "record the meeting screen". **The official `jitsi/jibri` image contains no CJK fonts**, so Chinese names / chat / captions show up in recordings as empty boxes (□, "tofu"). You need to add CJK fonts to the jibri image.

Build an extended image:

```dockerfile
# jibri-cjk/Dockerfile
FROM jitsi/jibri:stable-10888
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      fonts-noto-cjk fonts-noto-cjk-extra fonts-noto-color-emoji \
 && fc-cache -f \
 && rm -rf /var/lib/apt/lists/*
# Important: do NOT switch back to USER jibri. The jibri image starts s6 as root (it drops privileges to jibri for the services itself);
# if you add USER jibri at the end, the container fails with "s6-mkdir: /var/run/s6: Permission denied" and keeps restarting.
```

Build it and make compose use it:

```bash
docker build -t jibri-cjk:stable-10888 ./jibri-cjk
```

In `jibri.yml` (or `docker-compose.override.yml`), change the jibri service's `image:` to `jibri-cjk:stable-10888`, then restart.

> Verify: record a meeting with Chinese participant names and check during playback that the Chinese renders correctly (no tofu boxes). Noto CJK has the most complete coverage of Traditional Chinese.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 6. Recording files and playback (jibri-recordings-api)

### Recording output location

It is recommended to write recordings to a dedicated, easy-to-manage path (not a hidden folder in a home directory). In the jibri service's compose file, map the recording volume to `/srv/recordings`:

```yaml
    volumes:
      - ${CONFIG}/jibri:/config:Z
      - /srv/recordings:/config/recordings
```

Keep `JIBRI_RECORDING_DIR` as `/config/recordings` inside the container. Each recording gets its own subfolder (UUID) containing `<room>_<time>.mp4` and `metadata.json`.

### Recording playback service

The bundled `jibri-recordings-api` (pure Python standard library, zero third-party packages) lets jt-vc-portal **list / play / download / delete** recordings online, show **host storage capacity**, and apply **retention policies**.

1) Install the program (`server.py` is in this repo's `jibri-recordings-api/` directory):

```bash
sudo mkdir -p /opt/jibri-recordings-api
sudo cp jibri-recordings-api/server.py /opt/jibri-recordings-api/server.py
```

2) Generate a random token first (copy the output string):

```bash
openssl rand -hex 32
```

3) Create the environment file `/etc/jibri-recordings-api.env` and **paste** the output of the previous step after `API_TOKEN=` (this is a systemd EnvironmentFile, not a shell script, so you **cannot** write `$(...)`; fill in the actual string):

```bash
REC_DIR=/srv/recordings
API_TOKEN=<paste the random string generated in the previous step>
ALLOW_IPS=<portal host IP>,127.0.0.1
PORT=9080
```

Set permissions: `chmod 600 /etc/jibri-recordings-api.env`.

4) systemd service `/etc/systemd/system/jibri-recordings-api.service`:

```ini
[Unit]
Description=Jibri Recordings API (portal-only)
After=network.target

[Service]
EnvironmentFile=/etc/jibri-recordings-api.env
ExecStart=/usr/bin/python3 /opt/jibri-recordings-api/server.py
Restart=always
RestartSec=3
# Sandbox: only /srv/recordings is writable (cleanup / delete / config file)
ProtectSystem=strict
ReadWritePaths=/srv/recordings
ProtectHome=true
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

5) Enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now jibri-recordings-api
systemctl is-active jibri-recordings-api
```

6) In the portal, go to **System Settings → Recording Settings → Jibri Recording Service**, enter `http://<jibri host IP>:9080` and the token above, and click "Save and Test". Once it shows "Connected", a "Recordings" tab appears in the navigation bar.

> **Security**: the service uses two-factor verification — "source IP allowlist + Bearer token" — and accepts only the portal; the portal in turn requires an administrator login before proxying the stream. `ProtectSystem=strict` + `ReadWritePaths=/srv/recordings` restrict the service to reading and writing only the recording directory.

### Retention policies (all disabled by default)

Configure them in the portal's recording settings card; they are pushed to the service and applied hourly by a background thread:

- **By age**: keep the most recent N days; older recordings are deleted automatically.
- **By capacity**: keep a minimum amount of free space (`min_free_gb`) or cap total recording usage (`max_used_gb`); both delete oldest first.
- **Leftover cleanup**: incomplete recordings / fragments left behind by abnormally ended meetings are removed after N hours.
- Files currently being recorded (still written to within the last 5 minutes) are **never cleaned up**; cleanup actions are logged to `/srv/recordings/.api-cleanup.log`.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 7. Troubleshooting

| Symptom | Resolution |
|---|---|
| Clicking record does nothing / stays pending | jibri not running, `snd-aloop` not loaded, or no idle jibri (all busy recording) → add loopback cards and scale jibri |
| Chinese text in recordings shows as boxes / missing glyphs | jibri image lacks CJK fonts → use the `jibri-cjk` image from Section 5 |
| Cannot record 2 meetings / the 2nd one cannot get a recorder | fewer than 2 `snd-aloop` devices or jibri containers → raise both to 2 per Sections 2 and 4 (resources must also be sufficient) |
| `modprobe snd-aloop` fails | this machine is an LXC container, not a VM → use a VM (see Section 1) |
| Recording file is black / silent | `/dev/snd` not mounted into the container, snd-aloop malfunction, or insufficient host resources |
| Log shows `Failed to run finalize script /path/to/finalize` | **Harmless** — this is the default placeholder path when no finalize script is set; it attempts to run it when recording stops and fails, but **does not affect the recording output**. To silence it, set `JIBRI_FINALIZE_RECORDING_SCRIPT_PATH` to an existing script (or an empty `.sh`). |
| A recording stays in "Recording" status indefinitely | The portal decides whether a recording has finished based on whether `metadata.json` exists (Jibri writes it only after finalize); if finalize failed and never wrote it, it stays "Recording" → check the jibri log for finalize errors |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 8. Upgrade SOP

When upgrading, Jibri must follow Jitsi onto the same `stable-<version>`; if you use the custom `jibri-cjk` image from Section 5, **remember to rebuild it with the new version number** (otherwise the CJK font image stays on the old version). `snd-aloop` (Section 2) is version-independent and does not need to be redone.

```bash
cd docker-jitsi-meet

# (1) Back up configuration and existing recordings
cp -a ~/.jitsi-meet-cfg ~/.jitsi-meet-cfg.bak-$(date +%Y%m%d)

# (2) Get the new version (replace with the target tag)
git fetch --tags
git checkout stable-<new-version>
#   If .env contains JITSI_IMAGE_VERSION, make sure it equals stable-<new-version>

# (3) Rebuild the CJK jibri image (with the new version number)
sed -i 's/stable-[0-9]*/stable-<new-version>/' jibri-cjk/Dockerfile
docker build -t jibri-cjk:stable-<new-version> ./jibri-cjk
#   Also change the jibri service's image: in jibri.yml / override to jibri-cjk:stable-<new-version>

# (4) Pull the remaining official images and restart (keeping 2 jibri containers)
docker compose -f docker-compose.yml -f jibri.yml pull
docker compose -f docker-compose.yml -f jibri.yml up -d --scale jibri=2
```

Verify after upgrading:

```bash
docker compose -f docker-compose.yml -f jibri.yml ps     # web/prosody/jicofo/jvb + 2 jibri all Up
```

- Start a meeting and click record → confirm recording works and **Chinese text is not rendered as tofu boxes** (Section 5).
- Record 2 meeting rooms at the same time → confirm concurrency (Section 4) is unaffected by the upgrade.

> This guide is based on `stable-10888`; just replace `<new-version>` above with the tag you are upgrading to.
> `snd-aloop` is a kernel module and independent of the image version, so it does not need to be reconfigured on upgrade (unless you reinstalled the VM).

---

<br>
<br>
<br>
<br>
<br>
<br>

## 9. Dedicated Jibri VM (separate from Jitsi): step by step

In production, a dedicated VM for Jibri is recommended (see "Deployment topology"). The following are complete steps verified on real machines, assuming:

- Main Jitsi host: `10.0.0.10` (docker-jitsi-meet in `/opt/docker-jitsi-meet`, public URL `meet.example.com`).
- Jibri VM: a separate machine, **KVM, not LXC**, on the same subnet and able to reach the main host.

### 9-1 On the main Jitsi host (`10.0.0.10`)

```bash
cd /opt/docker-jitsi-meet
# (1) Enable recording
sed -i 's/^#\?ENABLE_RECORDING=.*/ENABLE_RECORDING=1/' .env || echo "ENABLE_RECORDING=1" >> .env

# (2) Open prosody's c2s port 5222 to the Jibri VM (standalone Jibri connects to it)
#     Publish it via docker-compose.override.yml, bound to the host's LAN IP (not exposed publicly)
cat >> docker-compose.override.yml <<'EOF'
  prosody:
    ports:
      - "10.0.0.10:5222:5222"
EOF
#     Note: the override may contain only one services: block; if other services already exist, merge prosody into it

docker compose up -d prosody jicofo     # apply recording + 5222
```

Collect the values the Jibri VM will need (**do not leak the passwords**):

```bash
grep -E '^JIBRI_XMPP_PASSWORD=|^JIBRI_RECORDER_PASSWORD=' .env   # two passwords, to be copied to the Jibri VM shortly
# XMPP domains (image defaults are fine; for this version they are):
#   XMPP_DOMAIN=meet.jitsi  AUTH=auth.meet.jitsi  INTERNAL_MUC=internal-muc.meet.jitsi
#   RECORDER(hidden)=hidden.meet.jitsi   brewery=jibribrewery
# Can be confirmed from prosody: docker exec docker-jitsi-meet-prosody-1 sh -c 'grep -E "^VirtualHost|^Component" /config/conf.d/jitsi-meet.cfg.lua'
```

### 9-2 On the Jibri VM

1) First complete **Section 2 (snd-aloop ×N)** and install Docker.

2) Get the repo and create `.env` (XMPP pointing to the main host, passwords matching the main host):

```bash
cd /opt && git clone https://github.com/jitsi/docker-jitsi-meet.git
cd docker-jitsi-meet && git checkout stable-10888
cp env.example .env && ./gen-passwords.sh        # fill in all fields first
mkdir -p ~/.jitsi-meet-cfg/jibri/recordings

# Settings (replace with your host IP / domain; set JIBRI_*_PASSWORD to the two values from the main host)
cat >> .env <<'EOF'
PUBLIC_URL=https://meet.example.com
TZ=Asia/Taipei
ENABLE_RECORDING=1
XMPP_SERVER=10.0.0.10
XMPP_PORT=5222
XMPP_TRUST_ALL_CERTS=1
XMPP_DOMAIN=meet.jitsi
XMPP_AUTH_DOMAIN=auth.meet.jitsi
XMPP_INTERNAL_MUC_DOMAIN=internal-muc.meet.jitsi
XMPP_MUC_DOMAIN=muc.meet.jitsi
XMPP_RECORDER_DOMAIN=hidden.meet.jitsi
JIBRI_BREWERY_MUC=jibribrewery
JIBRI_XMPP_USER=jibri
JIBRI_RECORDER_USER=recorder
JIBRI_RECORDING_DIR=/config/recordings
JIBRI_XMPP_PASSWORD=<paste the main host's JIBRI_XMPP_PASSWORD>
JIBRI_RECORDER_PASSWORD=<paste the main host's JIBRI_RECORDER_PASSWORD>
EOF
```

> **Key points**: `XMPP_SERVER` points to the main host, `XMPP_TRUST_ALL_CERTS=1` (prosody's internal certificate is self-signed), and `JIBRI_*_PASSWORD` must match the main host **exactly** (the jibri/recorder accounts are registered on the main host's prosody); the XMPP domains are the same as on the main host.

3) Build the CJK image (Section 5; **make sure not to add `USER jibri`**).

4) Create a standalone compose file (runs only jibri, with its own `/dev/snd` and `extra_hosts`):

```yaml
# docker-compose.jibri-standalone.yml
services:
  jibri:
    image: jibri-cjk:stable-10888
    restart: unless-stopped
    volumes:
      - ${CONFIG}/jibri:/config:Z
    shm_size: "2gb"
    cap_add: [ SYS_ADMIN ]
    devices: [ "/dev/snd:/dev/snd" ]
    extra_hosts:
      - "meet.example.com:10.0.0.10"   # make the recording Chrome resolve to the main host's internal IP
    environment:
      - PUBLIC_URL
      - TZ
      - XMPP_SERVER
      - XMPP_PORT
      - XMPP_TRUST_ALL_CERTS
      - XMPP_DOMAIN
      - XMPP_AUTH_DOMAIN
      - XMPP_INTERNAL_MUC_DOMAIN
      - XMPP_MUC_DOMAIN
      - XMPP_RECORDER_DOMAIN
      - JIBRI_XMPP_USER
      - JIBRI_XMPP_PASSWORD
      - JIBRI_RECORDER_USER
      - JIBRI_RECORDER_PASSWORD
      - JIBRI_BREWERY_MUC
      - JIBRI_RECORDING_DIR
      - DISPLAY=:0
```

5) Start 2 recorders:

```bash
docker compose -f docker-compose.jibri-standalone.yml up -d --scale jibri=2
```

### 9-3 Verification

```bash
# Jibri VM: both containers running, and the log shows "Joined MUC: jibribrewery@internal-muc.meet.jitsi"
docker compose -f docker-compose.jibri-standalone.yml ps
docker logs <jibri-container> 2>&1 | grep -E "Authenticated|Joined MUC"

# Main host jicofo: should show 2 jibri instances in the brewery with available = true
docker logs docker-jitsi-meet-jicofo-1 2>&1 | grep -i "brewery instance"
```

Finally, start a meeting and click record for a real test (Chinese text renders correctly, and 2 rooms can be recorded at the same time).

> Common pitfalls: ① Mistakenly adding `USER jibri` at the end of the CJK Dockerfile → container keeps restarting (s6 permissions). ② `JIBRI_*_PASSWORD` does not match the main host → log shows authentication failures. ③ The main host's prosody port 5222 is not open to the Jibri VM → cannot connect. ④ The Jibri VM cannot resolve `meet.example.com` to the internal IP → fix with `extra_hosts` or internal DNS.
