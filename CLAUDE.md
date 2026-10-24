# POS & inventory system

Laravel 13 point-of-sale and stock system for small shops: Filament admin at `/admin`, Vue 3 + Inertia till at `/pos` (works offline), one install and database per client. The first client is a retail moto-gear shop; a pharmacy module for a clinic is being built on top.

Read before changing anything:

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): stack, folder map, **the ten rules**, main flows, modules, install profiles.
- [docs/SCOPE.md](docs/SCOPE.md): what Phase 1 (retail) includes.
- [docs/PHARMACY.md](docs/PHARMACY.md): pharmacy plan. **Its Decisions section wins** over anything older in the doc. §9 is the branch plan.
- [docs/deploy/CLOUD.md](docs/deploy/CLOUD.md): how clients run in the cloud (servers, deploys, backups, monitoring, security). Never run `scripts/provision-server.sh` or `scripts/add-client.sh` on a development machine.

## Standing rules

- With `pharmacy` off, the retail POS behaves exactly as before, and every existing test still passes.
- Every new table has `shop_id` and its model uses `BelongsToShop`.
- Stock changes only through `StockService`. Batch-tracked items (medicines) go through `issue()` / `receive()` / `restore()`; `record()` refuses them.
- Money is integer minor units (`App\Support\Money`), never floats. The one approved exception is pharmacy cost per base unit, DECIMAL(20,4) (PHARMACY.md, Q9).
- New permissions go in `config/pos.php`.
- Optional features are Pennant modules (`config('pos.modules')`). Read them with `$shop->hasModule()`, change them only through `ModuleService`. `POS_PROFILE` is install-time only; runtime code never reads it.
- Checkout maths exists twice: `CheckoutService::calculate()` and its offline mirror `resources/js/lib/offline/pricing.ts` (no JS tests). Change both together.
- The thermal receipt layout exists twice: `App\Services\Receipts\ReceiptLayout::sale()` (+ `EscPos`) and its offline mirror `resources/js/lib/receipt/layout.ts` (+ `escpos.ts`). Change both together; `ReceiptLayoutParityTest` runs the till's code under node and compares.
- Feature tests for every rule, in `tests/Feature/Pos` (`PosTestCase` = retail demo shop, `PharmacyTestCase` = demo clinic, `BatchTestCase` = demo clinic with the clock fixed). Locking and races go in the MySQL group (below). CI also runs the whole suite on MySQL, whose JSON columns don't keep key order: compare a JSON object read back from the database (audit values, settings) with `assertSameIgnoringKeyOrder()`, not `assertSame()`.
- Update the docs (PHARMACY.md, and ARCHITECTURE.md when the core changes) in the same branch.
- Before calling any till layout work done (dialogs, panels, anything under `resources/js/components/pos` or `pages/pos`), run `composer test:e2e` and say what it reported. Feature tests don't render the page, so they can't catch content spilling out of a dialog.
- Before calling a branch done, run the **full suite on MySQL** (`composer test:mysql-full`, as CI's `mysql` job does) as well as `composer ci:check` and `composer test:mysql`. SQLite alone misses MySQL-only failures (JSON key order, DECIMAL, dates).
- Small commits. Don't merge.

## Commands

```bash
php artisan test --compact          # full suite (pinned to POS_PROFILE=retail in phpunit.xml)
vendor/bin/pint --parallel          # PHP style (CI: pint --parallel --test)
vendor/bin/phpstan analyse          # static analysis, level 7
npm run check                       # frontend + Markdown formatting/lint (vp check; --fix to apply)
npm run types:check                 # vue-tsc
composer ci:check                   # what CI runs: all of the above
npm run build                       # page-render tests need public/build (Vite manifest)
composer test:mysql                 # the MySQL group: row locks, till races, FEFO on MySQL
composer test:mysql-full            # the WHOLE suite on MySQL (CI's mysql job), against .env.testing.mysql
composer test:e2e                   # till layout in real browsers (Playwright), opt-in, about 2 minutes
php artisan pos:health              # backups, disk, queue, scheduler (System health on the command line)
```

The backup tests need the `age` binary (`sudo apt install age`); without it they are skipped locally, and CI (`POS_BACKUP_TESTS_REQUIRED=1`) fails. `Mysql/BackupMysqlTest` also needs the test user to have `pos_pharmacy_test_verify` (docs/SETUP-GUIDE.md, A5b).

**Till end-to-end test.** `tests/e2e/till-layout.spec.ts` walks one sale through the demo clinic in Chromium and Firefox at 1280, 1440 and 1920 px wide. It opens every till dialog and panel (OPD Medicines amount, batch pick, Discount, Prescription, Pay, Sale complete, Recent sales, Close shift and the printed shift summary, Stock requests) and fails if any element sits outside its box or anything scrolls sideways. `tests/e2e/offline-sale.spec.ts` cuts a till's connection, sells offline and checks the sale reaches the server on reconnect. The server sends the production Content-Security-Policy. It is not part of `ci:check`. Setup, once: `npm install`, then `npx playwright install chromium firefox` (add `--with-deps` on a machine without the browsers' system libraries). `composer test:e2e` builds the assets and runs Playwright. Playwright starts its own server on port 8010 (`E2E_PORT`) through `scripts/e2e-server.sh`, which seeds a fresh demo clinic into `database/e2e.sqlite`, and stops it when the run ends. It never touches `.env`'s database or the servers on 8000 and 8001. The script refuses to run while the config is cached. For a subset: `npx playwright test --project=firefox -g 1280` (after `npm run build`). Failures leave a screenshot and a trace in `storage/e2e/results`; open them with `npx playwright show-report storage/e2e/report`.

**MySQL test group.** SQLite ignores row locks, so `tests/Feature/Pos/Mysql` (`#[Group('mysql')]`, `MysqlTestCase`) runs on a real database. It is excluded from the default run; CI runs it in its own job (`mysql-locking`, MySQL 8.4 service). Setup, once: `cp .env.testing.mysql.example .env.testing.mysql` (gitignored) and fill in `DB_USERNAME` / `DB_PASSWORD` for `pos_pharmacy_test`. The tests truncate that database, refuse any database whose name does not end in `_test`, skip themselves when the file is missing (CI sets `POS_MYSQL_TESTS_REQUIRED=1`, which makes that a failure), and fork workers to race tills (needs the `pcntl` and `posix` extensions). Run them after changing `StockService`, FEFO or anything that locks.

**Don't start long-running servers** (`composer run dev`, `php artisan serve`, `npm run dev`): the developer runs them. The retail app is on port 8000, the pharmacy app on 8001 (`SERVER_PORT` in each `.env`).

## Worktrees

Two checkouts of the same repo, each with its own `.env` and database. Never point both at the same database.

| Directory                 | Branch                    | `POS_PROFILE` | Database           | Port |
| ------------------------- | ------------------------- | ------------- | ------------------ | ---- |
| `~/Projects/pos-system`   | `main` (retail)           | `retail`      | `pos_system`       | 8000 |
| `~/Projects/pos-pharmacy` | pharmacy feature branches | `pharmacy`    | `pos_pharmacy_dev` | 8001 |

`php artisan migrate:fresh --seed` seeds the profile's demo shop: retail gets the moto-gear shop (`owner@pos.test`), pharmacy gets the demo clinic (`owner@clinic.test`). Passwords are `password`.
