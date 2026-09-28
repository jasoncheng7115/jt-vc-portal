# Keycloak (OIDC Single Sign-On) × jt-vc-portal Setup

> **Author**: Jason Cheng　·　GitHub [@jasoncheng7115](https://github.com/jasoncheng7115)　·　Project [jt-vc-portal](https://github.com/jasoncheng7115/jt-vc-portal)
>
> 繁體中文: [KEYCLOAK-SETUP_zh-TW.md](KEYCLOAK-SETUP_zh-TW.md) · 日本語: [KEYCLOAK-SETUP_ja.md](KEYCLOAK-SETUP_ja.md)

This guide deploys **Keycloak on a dedicated host** as the OIDC identity provider (IdP) for jt-vc-portal, federating an on-premises **Active Directory** (Windows AD or Univention UCS / Samba AD) over **LDAPS**. Hosts and administrators then sign in to the portal with their company account plus a mandatory one-time password (OTP); guests are not affected and still join through invitation links.

> Applies to: Keycloak **26.4** (`quay.io/keycloak/keycloak`), PostgreSQL 17, Docker Compose v2, jt-vc-portal with the "Single sign-on (SSO / OIDC)" settings card.
> All ready-made files are in the repository folder [`keycloak/`](keycloak/). Every hostname, IP address and DN in this guide is an **example** (`example.com`, `10.0.0.x`) — replace them with your own.

---

## Table of Contents

**Planning**

- [1. Overview and architecture](#1-overview-and-architecture)
- [2. Requirements](#2-requirements)

**Installation**

- [3. Prepare Active Directory](#3-prepare-active-directory)
- [4. Install Docker and deploy Keycloak](#4-install-docker-and-deploy-keycloak)
- [5. Replace the bootstrap admin](#5-replace-the-bootstrap-admin)
- [6. Configure the realm (configure-realm.sh)](#6-configure-the-realm-configure-realmsh)
- [7. Reverse proxy](#7-reverse-proxy)
- [8. Connect jt-vc-portal](#8-connect-jt-vc-portal)

**Operations**

- [9. Operations](#9-operations)
- [10. Security checklist](#10-security-checklist)
- [11. Troubleshooting](#11-troubleshooting)
- [12. How this is tested](#12-how-this-is-tested)

---

<br>
<br>
<br>
<br>
<br>
<br>

## 1. Overview and architecture

```
                        Internet / users' browsers
                                   │  HTTPS 443
                                   ▼
                ┌──────────────────────────────────────┐
                │ Reverse proxy (TLS termination)      │
                │ sso.example.com → only /realms/ and  │
                │ /resources/ ; everything else = 404  │
                │ vc.example.com  → jt-vc-portal       │
                └───────┬──────────────────────┬───────┘
          HTTP 8080     │                      │  HTTP (portal port)
                        ▼                      ▼
┌────────────────────────────────┐   ┌──────────────────────────┐
│ Keycloak host (VM / LXC)       │   │ jt-vc-portal host        │
│ 10.0.0.20                      │   │ (container jaas-auth)    │
│  keycloak :8080  (proxy only)  │◄──┤ OIDC: discovery, token,  │
│  keycloak :8443  (admin HTTPS) │   │ JWKS via                 │
│  keycloak :9000  (health,      │   │ https://sso.example.com  │
│                   localhost)   │   └──────────────────────────┘
│  postgres  (internal network)  │
└───────────────┬────────────────┘
                │ LDAPS 636 (read-only bind: svc-keycloak)
                ▼
┌────────────────────────────────┐
│ AD domain controller           │
│ dc1.example.com                │
│ groups VC-Admins / VC-Hosts    │
└────────────────────────────────┘

Admin network (e.g. 10.0.1.0/24) ──HTTPS 8443──► Keycloak admin console (never via the proxy)
```

**Design decisions**

| Decision | Why |
|---|---|
| **jt-vc-portal never connects to AD / LDAP directly** | The portal only speaks OIDC. Directory credentials, LDAP binds and password checks all stay inside Keycloak; the portal only receives a signed ID token. |
| **Keycloak federates AD over LDAPS (port 636), read-only** | Passwords are verified against AD by an LDAP bind; Keycloak never writes to AD. |
| **Keycloak runs on its own host (VM / LXC)**, not on the portal host | Separate blast radius, separate patching and backups; an IdP holding directory credentials should not share a host with a public web application. |
| **Only `/realms/` and `/resources/` are exposed through the reverse proxy** | The admin console (`/admin`), metrics and health endpoints stay on the internal network (`KC_HOSTNAME_ADMIN`). |
| **Authorization Code + PKCE (S256)** with a confidential client | The portal also sends `state` and `nonce`; Keycloak enforces PKCE and an exact redirect URI. |
| **OTP (TOTP) is mandatory in Keycloak** | Every user must enrol an authenticator app at first login; every later login needs a code. |
| **Keycloak brute-force threshold < AD lockout threshold** | Keycloak locks the account (temporarily) *before* AD would, so an attacker cannot use Keycloak to lock AD accounts. If AD has no lockout at all, Keycloak is the main guard against password guessing. |
| **Only members of `VC-Admins` / `VC-Hosts` are visible to Keycloak** (LDAP custom filter) | No other AD account can be tried — or locked — through Keycloak. |

**What is protected where**

| Layer | Protection |
|---|---|
| Reverse proxy | TLS, HSTS, rate limit on the login POST, admin console not forwarded |
| Keycloak | AD password + mandatory OTP, brute-force detection, group-restricted user visibility, PKCE, exact redirect URI, session idle 30 min / max 12 h |
| jt-vc-portal | Verifies the ID token signature (RS256/384/512 only) and `iss` / `aud` / `azp` / `exp` / `nonce`; one-time `state`; group → role mapping (no allowed group = denied); binds accounts by (issuer, subject), never merges with an existing local account; fail2ban on the source IP; everything written to the audit log / SIEM |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 2. Requirements

### Host

| Item | Recommendation |
|---|---|
| OS | Ubuntu Server 24.04 LTS |
| CPU / RAM | 2 vCPU / 4 GB RAM |
| Disk | 20 GB or more (PostgreSQL data, images, logs) |
| Type | VM or LXC container, dedicated to Keycloak |

**Proxmox LXC**: Docker inside LXC needs the `nesting` and `keyctl` features:

```bash
# On the Proxmox node (replace 120 with your CT ID), then restart the container
pct set 120 --features nesting=1,keyctl=1
pct reboot 120
```

### DNS and TLS

- A DNS name for Keycloak, e.g. **`sso.example.com`**, pointing to the reverse proxy.
- A TLS certificate for that name **on the reverse proxy** (e.g. Let's Encrypt). Keycloak itself runs plain HTTP on the internal network.
- The portal host must be able to resolve `sso.example.com` and reach it over HTTPS with a **publicly trusted** certificate (the portal verifies the certificate against the container's CA bundle). If the portal is inside the same LAN, you may need split DNS / hairpin NAT.

### Network

| From | To | Port | Purpose |
|---|---|---|---|
| Reverse proxy (e.g. 10.0.0.10) | Keycloak 10.0.0.20 | TCP 8080 | Login pages / OIDC |
| Admin network (e.g. 10.0.1.0/24) | Keycloak 10.0.0.20 | TCP 8443 | Admin console (HTTPS) |
| Keycloak 10.0.0.20 | DC `dc1.example.com` | TCP 636 | LDAPS |
| jt-vc-portal | `sso.example.com` (proxy) | TCP 443 | Discovery, token, JWKS |

### Firewall recommendations

- **8080** (HTTP): allow only from the reverse proxy.
- **8443** (HTTPS, admin console): allow only from the admin network.
- **9000** (health / management): bound to `127.0.0.1` by `docker-compose.yml`; never expose it.
- **PostgreSQL**: not published at all (internal Docker network only).
- Keycloak host outbound: only 636 to the DCs, plus DNS / NTP / package updates.

> **Docker bypasses `ufw`**: ports published by Docker are handled in the `DOCKER-USER` chain, not by `ufw` INPUT rules. Either use a network firewall (e.g. the Proxmox VM/CT firewall), set `KC_BIND_ADDR` in `.env` to the internal IP, and/or add `DOCKER-USER` rules:
>
> ```bash
> # Rules are inserted at the top, so insert the DROP first and the ACCEPTs after it
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8080 --ctdir ORIGINAL -j DROP
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8443 --ctdir ORIGINAL -j DROP
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8080 --ctdir ORIGINAL -s 10.0.0.10 -j ACCEPT
> iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8443 --ctdir ORIGINAL -s 10.0.1.0/24 -j ACCEPT
> apt install -y iptables-persistent && netfilter-persistent save
> ```

---

<br>
<br>
<br>
<br>
<br>
<br>

## 3. Prepare Active Directory

You need:

1. Two security groups: **`VC-Admins`** (portal administrators) and **`VC-Hosts`** (portal hosts).
2. A **read-only lookup service account**, e.g. **`svc-keycloak`**, used by Keycloak only to search users and groups. It needs no special rights — any authenticated domain user can read the directory. Do **not** add it to any admin group.
3. The **CA certificate** that signed the DC's LDAPS certificate.

> The groups must be located directly in the container you will set as `LDAP_GROUPS_DN` (Windows AD default: `CN=Users,...`; UCS default: `CN=Groups,...`), and users must be **direct** members (the `memberOf` filter does not follow nested groups).

### (a) Windows AD (PowerShell, on a DC or a host with RSAT)

```powershell
Import-Module ActiveDirectory

# Groups (adjust -Path to the container you will use as LDAP_GROUPS_DN)
New-ADGroup -Name "VC-Admins" -GroupScope Global -GroupCategory Security -Path "CN=Users,DC=example,DC=com" -Description "jt-vc-portal administrators"
New-ADGroup -Name "VC-Hosts"  -GroupScope Global -GroupCategory Security -Path "CN=Users,DC=example,DC=com" -Description "jt-vc-portal hosts"

# Service account: create disabled, set the password, then enable
New-ADUser -Name "svc-keycloak" -SamAccountName "svc-keycloak" -UserPrincipalName "svc-keycloak@example.com" `
  -Path "CN=Users,DC=example,DC=com" -Description "Keycloak LDAP lookup (read-only)" -Enabled $false
Set-ADAccountPassword -Identity "svc-keycloak" -Reset -NewPassword (Read-Host -AsSecureString "svc-keycloak password")
Set-ADUser -Identity "svc-keycloak" -PasswordNeverExpires $true -CannotChangePassword $true   # optional
Enable-ADAccount -Identity "svc-keycloak"

# Members
Add-ADGroupMember -Identity "VC-Admins" -Members alice
Add-ADGroupMember -Identity "VC-Hosts"  -Members bob,carol

# DNs you will need for realm.env
Get-ADDomain | Select-Object DistinguishedName                 # LDAP_BASE_DN
Get-ADGroup "VC-Admins" | Select-Object DistinguishedName      # → LDAP_GROUPS_DN = the part after "CN=VC-Admins,"
Get-ADUser "svc-keycloak" | Select-Object DistinguishedName    # LDAP_BIND_DN

# Account lockout policy
Get-ADDefaultDomainPasswordPolicy | Select-Object LockoutThreshold,LockoutDuration,LockoutObservationWindow
```

**Deny interactive logon (recommended)**: in a GPO linked to the domain (or to the computers), add `svc-keycloak` to *Deny log on locally* and *Deny log on through Remote Desktop Services*. The account only needs LDAP binds.

### (b) Univention UCS (on the Primary Directory Node)

UCS runs **two directories**: **Samba AD** (ports 389 / 636) and the UCS **OpenLDAP** (ports 7389 / 7636). Point Keycloak at **Samba AD on 636** — the settings in this guide (`vendor=ad`, `sAMAccountName`, `objectGUID`, `memberOf`) are for AD.

```bash
BASE=$(ucr get ldap/base)

# Groups
udm groups/group create --position "cn=groups,$BASE" --set name=VC-Admins --set description="jt-vc-portal administrators"
udm groups/group create --position "cn=groups,$BASE" --set name=VC-Hosts  --set description="jt-vc-portal hosts"

# Service account: create disabled with no login shell (throw-away initial password)
udm users/user create --position "cn=users,$BASE" \
  --set username=svc-keycloak --set lastname=keycloak \
  --set password="$(openssl rand -base64 24)" \
  --set disabled=1 --set shell=/bin/false

# Set the real password and enable it
read -rsp 'svc-keycloak password: ' PW; echo
udm users/user modify --dn "uid=svc-keycloak,cn=users,$BASE" --set password="$PW" --set disabled=0
unset PW

# Members
udm groups/group modify --dn "cn=VC-Admins,cn=groups,$BASE" --append users="uid=alice,cn=users,$BASE"
udm groups/group modify --dn "cn=VC-Hosts,cn=groups,$BASE"  --append users="uid=bob,cn=users,$BASE"

# Samba AD DNs (these are what Keycloak sees on port 636)
samba-tool user show svc-keycloak | grep '^dn:'     # LDAP_BIND_DN, e.g. CN=svc-keycloak,CN=Users,DC=example,DC=com
samba-tool group show VC-Admins   | grep '^dn:'     # e.g. CN=VC-Admins,CN=Groups,DC=example,DC=com

# Account lockout policy
samba-tool domain passwordsettings show
```

### Account lockout threshold

Check the AD lockout threshold (`LockoutThreshold` / "Account lockout threshold"). **`LOCKOUT_FAILURES` in `realm.env` must be lower** (default 5).

If it is **0 (no lockout)**, consider enabling it, e.g. 10 attempts, 30 minutes:

```powershell
# Windows AD
Set-ADDefaultDomainPasswordPolicy -Identity example.com -LockoutThreshold 10 -LockoutDuration 00:30:00 -LockoutObservationWindow 00:30:00
```

```bash
# UCS / Samba AD
samba-tool domain passwordsettings set --account-lockout-threshold=10 --account-lockout-duration=30 --reset-account-lockout-after=30
```

If AD lockout stays disabled, Keycloak's brute-force detection is the main protection for these accounts.

### Export the CA certificate (for LDAPS)

Keycloak must trust the CA that signed the DC's LDAPS certificate, and that certificate must contain the DC name you use in `LDAP_URL` (e.g. `dc1.example.com`).

| Directory | Where to get the CA |
|---|---|
| UCS | `/etc/univention/ssl/ucsCA/CAcert.pem` on the Primary Directory Node (already PEM) |
| Windows (AD CS Enterprise CA) | On the CA: `certutil -ca.cert ad-ca.cer`, then convert: `openssl x509 -inform der -in ad-ca.cer -out ad-ca.pem` |

You will place it as `truststores/ad-ca.pem` in the next section.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 4. Install Docker and deploy Keycloak

### Files in `keycloak/`

| File | Purpose |
|---|---|
| `docker-compose.yml` | PostgreSQL 17 + Keycloak (`start`, production mode). Keycloak listens on 8080 (HTTP, for the proxy), 8443 (HTTPS, internal admin console, certificate from `./certs`) and 9000 (health, bound to `127.0.0.1`). Mounts `./truststores` and `./certs` read-only and trusts every PEM in it (`KC_TRUSTSTORE_PATHS`). Database in `./data/postgres`. |
| `docker-compose.bootstrap.yml` | Adds the temporary admin variables — used only for the very first start (Section 4–5). |
| `.env.example` | Template for `.env` (version, URLs, passwords). |
| `realm.env.example` | Template for `realm.env` (parameters of `configure-realm.sh`). |
| `configure-realm.sh` | Idempotent script that creates / updates the realm, AD federation and OIDC client (Section 6). |
| `nginx-sso.conf.example` | Reverse-proxy example (Section 7). |
| `.gitignore` | Keeps `.env`, `realm.env`, `client-secret.txt`, `data/`, `certs/` and `truststores/*.pem` out of version control. |

### Install

```bash
apt update && apt install -y docker.io docker-compose-v2 git curl jq
systemctl enable --now docker

# Deployment directory (root only)
install -d -m 700 /opt/keycloak
git clone --depth 1 https://github.com/jasoncheng7115/jt-vc-portal.git /tmp/jtvc
cp -a /tmp/jtvc/keycloak/. /opt/keycloak/
rm -rf /tmp/jtvc

# CA certificate for LDAPS (readable by the Keycloak container user)
install -d -m 755 /opt/keycloak/truststores
install -m 644 ad-ca.pem /opt/keycloak/truststores/ad-ca.pem

# Check LDAPS from this host with that CA (expect "Verify return code: 0 (ok)")
openssl s_client -connect dc1.example.com:636 -CAfile /opt/keycloak/truststores/ad-ca.pem </dev/null 2>/dev/null | grep 'Verify return code'

# HTTPS certificate for the internal admin console (port 8443). Self-signed is fine;
# put the IP / name you will type in the browser into subjectAltName.
install -d -m 750 /opt/keycloak/certs
openssl req -x509 -newkey rsa:3072 -nodes -days 3650 -subj "/CN=keycloak admin" \
  -addext "subjectAltName=IP:10.0.0.20,DNS:keycloak1" \
  -keyout /opt/keycloak/certs/tls.key -out /opt/keycloak/certs/tls.crt
chown -R 1000:0 /opt/keycloak/certs && chmod 600 /opt/keycloak/certs/tls.key   # container runs as uid 1000
```

> **Why HTTPS for the admin console?** Keycloak 26's admin console needs a browser *secure context* (Web Crypto for PKCE). Opened over plain `http://<IP>:8080` it only shows **"Something went wrong"**. `http://localhost` would work, but not an IP or host name — so the admin console is served on 8443 with this certificate. Your browser will warn about the self-signed certificate once (or import `tls.crt` as trusted on admin workstations).

### `.env`

```bash
cd /opt/keycloak
cp .env.example .env && chmod 600 .env
openssl rand -base64 32    # → KC_DB_PASSWORD
openssl rand -base64 24    # → KC_BOOTSTRAP_ADMIN_PASSWORD
vi .env
```

| Variable | Example | Meaning |
|---|---|---|
| `KC_VERSION` | `26.4` | Keycloak image tag (pin it; see [Upgrades](#upgrades)) |
| `KC_PUBLIC_URL` | `https://sso.example.com` | URL users' browsers see (through the proxy). Becomes the issuer base. |
| `KC_ADMIN_URL` | `https://10.0.0.20:8443` | Admin console URL, internal network only. **Must be `https://…:8443`** (see above). |
| `KC_BIND_ADDR` | `0.0.0.0` (or `10.0.0.20`) | Address ports 8080 / 8443 are published on |
| `KC_DB_PASSWORD` | *(random)* | PostgreSQL password (used at the database's first start) |
| `KC_BOOTSTRAP_ADMIN_USERNAME` | `temp-admin` | Temporary admin for the first start only |
| `KC_BOOTSTRAP_ADMIN_PASSWORD` | *(random)* | Its password — removed in Section 5 |

### Start

```bash
cd /opt/keycloak
docker compose -f docker-compose.yml -f docker-compose.bootstrap.yml up -d   # first start only
until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo "Keycloak ready"
docker compose ps
```

The first start takes a minute or two (database schema creation). Logs: `docker compose logs -f keycloak`.

The admin console is now at `https://10.0.0.20:8443/admin/` from the admin network.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 5. Replace the bootstrap admin

The bootstrap admin is meant to be temporary. Create a permanent administrator in the `master` realm and delete `temp-admin`.

```bash
cd /opt/keycloak
KC="docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh"
read -rsp 'Bootstrap (temp-admin) password: ' BOOT; echo
read -rsp 'New permanent admin password: ' NEWPW; echo

# 1) Log in as the temporary admin
$KC config credentials --server http://localhost:8080 --realm master --user temp-admin --password "$BOOT"

# 2) Create the permanent admin (choose your own name) and grant the realm "admin" role
$KC create users -r master -s username=kc-admin -s enabled=true
$KC set-password -r master --username kc-admin --new-password "$NEWPW"
$KC add-roles -r master --uusername kc-admin --rolename admin

# 3) Log in as the new admin and delete temp-admin
$KC config credentials --server http://localhost:8080 --realm master --user kc-admin --password "$NEWPW"
TID=$($KC get users -r master -q username=temp-admin --fields id --format csv --noquotes)
$KC delete "users/$TID" -r master

# (Recommended) require OTP for the new admin at its next console login
AID=$($KC get users -r master -q username=kc-admin --fields id --format csv --noquotes)
$KC update "users/$AID" -r master -s 'requiredActions=["CONFIGURE_TOTP"]'

unset BOOT NEWPW
```

Then remove both bootstrap lines from `.env` and re-create the container **without** the bootstrap file (Keycloak refuses to start if `KC_BOOTSTRAP_ADMIN_USERNAME` is set while the password is empty — that's why the bootstrap variables live only in `docker-compose.bootstrap.yml`):

```bash
sed -i '/^KC_BOOTSTRAP_ADMIN_/d' /opt/keycloak/.env
docker compose up -d   # from now on always without docker-compose.bootstrap.yml
```

Store the permanent admin credentials in your **password manager**. Log in once at `https://10.0.0.20:8443/admin/` to verify (and enrol OTP).

---

<br>
<br>
<br>
<br>
<br>
<br>

## 6. Configure the realm (configure-realm.sh)

### `realm.env`

```bash
cd /opt/keycloak
cp realm.env.example realm.env && chmod 600 realm.env
vi realm.env
```

| Variable | Example | Meaning |
|---|---|---|
| `REALM` | `jtvc` | Realm name. The issuer becomes `https://sso.example.com/realms/jtvc`. |
| `PORTAL_URL` | `https://vc.example.com` | Portal public URL — must equal the portal's site URL. Redirect URI = `<PORTAL_URL>/sso-callback`; post-logout redirect = `<PORTAL_URL>/`. |
| `CLIENT_ID` | `jt-vc-portal` | OIDC client ID |
| `KC_ISSUER_BASE` | `https://sso.example.com` | Only used to print the issuer at the end |
| `LDAP_URL` | `ldaps://dc1.example.com:636` | AD over LDAPS. **Empty = local-users-only mode** (no AD; users and the two groups are managed in Keycloak itself) |
| `LDAP_BASE_DN` | `DC=example,DC=com` | Where users are searched (subtree) |
| `LDAP_BIND_DN` | `CN=svc-keycloak,CN=Users,DC=example,DC=com` | Lookup service account (a UPN such as `svc-keycloak@example.com` also works with AD) |
| `LDAP_BIND_CREDENTIAL` | *(empty)* | Its password. **Recommended: leave empty** and set it in the admin console (see below), so it is never stored in a file. |
| `LDAP_GROUPS_DN` | `CN=Groups,DC=example,DC=com` | Container holding the two groups |
| `ADMIN_GROUP` / `HOST_GROUP` | `VC-Admins` / `VC-Hosts` | Group names (CN) |
| `LOCKOUT_FAILURES` | `5` | Keycloak brute-force threshold — **must be lower than the AD lockout threshold** |
| `LOCALES` / `DEFAULT_LOCALE` | `en,zh-Hant,ja` / `en` | UI languages of the login, account and admin pages (built in: `en`, `zh-Hant` Traditional Chinese, `zh-Hans` Simplified Chinese, `ja`, …). Chosen from the browser language; users can also switch on the login page. `DEFAULT_LOCALE` is used when nothing matches. Applied to the master realm (admin console) too. |
| `KC_ADMIN_URL` | `https://10.0.0.20:8443` | Same as in `.env`. The script sets it as the **master realm's Frontend URL**, so the Keycloak admin login page is always served from the internal admin URL (never from `sso.example.com`, where the proxy refuses `/realms/master`). |
| `KC_ADMIN_USER` / `KC_ADMIN_PASSWORD` | *(your master admin)* | Used by the script to log in. Clear them after running (or delete both lines from `realm.env` and `export` them in the shell — a blank line in `realm.env` would override the exported value). |

Optional overrides (environment variables): `KCADM` — the kcadm command (default `docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh`); `KC_SERVER` — server URL as seen by kcadm (default `http://localhost:8080`); `SECRET_OUT` — where to write the client secret (default `client-secret.txt`).

### Run

```bash
cd /opt/keycloak
./configure-realm.sh
```

Example output:

```
created realm jtvc
realm settings applied (brute force: 5 failures, OTP required)
created LDAP provider ad (…)
  NOTE: LDAP bind password not set — set it in the admin console (User federation → ad) or LDAP_BIND_CREDENTIAL
created group mapper vc-groups
created client jt-vc-portal
created groups mapper

== jt-vc-portal settings (System settings → Single sign-on) ==
Issuer        : https://sso.example.com/realms/jtvc
Client ID     : jt-vc-portal
Client secret : (printed to client-secret.txt, chmod 600)
Groups claim  : groups   Admin group: VC-Admins   Host group: VC-Hosts
Redirect URI  : https://vc.example.com/sso-callback
```

The client secret is written to `client-secret.txt` (mode 600). You will paste it into the portal in Section 8; afterwards keep it in your password manager and delete the file if you like.

**Re-running is safe**: every object is looked up by name and updated in place; an empty `LDAP_BIND_CREDENTIAL` leaves the existing bind credential unchanged, and the client secret is not regenerated.

### What the script configures

**Realm security settings**

| Setting | Value |
|---|---|
| `sslRequired` | `external` (HTTPS required except from private addresses) |
| User registration / reset password / remember me / edit username / login with email | all off |
| Brute-force detection | on, temporary lockout only: `failureFactor=LOCKOUT_FAILURES`, `waitIncrementSeconds=300`, `maxFailureWaitSeconds=1800`, `maxDeltaTimeSeconds=900`, quick-login check 1 s → 60 s wait |
| Events | login / logout / code-to-token / OTP events enabled (kept 90 days), admin events enabled, `jboss-logging` listener (to stdout) |
| OTP policy | TOTP, HmacSHA1, 6 digits, 30 s |
| Required action **Configure OTP** | enabled and **default action** → every user must enrol OTP at first login |
| Sessions | SSO session idle **30 min**, max **12 h** (same as the portal's session timeouts); access token 5 min |

**LDAP user federation "ad"** (only when `LDAP_URL` is set)

| Setting | Value |
|---|---|
| Vendor / edit mode | Active Directory / **READ_ONLY** (Keycloak never writes to AD) |
| Connection | `LDAP_URL` (LDAPS), StartTLS off, **truststore SPI always**, pooling, timeouts 5 s / 10 s |
| Username / RDN / UUID attribute | `sAMAccountName` / `cn` / `objectGUID` |
| Users DN / scope | `LDAP_BASE_DN`, subtree |
| **Custom user filter** | `(\|(memberOf=CN=VC-Admins,<GROUPS_DN>)(memberOf=CN=VC-Hosts,<GROUPS_DN>))` — only members of the two groups exist for Keycloak |
| Import / sync | import on first login; periodic full / changed sync off; Kerberos off |
| Group mapper **`vc-groups`** | `group-ldap-mapper`, READ_ONLY, groups DN = `LDAP_GROUPS_DN`, filter `(\|(cn=VC-Admins)(cn=VC-Hosts))`, membership via `member` (DN), load groups by member attribute |

In local-users-only mode (`LDAP_URL` empty) the script instead creates local groups `VC-Admins` and `VC-Hosts`; create users under *Users* in the admin console and join them to a group.

**OIDC client `jt-vc-portal`**

| Setting | Value |
|---|---|
| Type | confidential (client secret) |
| Flows | **standard flow (authorization code) only** — implicit, direct access grants and service accounts off; no consent screen; front-channel logout off |
| PKCE | `pkce.code.challenge.method = S256` (required) |
| Redirect URI | exactly `<PORTAL_URL>/sso-callback` (no wildcards) |
| Post-logout redirect | `<PORTAL_URL>/` |
| Web origins | none |
| Protocol mapper **`groups`** | Group Membership, claim `groups`, **full path off**, in ID token and userinfo |

### Set the LDAP bind password (if you left it empty)

Admin console (`https://10.0.0.20:8443/admin/`) → realm **jtvc** → **User federation** → **ad** → **Bind credentials** → enter the `svc-keycloak` password → **Save** → click **Test connection** and **Test authentication**.

Check that only group members are visible: realm **jtvc** → **Users** → search for a member (e.g. `alice`) — found; search for a non-member — not found. (Users are imported on first search / login; nothing is synchronised periodically.)

---

<br>
<br>
<br>
<br>
<br>
<br>

## 7. Reverse proxy

`keycloak/nginx-sso.conf.example` is an nginx server block for `sso.example.com`:

- **Only `/realms/…` and `/resources/…` are forwarded** to `http://10.0.0.20:8080`. Everything else — including `/admin`, `/metrics`, `/health` and `/` — returns **404**.
- **`/realms/master` also returns 404**: the master realm (Keycloak administrators) is only used from the internal admin URL. This rule must come before the other `/realms` locations.
- The login form POST (`/realms/<realm>/login-actions/authenticate`) is **rate-limited** per client IP (`limit_req`, 20 per minute, burst 10) as a first line against password guessing. The zone must be declared in the `http {}` context.
- **HSTS** and `X-Content-Type-Options: nosniff` are added.
- The shared snippet `kc-proxy.conf` sets `Host`, `X-Forwarded-Host`, `X-Forwarded-Proto https`, `X-Forwarded-Port 443`, `X-Forwarded-For` (Keycloak runs with `KC_PROXY_HEADERS=xforwarded`), and **larger proxy buffers** (Keycloak's responses carry large headers/cookies; the default buffers cause `502 upstream sent too big header`).

```bash
# On the reverse proxy
cp nginx-sso.conf.example /etc/nginx/sites-available/sso.example.com.conf     # edit names / IP / certificate paths
ln -s /etc/nginx/sites-available/sso.example.com.conf /etc/nginx/sites-enabled/

# Rate-limit zone (http context)
echo 'limit_req_zone $binary_remote_addr zone=kc_login:10m rate=20r/m;' > /etc/nginx/conf.d/kc-limit.conf

# Proxy snippet
cat > /etc/nginx/snippets/kc-proxy.conf <<'EOF'
proxy_set_header Host              $host;
proxy_set_header X-Forwarded-Host  $host;
proxy_set_header X-Forwarded-Proto https;
proxy_set_header X-Forwarded-Port  443;
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_buffer_size 128k;
proxy_buffers 4 256k;
proxy_busy_buffers_size 256k;
EOF

nginx -t && systemctl reload nginx
```

> On nginx 1.25.1 or newer, replace `listen 443 ssl http2;` with `listen 443 ssl;` + `http2 on;` to avoid the deprecation warning.
>
> Optional hardening: the `master` realm is only needed by the admin console, which you reach internally. You can add `location ^~ /realms/master/ { return 404; }` above the other locations so the master realm's login page is not reachable from the Internet.

### Verify

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://sso.example.com/admin/     # 404
curl -s -o /dev/null -w '%{http_code}\n' https://sso.example.com/           # 404
curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration | jq -r .issuer
# → https://sso.example.com/realms/jtvc   (must match exactly)
```

If the issuer shows `http://…`, an internal IP or another port, fix `KC_PUBLIC_URL` in `.env` and run `docker compose up -d`.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 8. Connect jt-vc-portal

Sign in to the portal as an administrator → **Settings** → card **Single sign-on (SSO / OIDC)**.

| Field | Value |
|---|---|
| **Enable single sign-on** | checked |
| **Redirect URI (register this URL at the IdP)** | read-only; shows `<site URL>/sso-callback`. It must equal the redirect URI printed by `configure-realm.sh` (i.e. `PORTAL_URL` = the portal's site URL). |
| **Issuer** | `https://sso.example.com/realms/jtvc` (no trailing path; trailing `/` is trimmed) |
| **Sign-in button label (empty = default)** | optional, default "Sign in with company account (SSO)" |
| **Client ID** | `jt-vc-portal` |
| **Client secret** | contents of `client-secret.txt`. It is never shown again; leaving it empty on later saves keeps the stored secret. |
| **Administrator groups (comma-separated)** | `VC-Admins` |
| **Host groups (comma-separated)** | `VC-Hosts` |
| **The IdP must use HTTPS (keep this checked)** | checked |
| Advanced: scopes and claim names | defaults: scopes `openid email profile`, groups claim `groups`, username claim `preferred_username`, email claim `email`, display name claim `name` |

Click **Save and test connection**. The portal fetches the discovery document and the signing keys (JWKS); success shows "Connected: retrieved the IdP configuration and N signing key(s)". Errors are shown (and audited) with a short reason — see [Troubleshooting](#11-troubleshooting).

Group rules: a user in neither group **cannot sign in**. Matching is case-insensitive; `VC-Admins` wins if a user is in both. Group names with a Keycloak full path (`/A/B`) can be matched by the full path or the last segment.

### First login test

1. Open the portal login page in a private window → **Sign in with company account (SSO)**.
2. Keycloak login page → enter an AD username (`sAMAccountName`) and password of a member of `VC-Hosts`.
3. **First login forces OTP enrolment**: scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, FreeOTP…), enter the code.
4. You land on the portal dashboard. **Account management** shows the new account with an **SSO** badge and the role derived from the group.

SSO accounts have no local password or local 2FA in the portal (OTP is handled by Keycloak). Logging out of the portal also ends the Keycloak session (RP-initiated logout with `id_token_hint`), then returns to `<PORTAL_URL>/`.

Account binding: the portal binds an SSO account by (issuer, `sub`). On first login it creates the account with the username from `preferred_username`; if a **local** account already uses that username or e-mail, the login is refused — it is never merged automatically (prevents account takeover). Rename or delete the local account first.

### Optional: SSO only

When SSO works, you can check **SSO only: regular accounts can no longer sign in with a local password**.

- Saving is refused unless **at least one enabled local administrator** (non-SSO) exists — your emergency account if Keycloak or AD is down.
- In SSO-only mode the local password form is hidden, and `POST /verify` is refused (and audited), for every source IP **not** in **"In SSO-only mode, source IPs / CIDRs still allowed to use local password sign-in"**, e.g. `10.0.1.0/24`. The card shows **your current source IP** — make sure it is covered before saving.
- **Empty = localhost only** (`127.0.0.1`, `::1`). Behind Docker and a reverse proxy no browser usually arrives from localhost, so in practice an empty list means "no local sign-in from the web" — set your admin network explicitly if you want a web fallback.
- IP / CIDR syntax is validated on save.

**Recovery CLI** (on the portal host; not reachable from the web — returns 404):

```bash
docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php show               # current SSO settings
docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable-sso-only   # keep SSO, re-allow local password sign-in
docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable            # turn SSO off completely
```

Both changes keep the stored client secret and are written to the audit log (actor `cli`).

---

<br>
<br>
<br>
<br>
<br>
<br>

## 9. Operations

### Adding / removing users

Access is managed **only through AD group membership**:

- **Grant**: add the user to `VC-Hosts` or `VC-Admins`. Effective at the user's next login (the portal account is created then).
- **Change role**: move the user between groups. The role is re-synchronised at each login (the last enabled portal administrator is never demoted).
- **Revoke**: remove the user from both groups (or disable the AD account). From the next login attempt on, Keycloak no longer finds the user and the portal refuses them.
- **Cut off immediately** (existing sessions): in the portal **Account management**, disable the account, or edit it and check **Sign this account out of all devices**. Optionally also end the Keycloak session: admin console → realm **jtvc** → **Users** → user → **Sessions** → **Sign out**.

### Changing the `svc-keycloak` password

Change it in **both** places, one right after the other:

1. AD: `Set-ADAccountPassword -Identity svc-keycloak -Reset` (Windows) or `udm users/user modify … --set password=…` (UCS).
2. Keycloak: realm **jtvc** → **User federation** → **ad** → **Bind credentials** → new password → **Save** → **Test authentication**.

Until step 2 is done, SSO logins fail.

### Backups

```bash
# Daily (e.g. cron at 02:30)
install -d -m 700 /var/backups/keycloak
cd /opt/keycloak
docker compose exec -T postgres pg_dump -U keycloak -d keycloak -Fc > /var/backups/keycloak/keycloak-$(date +%F).dump
tar -C /opt -czf /var/backups/keycloak/keycloak-files-$(date +%F).tar.gz --exclude=keycloak/data keycloak
find /var/backups/keycloak -mtime +30 -delete
```

The file archive contains `.env`, `realm.env`, `client-secret.txt` and the truststore — keep backups encrypted / access-restricted and copy them off the host.

**Restore** (same Keycloak version as the backup):

```bash
tar -C /opt -xzf keycloak-files-YYYY-MM-DD.tar.gz        # on a new host: restores /opt/keycloak
cd /opt/keycloak
docker compose up -d postgres
docker compose stop keycloak
docker compose exec -T postgres psql -U keycloak -d postgres -c 'DROP DATABASE IF EXISTS keycloak;' -c 'CREATE DATABASE keycloak OWNER keycloak;'
docker compose exec -T postgres pg_restore -U keycloak -d keycloak --no-owner < keycloak-YYYY-MM-DD.dump
docker compose up -d
until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo ready
```

Test a restore on a throw-away host at least once.

### Upgrades

1. Read the Keycloak release notes / migration guide for every version between the current and the target one.
2. Back up (see above).
3. Change `KC_VERSION` in `.env` (always a pinned version).
4. Apply:

   ```bash
   cd /opt/keycloak
   docker compose pull && docker compose up -d
   until curl -sf http://127.0.0.1:9000/health/ready >/dev/null; do sleep 5; done; echo ready
   docker compose logs --tail 100 keycloak
   ```

5. Verify: `curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration | jq -r .issuer`, run the portal's **Save and test connection**, and do a real SSO login.
6. Check the configuration with kcadm (optionally re-run `./configure-realm.sh`, which is idempotent):

   ```bash
   KC="docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh"
   $KC config credentials --server http://localhost:8080 --realm master --user kc-admin
   $KC get realms/jtvc --fields bruteForceProtected,failureFactor,sslRequired
   $KC get clients -r jtvc -q clientId=jt-vc-portal --fields clientId,redirectUris,attributes
   ```

PostgreSQL: the image is pinned to major version 17; minor updates come with `docker compose pull`. A **major** PostgreSQL upgrade needs a dump / restore — do not just change the tag.

### Log forwarding

- Container logs: `docker compose logs keycloak` (Docker's default `json-file` driver under `/var/lib/docker/containers/`).
- Keycloak events (login, login error, logout, OTP enrolment…) go through the **`jboss-logging`** listener to stdout. By default Keycloak logs **error** events at WARN and **success** events at DEBUG (not shown); the provided `docker-compose.yml` already sets `KC_SPI_EVENTS_LISTENER__JBOSS_LOGGING__SUCCESS_LEVEL: info` (note the double underscores — Keycloak 26 option syntax, verified on 26.4) so successful logins are logged at INFO.
- Ship them to your SIEM with the host's log agent, e.g. a **Wazuh agent** reading the Docker JSON log files, or switch the Docker log driver to `journald` / `syslog`.
- jt-vc-portal already sends its own `sso_login` / `sso_fail` audit events to the SIEM configured in the portal.

### Monitoring

```bash
curl -sf http://127.0.0.1:9000/health/ready && echo OK     # on the Keycloak host only
```

Check it from your monitoring agent on the host (port 9000 is bound to localhost). Externally, monitor `https://sso.example.com/realms/jtvc/.well-known/openid-configuration` (HTTP 200) and the TLS certificate expiry.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 10. Security checklist

- [ ] The admin console is **not reachable from the Internet** (`https://sso.example.com/admin/` and `https://sso.example.com/realms/master/` → 404); port 8080 only from the proxy, port 8443 (admin, HTTPS) only from the admin network; port 9000 localhost only.
- [ ] The **bootstrap admin is deleted**, the `KC_BOOTSTRAP_ADMIN_*` lines are removed from `.env` and the container runs without `docker-compose.bootstrap.yml`; the permanent admin uses OTP and is stored in a password manager.
- [ ] **OTP is required** (required action *Configure OTP* enabled + default).
- [ ] Keycloak **brute-force threshold (`LOCKOUT_FAILURES`) < AD lockout threshold**; AD lockout enabled if possible.
- [ ] LDAP federation is **READ_ONLY**, over **LDAPS** with a verified CA (truststore), and the **user filter is restricted to `VC-Admins` / `VC-Hosts`**.
- [ ] `svc-keycloak` is **read-only** (no admin groups), **denied interactive logon**, with a strong password.
- [ ] Client is **confidential**, standard flow only, **PKCE S256** required, **exact redirect URI** (`<PORTAL_URL>/sso-callback`).
- [ ] **TLS only at the proxy with HSTS**; Keycloak's HTTP port never exposed publicly.
- [ ] `.env`, `realm.env`, `client-secret.txt` are **chmod 600**, `/opt/keycloak` is 700; `KC_ADMIN_PASSWORD` / `LDAP_BIND_CREDENTIAL` cleared from `realm.env`.
- [ ] Portal: **The IdP must use HTTPS** checked; if **SSO only** is on, at least one local emergency admin exists and the allowed CIDRs are as narrow as possible.
- [ ] Host patched automatically (`apt install unattended-upgrades`), Keycloak version pinned and updated regularly.
- [ ] **Backups** run daily, are stored off-host, and a **restore has been tested**.

---

<br>
<br>
<br>
<br>
<br>
<br>

## 11. Troubleshooting

| Symptom | Cause / resolution |
|---|---|
| Portal: **"Discovery issuer mismatch"** | The issuer returned by Keycloak differs from the Issuer field. Paste exactly the output of `curl -s https://sso.example.com/realms/jtvc/.well-known/openid-configuration \| jq -r .issuer` (no `/.well-known/…` or other trailing path, same realm name and case). If Keycloak itself reports `http://`, an internal IP or a port, fix `KC_PUBLIC_URL` and the proxy's `X-Forwarded-*` headers, then `docker compose up -d`. |
| Portal: **"IdP endpoint must use https"** | The issuer (or an endpoint in the discovery document) is `http://`. Use the HTTPS URL through the proxy. Unchecking "The IdP must use HTTPS" is only for isolated test environments. |
| Portal: **"IdP connection failed (curl …)"** | The portal host cannot resolve / reach `sso.example.com:443`, or the certificate is not publicly trusted. Test from the portal host: `docker exec jaas-auth curl -sI https://sso.example.com/realms/jtvc`. |
| **LDAPS certificate errors** in the Keycloak log (`PKIX path building failed`, `SSLHandshakeException`, hostname mismatch) | The CA is missing from `truststores/` (must be PEM, readable, file mode 644), or the DC certificate does not contain the name used in `LDAP_URL`. Verify with the `openssl s_client` command in Section 4, then `docker compose restart keycloak`. |
| **Users not found** (login says invalid username or password; *Test authentication* works) | The user is not a **direct** member of the groups, or the custom filter does not match: check that `LDAP_GROUPS_DN` is exactly the container of the groups and the group CNs match (`samba-tool group show` / `Get-ADGroup`). Then re-run `./configure-realm.sh`. |
| **Groups missing in the token** → portal: "user not in any allowed group" (audit log) | The `vc-groups` LDAP group mapper or the client's `groups` protocol mapper is missing / wrong (re-run the script). Check the group names in the portal (**Administrator groups** / **Host groups**) and that the groups claim is `groups`. Users in neither group are denied by design. |
| **OTP code rejected** | Clock drift: sync time on the Keycloak host (`timedatectl`, chrony / systemd-timesyncd) and on the phone. A code **cannot be reused** — wait for the next 30-second code. |
| Portal: **"username or email conflicts with an existing account"** (audit log) | A **local** portal account with the same username or e-mail exists. Rename or delete that local account (SSO accounts are never merged with local ones), then log in again. |
| Portal: **"account disabled"** | The portal account was disabled in Account management — enable it there. |
| **Everyone locked out** (Keycloak or AD down, SSO only enabled) | On the portal host: `docker exec -u www-data jaas-auth php /var/www/html/sso-cli.php disable-sso-only`, then sign in with the local emergency admin. |
| **Keycloak account locked** after wrong passwords | Brute-force detection: wait (5 min, growing up to 30 min) or unlock in the admin console → **Users** → user → toggle *Temporarily locked* off. |
| `502 Bad Gateway` / `upstream sent too big header` at the proxy | Missing proxy buffer settings (`kc-proxy.conf`). |
| Admin console shows **"Something went wrong"** | It was opened over plain HTTP (e.g. `http://10.0.0.20:8080/admin/`). Keycloak 26 needs a secure context: use `https://10.0.0.20:8443/admin/` (Section 4, certificate in `certs/`). |
| Admin console **spins forever** / browser console shows a failed request to `sso.example.com/realms/master/…` | The master realm's login page still uses the public URL (not resolvable yet, or refused by the proxy). Set `KC_ADMIN_URL` in `realm.env` and re-run `./configure-realm.sh` (it sets the master realm Frontend URL). |
| Admin console loops or shows "HTTPS required" | Access it through `KC_ADMIN_URL` from a **private** address (the master realm has `sslRequired=external`). |

---

<br>
<br>
<br>
<br>
<br>
<br>

## 12. How this is tested

- **`tests/run-sso.sh`** — end-to-end integration test that touches no production system. It starts a throw-away Keycloak, configures it with **the same `configure-realm.sh`** in local-users mode (`LDAP_URL` empty, using the `KCADM` / `KC_SERVER` / `SECRET_OUT` overrides), creates test users in `VC-Admins`, `VC-Hosts` and no group, builds and starts a throw-away portal, and runs **52 browser / integration checks** (Playwright), including: OTP enrolment on first login and TOTP login, group → role mapping, refusal of users in no allowed group, PKCE S256 / `state` / `nonce` in the authorization request, fixed redirect URI, callback replay refusal, username conflicts with local accounts, disabled accounts (existing session invalidated), SSO-only mode with the IP allow-list and the local-admin safeguard, save-and-test connection, fail2ban lockout, Keycloak brute-force lockout, Keycloak rejecting requests without PKCE or with an unregistered redirect URI, RP-initiated logout, the `sso-cli.php` commands, re-running the script (idempotent) and `KC_ADMIN_URL` setting the master realm Frontend URL without changing the public issuer, and the login pages following the browser language (Traditional Chinese / Japanese / English, including the admin login).
- **`tests/unit/test_oidc.php`** — unit tests of `lib/oidc.php` with locally generated RSA keys and forged ID tokens: signature and algorithm whitelist (`none` / `HS*` refused), `iss` / `aud` / `azp` / `exp` / `iat` / `nonce` / `sub` checks, JWK → PEM conversion, group → role mapping, account provisioning and conflict rules, HTTPS / host restrictions for IdP endpoints.

Run them before every release together with the rest of the test suite (`tests/run-unit.sh`, `tests/run-sso.sh`).
