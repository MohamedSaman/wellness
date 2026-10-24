# POS & Inventory System

A Laravel 13 point-of-sale and inventory system for retail shops, starting with a helmet, riding-gear and vehicle spare parts shop. It is built to serve more shops and business types later.

- **Setup from scratch (Ubuntu, VS Code, GitHub):** [docs/SETUP-GUIDE.md](docs/SETUP-GUIDE.md)
- **Architecture and coding rules:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **Phase 1 scope and status:** [docs/SCOPE.md](docs/SCOPE.md)
- **Cloud deployment, tills, go-live:** [docs/deploy/CLOUD.md](docs/deploy/CLOUD.md), [TILLS.md](docs/deploy/TILLS.md), [GO-LIVE.md](docs/deploy/GO-LIVE.md); staff guides in [docs/handover](docs/handover/README.md)

## Quick start (after the setup guide)

```bash
composer run dev               # http://127.0.0.1:8000  |  /admin
php artisan test               # full test suite
php artisan migrate:fresh --seed
```

Demo logins (local only): `owner@pos.test` and `cashier@pos.test`, password `password`.

## Commands

| Command                                                                     | What it does                                                        |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `php artisan pos:install`                                                   | Onboard a new client shop with its owner, first till and price list |
| `php artisan pos:module list\|enable\|disable <module> --shop=<id or code>` | Switch optional modules on or off per shop                          |
| `php artisan pos:stock-rebuild`                                             | Recalculate stock levels from the ledger (integrity check)          |
| `php artisan pos:backup` / `pos:backup:verify` / `pos:backup:restore`       | Encrypted off-site backup, weekly restore check, restore            |
| `php artisan pos:health [--alert]`                                          | Backups, disk, queue, scheduler; mail the owners when one fails     |
| `php artisan pos:go-live-reset [--include-stock]`                           | Remove the rehearsal's transactions before go-live (owner confirms) |

© Webxkey. Private and confidential.
