# Cloud deployment

How a client's POS runs in the cloud: the server, its clients, releases, backups, monitoring and security. Written for Webxkey (whoever sets up and looks after the servers). The clinic's own pages are [TILLS.md](TILLS.md) (the till PCs), [GO-LIVE.md](GO-LIVE.md) (the go-live runbook) and [DATA-PROTECTION.md](DATA-PROTECTION.md) (the note the client approves).

**Decision (2026-10-22).** The app runs on a cloud VPS (Ubuntu 26.04 LTS, Singapore or Mumbai) behind the client's domain with HTTPS, not on a server in the clinic. The clinic's two till PCs (T1 General Pharmacy, T2 Doctor's Medicines / OPD) use it through Chrome; during an internet outage they keep billing with the offline till and upload when the connection is back. One app and one database per client; several clients may share a VPS; each client has a production and a staging environment, with staging **recommended on its own small VPS** (one staging VPS can hold every client's staging; staging on the production VPS is still supported). The same setup serves the retail client (`--profile=retail`). This replaces the "local server" of [PHARMACY.md §9, branch 12](../PHARMACY.md#9-branch-plan).

```
 Clinic                          Internet                  VPS (Singapore / Mumbai)
 ┌──────────────┐  broadband +                            ┌───────────────────────────────────────┐
 │ T1 GEN  Chrome├─ 4G failover ─── HTTPS (443) ─────────▶│ Caddy (Let's Encrypt)                 │
 │ T2 OPD  Chrome│  router                                │  ├─ pos.clinic.lk  → PHP-FPM pool      │
 │ printers,     │                                        │  │   pos-clinic (user pos-clinic)      │
 │ scanners      │                                        │  └─ other clients' production …        │
 └──────────────┘                                         │ MySQL 8.4 (localhost only)             │
                                                          │ per client: queue worker, scheduler    │
                                                          └──────────────┬────────────────────────┘
                                         nightly, age-encrypted          │ rclone
                                                          ┌──────────────▼────────────────────────┐
                                                          │ Object storage, another provider       │
                                                          │ (Backblaze B2 / Cloudflare R2 / S3)    │
                                                          └───────────────────────────────────────┘

 Staging VPS (smaller, recommended): staging.pos.clinic.lk → pos-clinic-staging, the same
 layout with its own database and backups. Every release reaches it first.
```

## Contents

1. [Server size](#server-size)
2. [DNS](#dns)
3. [Layout on the server](#layout-on-the-server)
4. [Provisioning](#provisioning)
5. [Adding a client](#adding-a-client)
6. [Releases](#releases)
7. [Deploying](#deploying) and [rolling back](#rolling-back)
8. [Backups](#backups) and [restore](#restore)
9. [Monitoring](#monitoring)
10. [Security](#security), including [APP_KEY](#app_key)
11. [What the clinic needs](#what-the-clinic-needs)
12. [Maintenance](#maintenance)
13. [Troubleshooting](#troubleshooting)
14. [Rehearsal on a staging VPS](#rehearsal-on-a-staging-vps)

## Server size

**Recommended: two VPSs**, production and a smaller staging one. A release, a migration or a mistake rehearsed on staging can then never touch production's disk, memory or database, and the staging VPS can be resized, rebuilt or used for the restore drill freely. One staging VPS serves the staging of every client.

| VPS                                           | Size                         | Notes                                                                                              |
| --------------------------------------------- | ---------------------------- | -------------------------------------------------------------------------------------------------- |
| **Production**, one client (the clinic)       | 2 vCPU, 4 GB RAM, 80 GB SSD  | Enough with room to spare: two tills and an admin are light traffic.                               |
| **Staging**, every client's staging           | 1 vCPU, 2 GB RAM, 50 GB SSD  | Recommended. Light use; the swap file covers peaks.                                                |
| Production, up to four client environments    | 2 vCPU, 4 GB RAM, 80 GB SSD  | Each environment adds a PHP pool (up to ~0.5 GB at its busiest) and a queue worker (~100 MB).      |
| Production and staging on one VPS (supported) | 2 vCPU, 4 GB RAM, 80 GB SSD  | Cheaper; staging then shares production's resources and database server.                           |
| More, or a client with many tills             | 4 vCPU, 8 GB RAM, 160 GB SSD | Raise `innodb_buffer_pool_size` in `/etc/mysql/mysql.conf.d/zz-pos.cnf` to about a quarter of RAM. |

- **Region:** Mumbai or Singapore, whichever the provider offers closest to the clients (Colombo to Mumbai is usually the shorter round trip). Any provider with Ubuntu 26.04 LTS images (DigitalOcean, Vultr, Linode/Akamai, AWS Lightsail …).
- **Disk:** the database grows slowly (a busy clinic adds well under 1 GB a year); the server keeps 5 releases and the 2 newest backups; logs rotate.
- Turn on the provider's **disk encryption at rest** where it is offered, and its **weekly snapshots** as a second line behind the backups.

## DNS

At the client's domain (or a Webxkey domain), for each environment:

| Record                  | Type | Value                       | Why                                           |
| ----------------------- | ---- | --------------------------- | --------------------------------------------- |
| `pos.clinic.lk`         | A    | the production VPS's IPv4   | production                                    |
| `staging.pos.clinic.lk` | A    | the staging VPS's IPv4      | staging (on a shared VPS: production's IPv4)  |
| both, if a VPS has IPv6 | AAAA | that VPS's IPv6             | optional                                      |
| `clinic.lk`             | CAA  | `0 issue "letsencrypt.org"` | only Let's Encrypt may issue its certificates |

Use a short TTL (300 s) while setting up. If the DNS is at Cloudflare, leave these records **DNS only** (grey cloud): through Cloudflare's proxy the app would see Cloudflare's addresses instead of the clinic's (the admin IP allowlist and the login limits work per address). The records must point at the VPS **before** `add-client.sh`, or Caddy cannot get the certificate (it retries by itself).

## Layout on the server

Each client environment is a Linux user, a folder, a database and a PHP pool of its own; one client's app cannot read another's files or database.

```
/srv/pos/
  incoming/                  release packages uploaded by the deploy user
  clinic/                    owner pos-clinic, 750 (Caddy reads public/ through the group)
    current -> releases/20261022093000-v1.1.0
    releases/<time>-<version>/   the code (vendor installed by composer --no-dev); .deploy records each deploy
    shared/.env              600: settings and secrets (APP_KEY, the database password)
    shared/storage/          uploads, logs, sessions, caches (linked into every release)
    backups/                 700: the 2 newest encrypted backups and status.json
    keys/server-verify.key   600: the server's age key, used by the weekly backup check
    rclone.conf              600: the backup store's credentials
    bin/                     deploy.sh, rollback.sh (sudo rule: deploy may run these as pos-clinic)
    releases.log             every deploy and rollback
  clinic-staging/            the same, for staging (on the staging VPS; here only on a shared VPS)
```

| Per client environment     | Name                                                                                                           |
| -------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Linux user and group       | `pos-<client>` (no login shell)                                                                                |
| Database, scratch database | `pos_<client>`, `pos_<client>_verify`; MySQL user `pos_<client>`, rights on those two only                     |
| PHP-FPM pool               | `/etc/php/8.5/fpm/pool.d/pos-<client>.conf`, socket `/run/php/pos-<client>.sock`, `open_basedir` to its folder |
| Caddy site                 | `/etc/caddy/sites/<client>.caddy`                                                                              |
| Queue worker               | `pos-queue@<client>.service`                                                                                   |
| Scheduler                  | `pos-scheduler@<client>.timer` (every minute)                                                                  |

The templates are in [deploy/](../../deploy/README.md).

## Provisioning

Once per VPS (the production VPS and the staging VPS alike), as root, from a checkout of this repository on the server (or a copy of `scripts/` and `deploy/`):

1. Create the VPS (Ubuntu 26.04 LTS) with your SSH key for root.
2. On your machine, make the key GitHub Actions will deploy with: `ssh-keygen -t ed25519 -f pos-deploy -C github-actions` (the private half goes into GitHub, [Releases](#releases)).
3. On the server:

    ```bash
    git clone https://github.com/<org>/pos-system.git /root/pos && cd /root/pos
    sudo bash scripts/provision-server.sh --acme-email=ops@webxkey.lk \
         --deploy-key="$(cat pos-deploy.pub)" --admin-key="ssh-ed25519 AAAA… you@webxkey"
    ```

    `--disable-root-login` also turns root's SSH login off (do it once you have checked you can sign in as `deploy` and use `sudo`). `--swap=4G` for a larger swap file.

It is safe to run again. It installs and sets up:

- **PHP 8.5-FPM** with bcmath, intl, gd, mysql, mbstring, zip, xml, curl, sqlite3, opcache (`deploy/php/pos.ini`: no `expose_php`, errors to logs only, upload limits, OPcache); the default `www` pool is switched off.
- **MySQL 8.4** listening on 127.0.0.1 only (no X Protocol, no `LOCAL INFILE`, UTC), anonymous users and the test database removed; root signs in through the local socket only.
- **Caddy** from Caddy's own repository (Ubuntu's is too old for `resolve_root_symlink`), which gets and renews each domain's Let's Encrypt certificate by itself.
- **age** (backup encryption), **rclone** (backup upload), **Composer** (checked against its signature), git, unzip.
- The **deploy** user, SSH **keys only** (`/etc/ssh/sshd_config.d/10-pos.conf`); it refuses to turn passwords off while no key is in place.
- **ufw**: incoming only 22 (rate limited), 80, 443 (TCP and UDP). **fail2ban** bans an address for an hour after 5 failed SSH logins in 10 minutes.
- **Unattended security upgrades** nightly, no automatic reboot (System health shows when one is needed).
- **Swap** (2 GB), swappiness 10; **chrony** time sync, timezone UTC; **journald** capped at 500 MB / 30 days; logrotate for the PHP-FPM logs (the app's own logs are daily, 14 kept).
- `/srv/pos`, `/srv/pos/incoming` and the systemd templates `pos-queue@.service`, `pos-scheduler@.service` and `.timer`.

## Adding a client

Once per client **and** environment (production and staging are two runs with different names, domains and databases: production on the production VPS, staging on the staging VPS). You need:

1. **The release package**: from the client's latest GitHub Release (`pos-v1.1.0.tar.gz`), copied to the server (`scp … deploy@vps:/srv/pos/incoming/`).
2. **The offline recovery key's public half.** On an offline machine at Webxkey (not the server), one key per client:

    ```bash
    age-keygen -o pos-recovery-clinic.key      # prints "Public key: age1…"
    ```

    Print the key file twice (one copy for the owner, one for Webxkey's safe) and copy it to two USB sticks; then delete it from that machine. The **private half never goes on the server**: it is what opens the backups if the server is lost. Only the `age1…` public half is passed to the script.

3. **A bucket at another provider** than the VPS (Backblaze B2, Cloudflare R2 or AWS S3), with an application key limited to that bucket, and an rclone configuration for it:

    ```bash
    rclone config --config /root/clinic-rclone.conf     # e.g. a "b2" remote with the key
    ```

    Turn on the bucket's **versioning** (or object lock) and keep old versions for 30 days: a compromised server could otherwise delete the backups along with its own data.

Then, as root:

```bash
sudo bash scripts/add-client.sh --client=clinic --domain=pos.clinic.lk --env=production \
     --profile=pharmacy --app-name="Clinic POS" --timezone=Asia/Colombo \
     --recovery-recipient=age1… --backup-remote=b2:webxkey-pos-backups/clinic-production \
     --rclone-config=/root/clinic-rclone.conf --alert-email=ops@webxkey.lk \
     --release=/srv/pos/incoming/pos-v1.1.0.tar.gz

sudo bash scripts/add-client.sh --client=clinic-staging --domain=staging.pos.clinic.lk --env=staging \
     --profile=pharmacy … --backup-remote=b2:webxkey-pos-backups/clinic-staging …
```

It creates everything in [the layout above](#layout-on-the-server), writes the `.env` (`APP_ENV=production` or `staging`, `APP_DEBUG=false`, a new `APP_KEY`, the generated database password, sessions in the database, encrypted, secure cookies only, the backup settings), deploys the release (no backup, nothing to back up yet), starts the queue worker and the scheduler, and runs `pos:install --profile=…` for the shop and its owner (interactive: shop name, code, currency, timezone, owner name, email, password). Staging also sends `X-Robots-Tag: noindex`. Run again, it keeps the existing `.env` (and so the `APP_KEY` and password).

Afterwards:

1. Put the `APP_KEY` and the database password in Webxkey's password manager under the client and environment ([APP_KEY](#app_key)).
2. `sudo -u pos-clinic php /srv/pos/clinic/current/artisan pos:backup`, then `… pos:backup:verify`: both must succeed.
3. Sign in as the owner, turn on two-factor authentication, open **Administration → System health**: everything OK (mail may say "Not set up").
4. Optional, mail for alerts: set `MAIL_MAILER=smtp` and the `MAIL_*` values in `shared/.env` (any SMTP service: Amazon SES, Mailgun, Zoho …), then `sudo -u pos-clinic php /srv/pos/clinic/current/artisan optimize`.
5. An uptime monitor ([Monitoring](#monitoring)).

## Releases

Releases are semantic version tags, recorded in [CHANGELOG.md](../../CHANGELOG.md). To release:

1. Move the changelog's **Unreleased** section under the new version and date; commit to `main`.
2. `git tag v1.2.0 && git push origin v1.2.0` (a pre-release, `v1.2.0-rc.1`, stops at staging).

The `release` workflow (`.github/workflows/release.yml`) then:

1. runs the whole test suite (the `tests` workflow: SQLite, the full suite on MySQL, the MySQL locking group);
2. builds the assets and packages the release (`scripts/package-release.sh`: the tagged code, the built assets, a `VERSION` file; no `vendor/`, no tests) and publishes it on **GitHub Releases** with its SHA-256 and the changelog section as notes;
3. deploys it to **staging** at once;
4. waits for approval, then deploys it to **production**.

Set up once in GitHub, **Settings → Environments**:

| Environment  | Protection                                                                | Variables                                                                               | Secrets                                                                                                        |
| ------------ | ------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `staging`    | deployment tags `v*`                                                      | `DEPLOY_HOST` (the staging VPS), `DEPLOY_CLIENTS` (`clinic-staging`)                    | `DEPLOY_SSH_KEY` (private half of `pos-deploy`), `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan -t ed25519 <staging vps>`) |
| `production` | **required reviewers** (you, the person who approves releases), tags `v*` | `DEPLOY_HOST` (the production VPS), `DEPLOY_CLIENTS` (`clinic`, or `clinic retailshop`) | `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan -t ed25519 <production vps>`)                             |

`DEPLOY_CLIENTS` lists every client on that VPS that runs this code; each is deployed in turn. A client on another VPS needs its own environment pair (e.g. `production-retail`) and a copy of the two deploy jobs.

## Deploying

The workflow uploads the package to `/srv/pos/incoming/` and runs, as the deploy user:

```bash
sudo -u pos-clinic /srv/pos/clinic/bin/deploy.sh --release=/srv/pos/incoming/pos-v1.2.0.tar.gz
```

which, for that client (`scripts/deploy.sh`):

1. unpacks the release into `releases/<time>-v1.2.0` and links `shared/.env` and `shared/storage`;
2. `composer install --no-dev --optimize-autoloader`;
3. **backs up first**: `pos:backup --label=pre-deploy`, with the release running now (a failed backup stops the deploy);
4. counts the migrations to run; if there are any, **maintenance mode** (`artisan down`). The tills switch to offline billing meanwhile and upload afterwards, so the clinic keeps selling;
5. `migrate --force`, then `optimize` (config, routes, views, events) and `filament:optimize`;
6. switches the `current` symlink in one step. Caddy resolves it on every request (`resolve_root_symlink`), so requests already running finish on the old release and new ones start on the new one, without reloading PHP: **no downtime** when there are no migrations;
7. `queue:restart` (the worker finishes its job and systemd starts it on the new code), maintenance off, and checks `/up`;
8. records the deploy in `releases/<id>/.deploy` and `releases.log`, keeps the newest 5 releases and installs the release's own `deploy.sh` / `rollback.sh` for next time.

A failed migration leaves `current` on the old release and the app in maintenance; the message says what to do (fix and deploy again, or `rollback.sh --restore`). `--dry-run` prints the plan without doing anything; `--maintenance=always|never` overrides the automatic choice.

By hand, without GitHub: copy the package from GitHub Releases to `/srv/pos/incoming/` and run the same command.

## Rolling back

```bash
ssh deploy@vps
sudo -u pos-clinic /srv/pos/clinic/bin/rollback.sh             # back to the release before
sudo -u pos-clinic /srv/pos/clinic/bin/rollback.sh --restore   # …and the database as it was before that deploy
```

Without `--restore`: maintenance on, the deploy's migrations are rolled back with the newer code (`migrate:rollback --step=<the number it ran>`, read from its `.deploy` record), `current` goes back, the older release's caches are rebuilt, the queue restarts, maintenance off. If a migration cannot be reversed, it stops in maintenance mode and asks for `--restore`.

With `--restore`: `current` goes back and the database and files are restored from that deploy's `pre-deploy` backup (`pos:backup:restore`, which makes its own safety backup first). **Anything recorded since that deploy is lost**: afterwards compare each till's sales with the paper receipts and re-enter what is missing. Prefer a fix-forward release when the clinic has billed for hours since.

`--to=<release folder>` goes back to an older release; `--dry-run` prints the plan.

## Backups

| What         | How                                                                                                                                                                                                                                                                                                               |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Contents     | The whole database (`mysqldump --single-transaction`: a consistent snapshot, tills are not locked out), `storage/app` (logos, uploads, import files) and the `.env`                                                                                                                                               |
| Encryption   | One `tar.gz` encrypted with **age** (X25519, ChaCha20-Poly1305) to two recipients: the **offline recovery key** and the **server's verify key**. Nothing leaves the server unencrypted                                                                                                                            |
| Where        | The 2 newest on the server (`/srv/pos/<client>/backups`); every one in the bucket at the other provider (rclone), with its SHA-256 beside it (`.json`)                                                                                                                                                            |
| When         | Nightly at 02:30 clinic time (`pos:backup`, scheduler), before every deploy (`pre-deploy`), before every restore (`pre-restore`), before the go-live reset, and **Back up now** on System health                                                                                                                  |
| Kept         | The newest backup of each of the last **14 days** and of the last **12 months** (`pos:backup` prunes after each run)                                                                                                                                                                                              |
| Checked      | Weekly, Sunday 04:00 (`pos:backup:verify`): downloads the newest from the bucket, checks its checksum, decrypts it with the server's verify key, restores it into the scratch database `pos_<client>_verify` and checks every table's row count against what the backup recorded, then drops the scratch database |
| Alerts       | A failed backup or check mails the owners and `POS_ALERT_EMAIL` at once; no backup in 26 hours, or no check in 8 days, is a failing health check (hourly alert)                                                                                                                                                   |
| Owner's copy | **System health → Download latest backup** (the encrypted file, audited); it opens only with the recovery key                                                                                                                                                                                                     |

**Why two keys.** The recovery key's private half is kept offline (paper and USB with the owner and in Webxkey's safe): with only that key, a stolen bucket or a leaked bucket key reveals nothing. The server's verify key lets the weekly check really restore the backup; whoever controls the server can read the live database anyway, so it adds no exposure there. Without the verify key (`POS_BACKUP_AGE_IDENTITY` empty), verify checks the checksum only and System health says so.

```bash
cd /srv/pos/clinic/current
sudo -u pos-clinic php artisan pos:backup:list           # what is in the bucket, newest first
sudo -u pos-clinic php artisan pos:backup                # one now
sudo -u pos-clinic php artisan pos:backup:verify         # check the newest now
```

## Restore

On the same server (a mistake, a bad import):

```bash
sudo -u pos-clinic php artisan pos:backup:restore                                  # the newest in the bucket
sudo -u pos-clinic php artisan pos:backup:restore pos-backup-20261020-210000.tar.gz.age
```

It asks for the database's name to be typed (`--confirm=pos_clinic` without a terminal), makes a `pre-restore` safety backup of what is there now, puts the app in maintenance, replaces the database (every table dropped first) and `storage/app`, runs the migrations (a backup older than the code is brought up to date), clears the cache and comes back up. The `.env` in use is never replaced. Everything recorded after that backup is gone: check the tills' sales against the paper receipts.

**On a new server** (the old one is lost), with the recovery key:

1. [Provision](#provisioning) a VPS and [add the client](#adding-a-client) with the **same** name, domain, recovery recipient and bucket (point the DNS at the new VPS first). When `pos:install` asks for the shop name, press Ctrl+C: the restore brings the shop back.
2. Copy the newest backup and the recovery key to the server:

    ```bash
    sudo -u pos-clinic rclone --config /srv/pos/clinic/rclone.conf copy b2:webxkey-pos-backups/clinic-production/pos-backup-20261020-210000.tar.gz.age /srv/pos/clinic/backups/
    # the recovery key from the USB stick, e.g. to /root/recovery.key (chmod 600)
    ```

3. Put the old `APP_KEY` back (two-factor secrets and sessions need it, [APP_KEY](#app_key)): from the password manager, or from the backup itself:

    ```bash
    age -d -i /root/recovery.key /srv/pos/clinic/backups/pos-backup-….tar.gz.age | tar -xzO env/.env | grep ^APP_KEY=
    ```

    Replace `APP_KEY=` in `/srv/pos/clinic/shared/.env` with it.

4. Restore with the recovery key:

    ```bash
    cd /srv/pos/clinic/current
    sudo cp /root/recovery.key /srv/pos/clinic/keys/recovery.key && sudo chown pos-clinic /srv/pos/clinic/keys/recovery.key
    sudo -u pos-clinic php artisan pos:backup:restore --file=/srv/pos/clinic/backups/pos-backup-….tar.gz.age \
         --identity=/srv/pos/clinic/keys/recovery.key --confirm=pos_clinic --no-safety-backup
    sudo shred -u /srv/pos/clinic/keys/recovery.key /root/recovery.key
    sudo -u pos-clinic php artisan optimize
    ```

5. The tills keep their registration (the restored database has their tokens). Sales a till still held offline upload by themselves once it is online; sales that had already reached the lost server after the backup are not in it: compare each till's sales with the paper receipts and re-enter what is missing.

**Restore drill.** Once before go-live and then every three months: restore the newest production backup into **staging** (add-client staging with the production recovery recipient, or restore by `--file` with the recovery key) and check the figures. The weekly verify proves the backups open; the drill proves the people and the steps work.

## Monitoring

- **Uptime:** an external monitor (UptimeRobot, Better Stack … free plans) checks `https://pos.clinic.lk/up` every 5 minutes and alerts Webxkey by email and SMS. `/up` answers 200 only when PHP and the database both work.
- **System health** (Administration → System health, permission `system.health`, the owner): the version and environment; debug pages off; HTTPS; the database and its size; the disk (fails at 85% used); the queue worker and the scheduler (heartbeats every minute; fail after 5 minutes without); the last backup (fails after 26 hours, or when it was kept on the server only); the last backup check (fails after 8 days or when it failed); the owners' two-factor authentication; mail for alerts; a server restart waiting after security updates. Then each till: last sync, its last offline sale received, offline sales waiting to upload and its clock against the server's. The dashboard shows three tiles for the backup, its check and the health checks.
- **Alerts:** `pos:health --alert` runs hourly and mails each failing check to the owners and `POS_ALERT_EMAIL` (also in the owners' bell), at most once per check every 12 hours; a failed backup or check is mailed at once. Without mail set up (`MAIL_MAILER=log`) alerts go to the log and the bell only, and System health says so.
- On the server: `sudo -u pos-clinic php /srv/pos/clinic/current/artisan pos:health` prints the same checks.

## Security

| Area                         | What is in place                                                                                                                                                                                                                                                                                                                                                       |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Network                      | Only 22, 80 and 443 open; SSH by key only, fail2ban; MySQL on localhost only                                                                                                                                                                                                                                                                                           |
| HTTPS                        | Let's Encrypt through Caddy, HTTP redirected; `Strict-Transport-Security` (1 year); session cookies only over HTTPS, encrypted, HTTP-only                                                                                                                                                                                                                              |
| Sign-in                      | Fortify for the till **and** the admin panel (`/admin` sends to `/login`; the panel's own login, which skipped the two-factor code, is gone). Five attempts a minute per email and address, and 20 a minute per address whatever the email                                                                                                                             |
| Two-factor                   | **Required for the owner** (`POS_REQUIRE_2FA=true`, `pos.security.two_factor_roles`): until it is on, every page sends the owner to Settings → Security. Optional for everyone else. Passkeys also available                                                                                                                                                           |
| Passwords                    | In production (`POS_STRICT_PASSWORDS`): at least 12 characters for anyone who can open the admin panel, at least 10 for till-only users (cashiers), never one known from a breach (Have I Been Pwned, by the first 5 characters of its hash only); no case or symbol rules; bcrypt                                                                                     |
| Idle sign-out                | Administration → Security: 30 minutes in the admin panel; **off at the till** (0; decided 2026-10-22: a till signed out during an outage could not bill. A PIN lock that works offline is a future item). Background refreshes (the dashboard's charts) do not count as activity. If a till time is set, an offline till still stays signed in until it is back online |
| Headers                      | `Content-Security-Policy` (everything from the site itself; inline scripts allowed for Livewire and Alpine; no framing, plugins or other origins), `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`                                                                                                  |
| Admin from known places only | Optional: Administration → Security → **Only from these addresses** (IPs or ranges); the till is never limited. Locked out: `php artisan pos:admin-ip --clear` on the server                                                                                                                                                                                           |
| Errors                       | `APP_DEBUG=false` (System health fails if it is on in production); PHP errors to logs only                                                                                                                                                                                                                                                                             |
| Audit                        | Changes to users, settings, prices, costs and suppliers in the audit log (owner only). Retention: Administration → Security, **Keep audit log entries for (days)** (0 = all; at least 365), pruned nightly by `pos:audit-prune`; cost revaluations are always kept                                                                                                     |
| Isolation                    | One Linux user, PHP pool (`open_basedir`) and database user per client environment                                                                                                                                                                                                                                                                                     |
| Updates                      | Security updates nightly; releases only through the tested release workflow                                                                                                                                                                                                                                                                                            |

### APP_KEY

`APP_KEY` (in `shared/.env`) encrypts, in this app:

- each user's **two-factor secret and recovery codes** (Fortify);
- **sessions** (`SESSION_ENCRYPT=true`) and the encrypted cookies (remember me);
- the **passkey user handles** (`PASSKEYS_USER_HANDLE_SECRET` defaults to it);
- password reset links in flight.

Sales, stock, products and every other business record are **not** encrypted with it, so losing it loses no business data. But without the same key, after a restore on a new server: everyone with two-factor authentication must have it reset, passkeys must be registered again, and everyone signs in again. So:

1. When a client is added, put its `APP_KEY` (and the database password) in Webxkey's password manager, under the client and environment.
2. It is also inside every backup (`env/.env`, encrypted), so the recovery key recovers it ([Restore](#restore), step 3).
3. Never change it on a running install (`php artisan key:generate` would sign everyone out and break every two-factor secret). If it leaked, rotate it with Laravel's `APP_PREVIOUS_KEYS` (put the old key there, a new one in `APP_KEY`, `optimize`) and then have the owner turn two-factor authentication off and on again.

Resetting one user's two-factor authentication (a lost phone without recovery codes), after checking who is asking:

```bash
sudo -u pos-clinic php /srv/pos/clinic/current/artisan tinker --execute="App\Models\User::where('email', 'owner@clinic.lk')->firstOrFail()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();"
```

The owner is asked to set it up again at the next sign-in.

## What the clinic needs

- **Internet:** a fixed broadband line (fibre or ADSL, 10 Mbit/s is plenty; the tills send little data) **and a router with 4G failover** (a dual-WAN router with a SIM, switching over by itself). The tills keep billing through an outage either way, but with failover the clinic rarely notices one.
- **Power:** a UPS for the router, both till PCs and the receipt printers (30 minutes).
- **Till PCs:** Windows 10/11 or Ubuntu with Chrome, the receipt printer, scanner and cash drawer: [TILLS.md](TILLS.md).

What works without internet (the till must have been **set up for offline sales** and signed in before the outage):

| Works offline (on each till)                                                                                                           | Needs internet                                                                                   |
| -------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Searching and scanning items (the till's own copy of the catalog, refreshed every 5 minutes online)                                    | Signing in (a till signed out during an outage stays out until it is back)                       |
| Sales: units and packs, batches (first expiry first), prescriptions, OPD bills with the Medicines amount, cash, card and bank transfer | Recent sales, reprints, closing the shift (it waits until the till's offline sales are uploaded) |
| Printing the receipt                                                                                                                   | Returns, refunds and voids (admin panel)                                                         |
| Holding the sales, numbered from the till's own reserved invoice numbers (200 at a time)                                               | Stock requests, the admin panel, reports, receiving, transfers, counts, settings                 |
|                                                                                                                                        | Controlled drugs, when the controlled-drug register is on                                        |
|                                                                                                                                        | Price or stock changes made in the admin panel reach a till at its next sync                     |

When the connection is back, each till uploads its sales by itself within a minute; the server accepts them even if stock went below zero (it happened) and flags anything unusual for review (Sales, the **Needs review** tab). System health shows each till's offline sales waiting.

## Maintenance

- **Weekly:** look at System health (or rely on the alerts): backups, the check, disk.
- **Server restarts:** security updates install nightly without rebooting. When System health shows **Server restart: Needed**, run `sudo reboot` after the clinic closes; everything starts by itself (Caddy, PHP-FPM, MySQL, each client's worker and timer). The tills bill offline for the minute it takes.
- **Ubuntu, PHP or MySQL upgrades** (beyond security updates): rehearse on a staging VPS first, take a backup, do it after hours.
- **Disk:** releases beyond 5 and backups beyond 2 are removed by themselves; logs rotate. If it still fills up, resize the disk.
- **Every three months:** the [restore drill](#restore); check that the bucket's versioning and the uptime monitor still work; remove people who left (users in the app, SSH keys on the server).

## Troubleshooting

| Problem                                                                    | Look at / fix                                                                                                                                                                                                         |
| -------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The site has no certificate / browser warning                              | DNS must point at the VPS (`dig +short pos.clinic.lk`), ports 80 and 443 open (`ufw status`); `journalctl -u caddy -n 50`. Caddy retries by itself                                                                    |
| 502 Bad Gateway                                                            | The client's PHP pool: `systemctl status php8.5-fpm`, `/srv/pos/<client>/shared/storage/logs/php-fpm.log`; `current` must point at a release                                                                          |
| 500 error                                                                  | `/srv/pos/<client>/shared/storage/logs/laravel-<date>.log`; after changing `.env`: `sudo -u pos-<client> php artisan optimize`                                                                                        |
| "Queue worker: Never seen" / imports never finish                          | `systemctl status pos-queue@<client>`, `journalctl -u pos-queue@<client> -n 50`                                                                                                                                       |
| "Scheduler" failing / no nightly backup                                    | `systemctl list-timers 'pos-scheduler@*'`, `journalctl -u pos-scheduler@<client> -n 50`                                                                                                                               |
| Backup fails                                                               | The alert's message; `sudo -u pos-<client> php artisan pos:backup` shows it. Common: rclone credentials (`rclone --config /srv/pos/<client>/rclone.conf lsd <remote>:`), `POS_BACKUP_AGE_RECIPIENTS` empty, disk full |
| Backup check fails "does not match what was backed up"                     | The backup or the restore is broken: make a new backup and check it at once; tell the client nothing is lost (the live database is fine)                                                                              |
| Owner locked out of the admin panel ("cannot be opened from this network") | `sudo -u pos-<client> php /srv/pos/<client>/current/artisan pos:admin-ip --clear`                                                                                                                                     |
| Owner lost the two-factor phone and the recovery codes                     | [Reset it](#app_key) after checking who is asking                                                                                                                                                                     |
| A till says "This till can no longer sync. Register it again."             | Its token was revoked (Tills screen, or the go-live reset): the owner signs in on that PC and presses **Set up offline sales**                                                                                        |
| A till's sales are flagged "clock"                                         | The PC's clock is wrong: turn on automatic time in Windows / Ubuntu ([TILLS.md](TILLS.md))                                                                                                                            |
| A deploy failed half way                                                   | `releases.log`, the workflow's log. `current` is unchanged; the app may be in maintenance (`… artisan up` once fixed, or `rollback.sh --restore`)                                                                     |
| A disk alert                                                               | `du -sh /srv/pos/*/releases /srv/pos/*/backups /var/log`; old releases go at the next deploy; resize the disk if the database itself has grown                                                                        |

## Rehearsal on a staging VPS

The whole install, end to end, on the **staging VPS** before anything touches production: from the server to a working till, backup and restore, an update and a rollback, and the go-live reset. Allow a day. The same steps, with production's names, build production afterwards.

**A. Server, DNS, storage**

1. Buy the staging VPS: Ubuntu 26.04 LTS, 1 vCPU / 2 GB / 50 GB, Mumbai or Singapore, with your SSH key for root.
2. DNS: `staging.<domain>` A → the staging VPS's IPv4, TTL 300, DNS only.
3. A bucket at another provider (B2 or R2) with versioning (old versions kept 30 days) and an application key for that bucket only.

**B. Keys** (on your machine; the recovery key on an offline machine)

4. `ssh-keygen -t ed25519 -f pos-deploy -C github-actions` (the deploy key, also used for production).
5. `age-keygen -o pos-recovery-clinic.key`: note the `age1…` public key, print the file twice, copy it to two USB sticks, delete it from that machine. (Staging and production may share the client's recovery key, so the restore drill can use production backups.)
6. `rclone config --config clinic-staging-rclone.conf` (the bucket key), then `scp` it to the staging VPS as `/root/clinic-rclone.conf`.

**C. Provision the staging VPS**

7. ```bash
   git clone https://github.com/<org>/pos-system.git /root/pos && cd /root/pos
   sudo bash scripts/provision-server.sh --acme-email=you@… --deploy-key="$(cat pos-deploy.pub)" --admin-key="<your key>"
   ```
8. Check `ssh deploy@<staging vps>` and `sudo` work, then run it again with `--disable-root-login`.

**D. The first release and the client**

9. GitHub → Settings → Environments → `staging`: `DEPLOY_HOST` = the staging VPS, `DEPLOY_CLIENTS` = `clinic-staging`, secrets `DEPLOY_SSH_KEY` (private `pos-deploy`) and `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan -t ed25519 <staging vps>`).
10. Tag `v1.1.0-rc.1` and push it. The tests and the package run; the deploy to staging fails, as the client does not exist yet. Download `pos-v1.1.0-rc.1.tar.gz` from the release to `/srv/pos/incoming/` on the VPS.
11. ```bash
    sudo bash scripts/add-client.sh --client=clinic-staging --domain=staging.<domain> --env=staging \
         --profile=pharmacy --app-name="Clinic POS (staging)" --recovery-recipient=age1… \
         --backup-remote=<remote>:<bucket>/clinic-staging --rclone-config=/root/clinic-rclone.conf \
         --alert-email=you@… --release=/srv/pos/incoming/pos-v1.1.0-rc.1.tar.gz
    ```
    Answer `pos:install` (the shop, the owner: a password of 12+ characters). Put `APP_KEY` and the database password from `/srv/pos/clinic-staging/shared/.env` in the password manager.
12. Open `https://staging.<domain>`, sign in as the owner, set up two-factor authentication (with the recovery codes printed), open **System health**: all OK (mail: "Not set up" until SMTP).
13. An uptime monitor on `https://staging.<domain>/up`.

**E. Backup and restore**

14. As `pos-clinic-staging` (`sudo -u pos-clinic-staging php /srv/pos/clinic-staging/current/artisan …`): `pos:backup`, `pos:backup:list`, `pos:backup:verify`. The bucket holds the `.age` file and its `.json`.
15. Rename a product, then `pos:backup:restore --confirm=pos_clinic_staging`: the name is back.
16. The new-server restore with the paper recovery key ([Restore](#restore), "On a new server"), on the same VPS: shows the key and the steps work. Delete the key from the server afterwards.

**F. A working till**

17. A cashier user (10+ characters) with allowed location General Pharmacy; Shop settings: **Sell only with an open shift** on and cashier discount 0 (the pharmacy profile's defaults); Administration → Security: admin 30, till 0.
18. A test PC per [TILLS.md](TILLS.md): printer at 80 mm without margins and the drawer through its driver, scanner with Enter, owner signs in, chooses T1, **Set up offline sales**, installs the app, sets the print settings once, kiosk start-up shortcut, restart.
19. The cashier signs in: no Discount button, no Stock requests link at T1, the till asks to open a shift. Open it, sell, print. Then the offline check ([TILLS.md, Check offline billing](TILLS.md#5-check-offline-billing)): System health shows 0 waiting afterwards.
20. A T2 till at Doctor's Medicines (OPD bills on): an OPD bill, and **Stock requests** to General Pharmacy, sent from the admin panel.

**G. An update and a rollback**

21. A small change, its CHANGELOG line, tag `v1.1.0-rc.2`: it deploys to staging by itself; System health shows the new version.
22. `sudo -u pos-clinic-staging /srv/pos/clinic-staging/bin/rollback.sh --dry-run`, then for real: the version goes back. Tag `v1.1.0-rc.3` to roll forward again. To practise `rollback.sh --restore`, use a release with a migration.

**H. The go-live reset**

23. Imports (items, suppliers), test sales on both tills, a return and a void (owner), a GRN and a transfer; each till online with nothing waiting.
24. `sudo -u pos-clinic-staging php /srv/pos/clinic-staging/current/artisan pos:go-live-reset --include-stock`: the owner's email and password, the shop code, `RESET`.
25. On each till: clear Chrome's site data, sign in as the owner, **Set up offline sales** again. Import the opening stock and supplier invoices, open the shifts, first sale: `T1-000001`.
26. Walk through [GO-LIVE.md](GO-LIVE.md)'s day-before list and the [handover checklist](../handover/handover-checklist.md) on staging.

Then production: a production VPS (2 vCPU / 4 GB), the `production` environment in GitHub with required reviewers, `add-client.sh --client=clinic --env=production …`, and the first non-pre-release tag, approved in GitHub.
