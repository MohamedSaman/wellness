# POS System: Setup Guide (Ubuntu)

From your Ubuntu PC to a running, tested POS project on GitHub. Do the steps in order. Part A is once per computer; Parts B to D are once per project.

**What you end up with:** Laravel 13 + Vue (Inertia) starter kit, MySQL, Filament admin panel at `/admin`, Owner and Cashier roles, the Phase 1 database (products, variants, stock ledger, sales, returns, shifts), demo data and a passing test suite, all in a private GitHub repository.

You already have VS Code and GitHub connected, so those steps are skipped.

---

## Part A: Install the tools (once per computer)

These steps are written for **Ubuntu 26.04 LTS**. Check your version with `lsb_release -rs`.

### A1. PHP and the extensions Laravel needs

Laravel 13 supports **PHP 8.3 to 8.5**. Ubuntu 26.04 ships **PHP 8.5**, so install it straight from Ubuntu's own repositories:

```bash
sudo apt update
sudo apt install -y php-cli php-mysql php-sqlite3 php-mbstring php-xml php-curl \
  php-zip php-bcmath php-intl php-gd php-redis unzip git curl
```

The unversioned `php-*` names always install Ubuntu's default PHP (8.5 on 26.04), so the extensions match the PHP version.

Check:

```bash
php -v                 # PHP 8.5.x
php -m | grep -E 'pdo_mysql|intl|pdo_sqlite'
```

If `composer require` ever reports that a package doesn't support PHP 8.5 yet, add a second PHP version (8.4) from the [Ondřej Surý PHP repository](https://launchpad.net/~ondrej/+archive/ubuntu/php) and switch with `sudo update-alternatives --config php`. Most packages support 8.5, so you will probably never need this.

### A2. Composer

```bash
cd ~
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer -V
```

### A3. Laravel installer

```bash
composer global require laravel/installer
echo 'export PATH="$HOME/.config/composer/vendor/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
laravel --version
```

### A4. Node.js LTS (via nvm, no sudo needed)

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
source ~/.bashrc
nvm install --lts
node -v && npm -v      # Node 20 or higher
```

### A5. MySQL 8

```bash
sudo apt install -y mysql-server
sudo systemctl enable --now mysql
sudo mysql_secure_installation      # answer Yes to the security questions
```

For a database GUI, install **DBeaver** (`sudo snap install dbeaver-ce`) or **MySQL Workbench**.

### A5b. age (backup encryption)

The backup tests encrypt and decrypt real backups with [age](https://age-encryption.org); without it they are skipped (CI requires them):

```bash
sudo apt install -y age
```

The MySQL backup test (`composer test:mysql`) also restores into a scratch database next to the test database; give the test user that one too:

```sql
GRANT ALL PRIVILEGES ON pos_pharmacy_test_verify.* TO 'pos_user'@'localhost';
```

### A6. VS Code extensions

You have VS Code already. Add these if missing (Ctrl+Shift+X):

| Extension                      | Why                                 |
| ------------------------------ | ----------------------------------- |
| PHP Intelephense               | PHP autocomplete and errors         |
| Laravel (official, by Laravel) | Routes, views, config autocomplete  |
| Vue - Official                 | Vue 3 + TypeScript support          |
| Tailwind CSS IntelliSense      | CSS class autocomplete              |
| ESLint and Prettier            | Front-end linting and formatting    |
| EditorConfig for VS Code       | Same indentation for everyone       |
| GitLens                        | See who changed which line, and why |

Turn on **Format On Save** in settings (Ctrl+,).

Check git knows who you are (this is used on every commit):

```bash
git config --global user.name      # should print your name
git config --global user.email     # should print your GitHub email
git config --global init.defaultBranch main
```

---

## Part B: Create the Laravel project

### B1. Create the app

```bash
mkdir -p ~/Projects && cd ~/Projects
laravel new pos-system
```

Answer the installer's questions as follows:

- **Starter kit:** Vue
- **Authentication:** Laravel's built-in authentication
- **Testing framework:** PHPUnit (the POS tests are PHPUnit classes; Pest can run them too)
- **Run npm install and build:** Yes
- **Initialize a Git repository:** Yes

```bash
cd pos-system
code .
```

### B2. Create the database and a dedicated database user

Don't let the app use the MySQL root account:

```bash
sudo mysql
```

```sql
CREATE DATABASE pos_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pos_user'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
GRANT ALL PRIVILEGES ON pos_system.* TO 'pos_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### B3. Point `.env` at MySQL

Open `.env` in VS Code and set:

```dotenv
APP_NAME="POS System"
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pos_system
DB_USERNAME=pos_user
DB_PASSWORD=choose-a-strong-password
```

Leave `APP_TIMEZONE` as UTC. Each shop stores its own timezone, and dates are shown in it.

`.env` holds secrets and is already in `.gitignore`. **Never commit it.**

### B4. Commit the clean starter kit

Committing the untouched starter kit first gives you a clean baseline to compare against:

```bash
git add .
git commit -m "Laravel 13 Vue starter kit"
```

---

## Part C: Add the POS foundation

### C1. Copy in the POS files

```bash
unzip -o ~/Downloads/pos-system-starter.zip -d ~/Projects/pos-system
```

`-o` replaces `database/seeders/DatabaseSeeder.php` and `README.md` with the POS versions. You should now have, among others:

```
app/Services/CheckoutService.php
config/pos.php
scripts/setup.sh
tools/patch-project.php
tests/Feature/Pos/
```

### C2. Run the setup script

```bash
bash scripts/setup.sh
```

The script:

