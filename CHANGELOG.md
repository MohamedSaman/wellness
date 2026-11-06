# Changelog

Every release is a semantic version tag (`v1.2.0`; a pre-release `v1.2.0-rc.1` goes to staging only). Pushing the tag runs the release workflow: tests, the package on GitHub Releases with this file's section as its notes, staging, then production after approval ([docs/deploy/CLOUD.md](docs/deploy/CLOUD.md#releases)). Before tagging, rename **Unreleased** to the version and date.

- **Major** (2.0.0): a change the clinic must prepare for (a migration that cannot be rolled back, a new server requirement).
- **Minor** (1.2.0): new features; migrations that roll back.
- **Patch** (1.1.1): fixes only, no migrations.

## [Unreleased]

### Added

- **Duplicate product**, from the Edit page and the products list: opens Create pre-filled, without ids, SKU, barcodes, costs, opening stock or batch. A medicine's copy also leaves out the name, description and NMRA number.
- Product form: Enter in a variant or pack Barcode field moves to the next field, so a scanner no longer saves the form.
- Create new item, on order and GRN lines: Enter in a Barcode field also moves to the next field, so a scanner no longer creates the item.
- **Same medicine check**: the product form refuses a new medicine that repeats one in the catalog (generic, brand name, strength, unit, dosage form and manufacturer), with a link to it.
- Cloud deployment ([docs/deploy/CLOUD.md](docs/deploy/CLOUD.md)): `scripts/provision-server.sh` (Ubuntu 26.04: PHP 8.5-FPM, MySQL 8.4 on localhost, Caddy with Let's Encrypt, deploy user with SSH keys only, firewall, fail2ban, security updates, swap, time sync), `scripts/add-client.sh` (one Linux user, database, `.env`, PHP pool, Caddy site, queue worker and scheduler per client and environment), `scripts/deploy.sh` and `scripts/rollback.sh` (backup first, release folders, one-step switch, rollback of migrations or restore), `scripts/package-release.sh` and the release workflow.
- Backups: `pos:backup` (nightly, database and files, encrypted with age, uploaded with rclone, 14 daily and 12 monthly kept), `pos:backup:verify` (weekly restore into a scratch database), `pos:backup:restore`, `pos:backup:list`.
- Monitoring: Administration → System health, dashboard backup tiles, `pos:health --alert` (mail and bell), `/up` checks the database; tills report waiting offline sales and their clock.
- Security: two-factor authentication required for the owner in production, passwords by role (12+ characters for admin users, 10+ for till-only users, never one known from a breach), idle sign-out of the admin panel (the till's is available, off by default), Content-Security-Policy, admin IP allowlist, audit log retention (Administration → Security), a login cap per address; `pos:admin-ip`, `pos:audit-prune`.
- `pos:go-live-reset`: removes the rehearsal's transactions before go-live ([docs/deploy/GO-LIVE.md](docs/deploy/GO-LIVE.md)).
- Handover pack: user guides, quick references and the handover checklist ([docs/handover](docs/handover/README.md)).
- Offline ready by default ([TILLS.md](docs/deploy/TILLS.md#offline-ready-by-default)): a till the owner approved takes its sync token, downloads the catalog and reserves invoice numbers by itself, with no button, and keeps them fresh. The top bar says **Ready for offline**, **Preparing offline...**, **Not ready for offline - reconnect** or **Offline not available on this till - ask the owner**. A till never approved, or revoked, does not prepare itself.
- Administration → Tills: **Offline sales allowed**, and **Reset offline on this till** for a till whose Chrome was reset or whose PC was replaced (audited).
- Liberation Mono (unmodified, SIL Open Font License) bundled for receipts, licence in `resources/fonts/liberation-mono/LICENSE.txt`.
- First print test on the real printer ([TILLS.md](docs/deploy/TILLS.md#first-print-test-on-the-real-printer)), with the XP-80C fallback.

### Changed

- Product form: full page width in a responsive grid; Medicine, Selling & stock and More details collapse to one line (collapsed on edit); each variant is a compact row with the rarely used fields under **More**; help text behind an info icon; per-location **Sell loose** on one line; packs as compact rows; opening batch and location show only with opening stock; sticky footer with **Save & add another** (create only), an error count and a jump to the first error. No field, rule or saved data changed. Saved variants and packs no longer show a delete button: it never removed them. The variant drag handle is gone: nothing stored the order.

- The admin panel signs in through `/login` (Fortify), so the owner's two-factor code is always asked; `/admin/login` is gone.
- A till registered again for offline sales drops the offline invoice numbers it had reserved.
- The pharmacy profile sells only with an open shift (Shop settings → Till, **Sell only with an open shift**).
- No Discount button, line discount or F4 for a cashier whose discount limit is 0.
- The till's **Stock requests** link only at a till away from the default location (the OPD's, not General Pharmacy's).
- A receipt window the till opened closes itself once printed (kiosk printing).
- A pharmacy's receipts, reprints and refund and void slips print larger and solid black for thermal printers: 12.6 px at 36 columns on 80 mm inside the 72 mm the head reaches, totals bold and double height, a black and white logo. ESC/POS (QZ Tray) stays at the printer's 48 columns. Retail's receipts are unchanged.
- **Sync now** is **Refresh now**: the till refreshes by itself, this is the fallback.
- **Revoke offline access** also takes the till's approval away, and so does the go-live reset. A till the server turned down stops selling offline; the sales it holds stay.

### Fixed

- A pharmacy's offline receipt prints exactly as the online one: only the receipt, on 80 mm paper, with the logo, and without Chrome's page title and address. The page length is measured, because Chrome ignores `size: 80mm auto`.

### Release and deploy notes

This is a **minor** release: it has a migration, `2026_11_05_000001_add_offline_allowed_to_devices`, which adds `devices.offline_allowed` and rolls back. The migration approves every till that already holds a sync token, so those keep working with nothing to do; any other till stays off until the owner approves it.

1. **Back up first:** `pos:backup`, and check it finished ([docs/deploy/CLOUD.md](docs/deploy/CLOUD.md)).
2. Deploy.
3. On each till, reload and check the top bar shows **Ready for offline**. If it says **Offline not available on this till - ask the owner**, use Administration → Tills: **Offline sales allowed** for a till never approved, **Reset offline on this till** for one whose Chrome no longer has its registration.
4. Run the [first print test on the real printer](docs/deploy/TILLS.md#first-print-test-on-the-real-printer) on each till. If Chrome ignores the measured page length with the XP-80C driver, set the driver's paper to **80(72.1) x 297 mm**.

## [1.0-retail] - 2026-10-15

The first client's retail till and back office (moto-gear shop), before the pharmacy branches (docs/PHARMACY.md §9) and this changelog.
