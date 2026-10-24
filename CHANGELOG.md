# Changelog

Every release is a semantic version tag (`v1.2.0`; a pre-release `v1.2.0-rc.1` goes to staging only). Pushing the tag runs the release workflow: tests, the package on GitHub Releases with this file's section as its notes, staging, then production after approval ([docs/deploy/CLOUD.md](docs/deploy/CLOUD.md#releases)). Before tagging, rename **Unreleased** to the version and date.

- **Major** (2.0.0): a change the clinic must prepare for (a migration that cannot be rolled back, a new server requirement).
- **Minor** (1.2.0): new features; migrations that roll back.
- **Patch** (1.1.1): fixes only, no migrations.

## [Unreleased]

### Added

- Cloud deployment ([docs/deploy/CLOUD.md](docs/deploy/CLOUD.md)): `scripts/provision-server.sh` (Ubuntu 26.04: PHP 8.5-FPM, MySQL 8.4 on localhost, Caddy with Let's Encrypt, deploy user with SSH keys only, firewall, fail2ban, security updates, swap, time sync), `scripts/add-client.sh` (one Linux user, database, `.env`, PHP pool, Caddy site, queue worker and scheduler per client and environment), `scripts/deploy.sh` and `scripts/rollback.sh` (backup first, release folders, one-step switch, rollback of migrations or restore), `scripts/package-release.sh` and the release workflow.
- Backups: `pos:backup` (nightly, database and files, encrypted with age, uploaded with rclone, 14 daily and 12 monthly kept), `pos:backup:verify` (weekly restore into a scratch database), `pos:backup:restore`, `pos:backup:list`.
- Monitoring: Administration → System health, dashboard backup tiles, `pos:health --alert` (mail and bell), `/up` checks the database; tills report waiting offline sales and their clock.
- Security: two-factor authentication required for the owner in production, passwords by role (12+ characters for admin users, 10+ for till-only users, never one known from a breach), idle sign-out of the admin panel (the till's is available, off by default), Content-Security-Policy, admin IP allowlist, audit log retention (Administration → Security), a login cap per address; `pos:admin-ip`, `pos:audit-prune`.
- `pos:go-live-reset`: removes the rehearsal's transactions before go-live ([docs/deploy/GO-LIVE.md](docs/deploy/GO-LIVE.md)).
- Handover pack: user guides, quick references and the handover checklist ([docs/handover](docs/handover/README.md)).

### Changed

- The admin panel signs in through `/login` (Fortify), so the owner's two-factor code is always asked; `/admin/login` is gone.
- A till registered again for offline sales drops the offline invoice numbers it had reserved.
- The pharmacy profile sells only with an open shift (Shop settings → Till, **Sell only with an open shift**).
- No Discount button, line discount or F4 for a cashier whose discount limit is 0.
- The till's **Stock requests** link only at a till away from the default location (the OPD's, not General Pharmacy's).
- A receipt window the till opened closes itself once printed (kiosk printing).

## [1.0-retail] - 2026-10-15

The first client's retail till and back office (moto-gear shop), before the pharmacy branches (docs/PHARMACY.md §9) and this changelog.