1. checks the PHP extensions are installed,
2. installs **Filament 5**, **spatie/laravel-permission** and **Laravel Pennant**,
3. publishes their config files and migrations,
4. installs the Filament admin panel (press **Enter** to accept the `admin` panel ID; answer "no" if it asks to create a user),
5. runs `tools/patch-project.php`, which registers `PosServiceProvider` and adds roles and admin access to the `User` model,
6. creates all tables and seeds the demo shop,
7. runs the full test suite. **Every test should pass.**

### C3. Start it and log in

```bash
composer run dev
```

- App: http://127.0.0.1:8000
- Admin panel: http://127.0.0.1:8000/admin
- Owner: `owner@pos.test` / `password`
- Cashier: `cashier@pos.test` / `password`

The admin panel is empty for now. Product, stock and sales screens are the next build step. Check the data in DBeaver: `products`, `product_variants`, `stock_movements` and `stock_levels` should all have rows.

### C4. Try the tools

```bash
php artisan pos:module list                         # all optional modules: off
php artisan pos:module enable purchasing --shop=DEMO
php artisan pos:module disable purchasing --shop=DEMO
php artisan pos:stock-rebuild                       # ledger integrity check
php artisan test --filter=Pos                       # only the POS tests
```

### C5. Commit

```bash
git add .
git commit -m "Add POS foundation: tenancy, catalog, stock ledger, checkout, roles"
```

---

## Part D: Put it on GitHub (private)

Since VS Code is already connected to your GitHub:

1. Open the **Source Control** panel (Ctrl+Shift+G).
2. Click **Publish Branch**.
3. Choose **Publish to GitHub private repository**.

Alternatively, with the GitHub CLI (`sudo apt install gh`, then `gh auth login`):

```bash
gh repo create pos-system --private --source . --remote origin --push
```

Always use **private**.

Before pushing, confirm `.env` is ignored:

```bash
git check-ignore .env      # must print ".env"
```

### Protect the main branch

On GitHub, go to **Settings → Branches → Add rule** for `main`:

- Require a pull request before merging
- Require status checks to pass (the starter kit's GitHub Actions run the tests)
- Block force pushes

---

## Part E: Daily workflow

```bash
git switch main
git pull
git switch -c feature/product-screens      # one branch per feature

composer run dev                            # app + Vite + queue + logs
php artisan test                            # before every commit
vendor/bin/pint                             # fix code style

git add .
git commit -m "Add product and variant admin screens"
git push -u origin feature/product-screens  # then open a pull request on GitHub
```

Scheduled jobs (the pharmacy's daily expiry digest) run from Laravel's scheduler; `composer run dev` does not start it. Run `php artisan schedule:work` in another terminal to try them, or send the digest now with `php artisan pos:expiry-digest --now`.

Reset the local database to fresh demo data at any time:

```bash
php artisan migrate:fresh --seed
```

---

## Part F: Security checklist

**Already built in:**

- Login rate limiting (per email and address, and per address), two-factor authentication (required for the owner in production) and passkeys (Fortify, from the starter kit); the admin panel signs in through the same login
- Idle sign-out of the admin panel (the till's is off by default), an optional admin IP allowlist, audit log retention (Administration → Security)
- Passwords in production: 12+ characters for admin users, 10+ for till-only users, never one known from a breach
- Owner and Cashier roles with fine-grained permissions (`config/pos.php`)
- Shop data isolation: every query is filtered to the user's shop, and `shop_id` can't be set from user input
- Prices always come from the server; tills can't send their own prices
- Cashier discount limit (10% by default, set per shop)
- Money stored as whole numbers (cents), never floats
- Immutable stock ledger and audit log; sales can't be deleted, only voided or returned
- Disabled users are logged out on their next request
- Security headers on every response, and a Content-Security-Policy in production
- Encrypted, verified, off-site backups and health alerts (`pos:backup`, System health)
- Strict mode in development, which catches N+1 queries and mass-assignment mistakes early

**Before the first client goes live:** follow [docs/deploy/CLOUD.md](deploy/CLOUD.md) (the server, the client, backups, monitoring) and [docs/deploy/GO-LIVE.md](deploy/GO-LIVE.md). `scripts/add-client.sh` does what used to be this checklist: production `.env` without debug and with a fresh `APP_KEY`, HTTPS, a dedicated MySQL user, the scheduler and queue worker as systemd units, nightly off-site backups, and `pos:install` (never the demo seeder in production). Public registration is already off (no `Features::registration()` in `config/fortify.php`): owners create staff accounts.

---

## Troubleshooting

| Problem                                                                                    | Fix                                                                                               |
| ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------- |
| `laravel: command not found`                                                               | Composer's bin folder isn't on your PATH. Redo step A3, then open a new terminal                  |
| `could not find driver`                                                                    | `sudo apt install php-mysql php-sqlite3`                                                          |
| `SQLSTATE[HY000] [1045] Access denied`                                                     | Check `DB_USERNAME` and `DB_PASSWORD` in `.env`, then run `php artisan config:clear`              |
| `Class "Spatie\Permission\..." not found`                                                  | `composer install`, then `composer dump-autoload`                                                 |
| `Your requirements could not be resolved ... ext-intl`                                     | `sudo apt install php-intl`                                                                       |
| Permission errors on `storage/` or `bootstrap/cache`                                       | `chmod -R u+w storage bootstrap/cache` (don't use `sudo` inside the project)                      |
| Port 8000 already in use                                                                   | `php artisan serve --port=8080`, or stop the other process                                        |
| Patch script reports a manual step                                                         | Open `app/Models/User.php` and compare with the instructions it printed                           |
| A starter-kit test fails with `LazyLoadingViolationException` or `MassAssignmentException` | That's strict mode catching a real issue. Fix the query or field list, or share the error with me |
