<?php

use App\Models\Device;
use App\Models\DrugDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierAllocation;
use App\Models\SupplierOpeningBalance;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\VariantPrice;
use App\Models\VariantUnit;
use App\Models\VariantUnitLocation;
use Database\Seeders\DemoClinicSeeder;
use Database\Seeders\DemoShopSeeder;
use Database\Seeders\VehicleSeeder;

/*
|--------------------------------------------------------------------------
| POS & Inventory configuration
|--------------------------------------------------------------------------
|
| Everything that differs between client shops lives in the shop's own
| settings (shops.settings JSON). The values below are the defaults a new
| shop starts with, plus the role/permission and module definitions.
|
*/

return [

    /*
    | Defaults merged under every shop's own settings.
    | Read them with $shop->setting('key').
    */
    'shop_defaults' => [
        'allow_negative_stock' => false,      // online checkout blocks selling stock you don't have
        'max_cashier_discount_percent' => 10, // cashiers cannot discount more than this per sale
        'require_open_shift' => false,        // turn on once the shift screen is built
        'invoice_padding' => 6,               // T1-000001
        // Batch-tracked items (pharmacy). FEFO never sells a batch expiring within this
        // many days of the sale (0 = up to and including its expiry day); a medicine's own
        // min_days_to_expiry overrides it (docs/PHARMACY.md §6.2, Q12).
        'min_days_to_expiry_for_sale' => 0,
        // Receiving a batch that expires within this many days needs batches.manage
        // and an explicit "accept" (docs/PHARMACY.md §11: about 6 months; the
        // pharmacy profile starts shops at 90, the clinic's answer of 2026-10-20).
        'near_expiry_warning_days' => 180,
        // Purchasing with several locations: new orders and goods received notes start
        // at the shop's default location (central receiving) instead of the user's own.
        'receive_at_default_location' => false,
        // Pharmacy checkout (docs/PHARMACY.md §4.10, §9 branch 8). A prescription-only or
        // controlled line needs a prescriber's name ('required') or the till asks for one
        // but may sell without it ('prompt').
        'rx_prescriber' => 'prompt',
        // The clinic doctor's name, filled in as the prescriber at the till.
        'default_prescriber' => null,
        // Controlled-drug register (branch 11): when on, a controlled line needs the
        // patient's name online and is blocked offline on the till.
        'controlled_register' => false,
        // The Consultation product's variant id: the fee line an OPD bill starts with.
        'opd_consultation_variant_id' => null,
        // What an OPD receipt prints for the medicines: their 'names' (no prices) or
        // only the Medicines amount ('totals').
        'opd_receipt_medicines' => 'names',
        // OPD bills (decided 2026-10-22): the till shows the medicines' list value next to the
        // Medicines amount and warns, without blocking, when the amount is below this share
        // of it (%; 0 = no warning). The sale records that it was (sales.opd_below_list).
        'opd_below_list_warning_percent' => 100,
        // A bank transfer payment needs its reference.
        'bank_transfer_reference_required' => false,
        // An offline till whose clock is further out than this (minutes, either way)
        // has its sales flagged, and reports use the server's time (§7.5).
        'till_clock_tolerance_minutes' => 10,
        // Receipts (docs/PHARMACY.md §9, branch 9). Paper width in mm: 80 (48 characters
        // a line) or 58 (32). What a pharmacy receipt prints under each medicine: its
        // batch number, its expiry date, and the prescriber on prescription lines. Read
        // when printing, so a reprint follows today's choice; the sale keeps them all.
        // The pharmacy profile hides the expiry and shows the prescriber (2026-10-21).
        'receipt_paper_width' => 80,
        'receipt_show_batch' => true,
        'receipt_show_expiry' => true,
        'receipt_show_prescriber' => false,
        // Branding (Shop settings → Branding): the brand colour (#rrggbb) is the admin
        // panel's primary colour and the till's accent, as a shade scale kept readable
        // (App\Support\BrandPalette); null keeps the panel's amber and the till's own
        // colours. The favicon is a file on the public disk. The logo is shops.logo_path.
        'brand_color' => null,
        'favicon_path' => null,
        // The colour theme (Settings → Appearance, owner only; App\Support\ThemePresets):
        // classic, clinic_teal, ocean_blue, plum or slate. Null: Clinic Teal for a pharmacy
        // shop, classic (the look from before themes) for any other. Choosing one clears
        // brand_color, which otherwise still overrides the theme's primary colour.
        'theme_preset' => null,
        // Security (Administration → Security, docs/deploy/CLOUD.md). A signed-in user who
        // does nothing for this many minutes is signed out: in the admin panel and the rest
        // of the site, and at the till (/pos). 0 = never. A till that is offline stays
        // signed in until it is back online, so billing never stops during an outage.
        'admin_idle_minutes' => 30,
        // Off at the till (decided 2026-10-22): a till signed out during an internet outage
        // cannot sign in again until it is back, and billing must never stop. A PIN lock
        // that works offline is the future answer (docs/PHARMACY.md, branch 12).
        'till_idle_minutes' => 0,
        // Only these IP addresses or ranges (203.0.113.7, 203.0.113.0/24) may open the
        // admin panel; empty = anywhere. The till is never limited (it moves with the
        // clinic's internet connection). pos:admin-ip --clear empties it from the server.
        'admin_ip_allowlist' => [],
        // pos:audit-prune deletes audit log entries older than this many days (0 = keep
        // them all). Cost revaluations are always kept: pos:stock-rebuild --costs reads them.
        'audit_log_retention_days' => 0,
    ],

    /*
    | Quick-cash buttons on the payment screen, in whole currency units, by
    | currency code. 'default' is used for any currency not listed.
    */
    'quick_cash' => [
        'LKR' => [100, 500, 1000, 2000, 5000],
        'default' => [5, 10, 20, 50, 100],
    ],

    /*
    | Offline tills (offline_sync module). Each till reserves a block of
    | invoice numbers while online and numbers its offline sales from it.
    */
    'offline' => [
        'invoice_block_size' => 200,
    ],

    /*
    | Pharmacy module. Suggestions only: the medicine form (and later the
    | import) fills these in when a dosage form is picked on a new product,
    | and every value stays editable per product (docs/PHARMACY.md, Unit
    | defaults). Nothing else reads them, so changing them is a config change.
    |
    |   base_unit         what stock is counted in
    |   whole_units_only  no half tablets, no half packs
    |   allow_unit_sale   sell the base unit loose when a larger pack exists (Q16)
    |   packs             suggested pack levels: name and base units per pack
    |
    | Client answers of 2026-10-20: tablets come in boxes of 30 and 100 (16
    | sometimes; real sizes come from the import), no half tablets; syrups are
    | counted in ml with a Bottle pack, so a doctor's dispensary can sell loose
    | ml once selling loose is per location (checkout branch); injections sell
    | as single vials and boxes. Two packs of one item need different names.
    */
    'pharmacy' => [
        'unit_defaults' => [
            'tablet' => ['base_unit' => 'Tablet', 'whole_units_only' => true, 'allow_unit_sale' => true, 'packs' => [['name' => 'Box of 30', 'factor' => 30], ['name' => 'Box of 100', 'factor' => 100]]],
            'capsule' => ['base_unit' => 'Capsule', 'whole_units_only' => true, 'allow_unit_sale' => true, 'packs' => [['name' => 'Box of 30', 'factor' => 30], ['name' => 'Box of 100', 'factor' => 100]]],
            'syrup' => ['base_unit' => 'ml', 'whole_units_only' => true, 'allow_unit_sale' => false, 'packs' => [['name' => 'Bottle', 'factor' => 100]]],
            'suspension' => ['base_unit' => 'Bottle', 'whole_units_only' => true, 'allow_unit_sale' => false, 'packs' => []],
            'drops' => ['base_unit' => 'Bottle', 'whole_units_only' => true, 'allow_unit_sale' => false, 'packs' => []],
            'cream' => ['base_unit' => 'Tube', 'whole_units_only' => true, 'allow_unit_sale' => false, 'packs' => []],
            'ointment' => ['base_unit' => 'Tube', 'whole_units_only' => true, 'allow_unit_sale' => false, 'packs' => []],
            'gel' => ['base_unit' => 'Tube', 'whole_units_only' => true, 'allow_unit_sale' => false, 'packs' => []],
            'inhaler' => ['base_unit' => 'Piece', 'whole_units_only' => true, 'allow_unit_sale' => false, 'packs' => []],
            'injection' => ['base_unit' => 'Vial', 'whole_units_only' => true, 'allow_unit_sale' => true, 'packs' => [['name' => 'Box', 'factor' => 10]]],
            'other' => ['base_unit' => 'Piece', 'whole_units_only' => true, 'allow_unit_sale' => true, 'packs' => []],
        ],

        // Offered while typing a strength; any other unit can be typed.
        'strength_units' => ['mg', 'g', 'mcg', 'IU', 'ml', '%', 'mg/ml', 'mg/5 ml', 'mcg/dose', 'IU/ml'],
    ],

    /*
    | Excel / CSV import (docs/PHARMACY.md §10). Files are kept on the local
    | disk under imports/{shop}. Writing a file runs on the queue (the dev
    | stack runs queue:listen; the clinic's server runs a queue worker), in
    | one transaction, so the page shows progress from the cache store
    | below: it must not be the database (the transaction would hide it).
    */
    'imports' => [
        'max_rows' => 20000,
        'max_file_kb' => 20480,
        'queue' => env('POS_IMPORT_QUEUE', 'default'),
        'progress_store' => env('POS_IMPORT_PROGRESS_STORE', 'file'),
        // Rows of problems listed on the preview page; the error report has them all.
        'preview_issue_limit' => 200,
    ],

    /*
    | Barcode labels (labels module, docs/PHARMACY.md §9, branch 7b). Every
    | size is in millimetres. A template starts from a preset (a thermal roll
    | prints one label per page; an A4 sheet prints columns x rows) and can be
    | changed to any size. Readability and scanning limits, enforced when a
    | template is saved and when labels are laid out:
    |
    |   min_module     narrowest barcode bar (X-dimension). 0.25 mm is two
    |                  dots on a 203 dpi printer, about 76% of the nominal
    |                  EAN-13 (0.33 mm), which store scanners read; bars are
    |                  whole printer dots, so they print crisp
    |   max_module     widest bar used when the label has room to spare
    |   min_bar_height shortest bars printed (truncated EAN-13)
    |   min_font_pt    smallest text; names shrink to it, then wrap to two
    |                  lines, then are cut short with an ellipsis
    */
    'labels' => [
        'min_module' => 0.25,
        'max_module' => 0.40,
        'min_bar_height' => 5.0,
        'max_bar_height' => 15.0,
        'min_font_pt' => 6.0,
        'max_copies' => 1000,          // of one label in one print
        'max_labels_per_print' => 2000,
        'dpi' => [203, 300, 600],

        'presets' => [
            'roll_38x25' => ['label' => 'Thermal roll 38 × 25 mm', 'kind' => 'roll', 'label_width' => 38, 'label_height' => 25],
            'roll_50x25' => ['label' => 'Thermal roll 50 × 25 mm', 'kind' => 'roll', 'label_width' => 50, 'label_height' => 25],
            'roll_50x30' => ['label' => 'Thermal roll 50 × 30 mm', 'kind' => 'roll', 'label_width' => 50, 'label_height' => 30],
            'roll_40x30' => ['label' => 'Thermal roll 40 × 30 mm', 'kind' => 'roll', 'label_width' => 40, 'label_height' => 30],
            'roll_58x40' => ['label' => 'Thermal roll 58 × 40 mm', 'kind' => 'roll', 'label_width' => 58, 'label_height' => 40],
            // A4 sticker sheets (the usual 24, 40 and 65 labels a sheet).
            'a4_3x8' => ['label' => 'A4 sheet, 3 × 8 (24 labels of 70 × 37 mm)', 'kind' => 'sheet', 'label_width' => 70, 'label_height' => 37, 'columns' => 3, 'rows' => 8, 'page_width' => 210, 'page_height' => 297, 'margin_top' => 0.5, 'margin_left' => 0, 'gap_x' => 0, 'gap_y' => 0],
            'a4_4x10' => ['label' => 'A4 sheet, 4 × 10 (40 labels of 48.5 × 25.4 mm)', 'kind' => 'sheet', 'label_width' => 48.5, 'label_height' => 25.4, 'columns' => 4, 'rows' => 10, 'page_width' => 210, 'page_height' => 297, 'margin_top' => 21.5, 'margin_left' => 8, 'gap_x' => 0, 'gap_y' => 0],
            'a4_5x13' => ['label' => 'A4 sheet, 5 × 13 (65 labels of 38.1 × 21.2 mm)', 'kind' => 'sheet', 'label_width' => 38.1, 'label_height' => 21.2, 'columns' => 5, 'rows' => 13, 'page_width' => 210, 'page_height' => 297, 'margin_top' => 10.7, 'margin_left' => 4.75, 'gap_x' => 2.5, 'gap_y' => 0],
        ],
    ],

    /*
    | Security for an install on the public internet (docs/deploy/CLOUD.md).
    | Users with one of two_factor_roles cannot use the app until they have
    | turned on two-factor authentication (Settings → Security); for everyone
    | else it is optional. Enforced when require_two_factor is on: by default
    | in production only, so local development and the test suite are as before.
    | The Content-Security-Policy header is sent in production (csp), never
    | while Vite's dev server is running.
    */
    'security' => [
        'two_factor_roles' => ['owner'],
        'require_two_factor' => (bool) env('POS_REQUIRE_2FA', env('APP_ENV') === 'production'),
        'csp' => (bool) env('POS_CSP', env('APP_ENV') === 'production'),
        // App\Support\PasswordRules: 12+ characters for admin users, 10+ for till-only
        // users, never one known from a breach. Off: Laravel's 8 characters (development).
        'strict_passwords' => (bool) env('POS_STRICT_PASSWORDS', env('APP_ENV') === 'production'),
        // On top of Fortify's 5 a minute per email and address: sign-in attempts per
        // minute from one address, whatever the email (password spraying).
        'login_attempts_per_ip' => 20,
        // Behind Caddy on the same server: the client's address and HTTPS come from
        // its X-Forwarded-* headers, trusted from these proxies only (AppServiceProvider).
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))),
    ],

    /*
    | Backups (pos:backup, pos:backup:verify, pos:backup:restore; docs/deploy/CLOUD.md).
    | A backup is the database (mysqldump --single-transaction) and storage/app,
    | in one tar.gz encrypted with age to every recipient (the offline recovery
    | key kept by Webxkey / the owner, and the server's verify key), kept in
    | local_path and uploaded with rclone to `remote` (rclone remote:path at
    | another provider). The server's private verify key (identity) lets the
    | weekly verify decrypt and restore into the scratch database; without it
    | verify checks the checksum only.
    */
    'backup' => [
        'local_path' => env('POS_BACKUP_LOCAL_PATH', storage_path('backups')),
        // The files backed up with the database: uploads, logos, import files.
        'storage_root' => storage_path('app'),
        // The install's .env, kept in the (encrypted) archive as env/.env so a new server
        // can be set up from a backup alone; a restore never overwrites the .env in use.
        'env_file' => base_path('.env'),
        'keep_local' => 2,
        'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('POS_BACKUP_AGE_RECIPIENTS', ''))))),
        'identity' => env('POS_BACKUP_AGE_IDENTITY'),
        'remote' => env('POS_BACKUP_REMOTE'),
        'rclone_config' => env('POS_BACKUP_RCLONE_CONFIG'),
        'keep_daily' => 14,
        'keep_monthly' => 12,
        // The scratch database pos:backup:verify restores into (MySQL); default <DB_DATABASE>_verify.
        'verify_database' => env('POS_BACKUP_VERIFY_DATABASE'),
        'binaries' => [
            'age' => env('POS_BACKUP_AGE_BINARY', 'age'),
            'rclone' => env('POS_BACKUP_RCLONE_BINARY', 'rclone'),
            'mysqldump' => env('POS_BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
            'mysql' => env('POS_BACKUP_MYSQL_BINARY', 'mysql'),
            'tar' => 'tar',
        ],
        // Rows here come and go all the time: in the backup, but not counted by verify.
        'volatile_tables' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'notifications'],
    ],

    /*
    | Health checks (Administration → System health, pos:health; docs/deploy/CLOUD.md).
    | pos:health --alert runs hourly from the scheduler and mails the owners and
    | alert_email about each failing check, at most once per check every
    | alert_every_hours (mail is optional: without it alerts go to the log).
    */
    'health' => [
        'backup_stale_hours' => 26,
        'verify_stale_days' => 8,
        'disk_warn_percent' => 85,
        'queue_stale_minutes' => 5,
        'scheduler_stale_minutes' => 5,
        'alert_email' => env('POS_ALERT_EMAIL'),
        'alert_every_hours' => 12,
        // Ubuntu writes it when a security update needs a restart.
        'reboot_required_file' => '/var/run/reboot-required',
    ],

    /*
    | The timezone the nightly backup and the weekly check are scheduled in
    | (routes/console.php): the clinic's, so they run while nobody bills.
    */
    'schedule_timezone' => env('POS_SCHEDULE_TIMEZONE', 'Asia/Colombo'),

    /*
    | The release this install runs: the VERSION file the release package
    | carries (git describe of its tag), else "dev" for a checkout.
    */
    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev',

    /*
    | Modules that are built (or will be built) but can be switched on per
    | shop with Laravel Pennant. Off by default, except vehicle_fitment,
    | which starts on for retail shops (PosServiceProvider::registerModules).
    |   php artisan pos:module enable purchasing --shop=1
    */
    'modules' => [
        'suppliers' => 'Suppliers',
        'purchasing' => 'Purchase orders & goods received',
        'labels' => 'Barcode label printing',
        'customers' => 'Customers & credit',
        'multi_location' => 'Multiple branches / stock transfers',
        'wholesale' => 'Wholesale price lists & units',
        'offline_sync' => 'Offline till sync API',
        'pharmacy' => 'Pharmacy: drug master, batches & pharmacy checkout',
        'vehicle_fitment' => 'Vehicle fitment for spare parts',
    ],

    /*
    | Modules that need other modules. Switching one on switches these on too,
    | and none of them can be switched off while it is on. Enforced only by
    | App\Services\ModuleService.
    */
    'module_requires' => [
        'pharmacy' => ['suppliers', 'purchasing', 'multi_location'],
        // Every order, delivery and return is from a supplier.
        'purchasing' => ['suppliers'],
    ],

    /*
    | Install profile: install-time defaults only. It decides what db:seed and
    | pos:install create (reference data, demo shop, locations, roles) and
    | which modules a new shop starts with. After that, whether a module is
    | on is only the shop's Pennant state; no runtime code reads the profile.
    */
    'profile' => env('POS_PROFILE', 'retail'),

    'profiles' => [
        'retail' => [
            'business_type' => 'retail',
            'modules' => ['vehicle_fitment'],
            'reference_seeders' => [VehicleSeeder::class],
            'demo_seeder' => DemoShopSeeder::class,
            'locations' => [
                ['code' => 'MAIN', 'name' => 'Main shop', 'default' => true],
            ],
            'roles' => ['owner', 'cashier'],
            'settings' => [],
        ],
        'pharmacy' => [
            'business_type' => 'pharmacy',
            // labels: in-store barcodes and labels for the medicines and packs without one.
            'modules' => ['pharmacy', 'multi_location', 'suppliers', 'purchasing', 'offline_sync', 'labels'],
            'reference_seeders' => [],
            'demo_seeder' => DemoClinicSeeder::class,
            'locations' => [
                ['code' => 'GEN', 'name' => 'General Pharmacy', 'default' => true],
                ['code' => 'DOC', 'name' => "Doctor's Medicines"],
                // Expired, damaged or recalled stock moved off the shelves until it is written
                // off or returned (docs/PHARMACY.md §9, branch 10): no till sells from it.
                ['code' => 'QUAR', 'name' => 'Quarantine / Expired', 'sellable' => false],
            ],
            // Pharmacist and Doctor join when their permissions exist (docs/PHARMACY.md §8).
            'roles' => ['owner', 'cashier'],
            // Shop settings a new shop starts with, over 'shop_defaults' (editable afterwards
            // in Shop settings). Client answers of 2026-10-20: short expiry within 90 days;
            // all stock is received at General Pharmacy and transferred to the doctor's;
            // no discounts, so cashiers may give none (the owner still can); a prescription
            // line needs the prescriber; a bank transfer needs its reference. Receipts
            // (2026-10-21): the doctor wants no expiry date printed; the prescriber is.
            'settings' => [
                'near_expiry_warning_days' => 90,
                'receive_at_default_location' => true,
                'max_cashier_discount_percent' => 0,
                'rx_prescriber' => 'required',
                'bank_transfer_reference_required' => true,
                'receipt_show_expiry' => false,
                'receipt_show_prescriber' => true,
                // Decided 2026-10-22: a till sells only with an open shift (no "Sell without a shift").
                'require_open_shift' => true,
                // A calm clinic look (feat/clinic-theme-dashboard); the owner can change it
                // (Settings → Appearance). It replaces the teal brand colour seeded before.
                'theme_preset' => 'clinic_teal',
            ],
        ],
    ],

    /*
    | Every permission in the system. Add new ones here, then re-run
    |   php artisan db:seed --class=RolesAndPermissionsSeeder
    */
    'permissions' => [
        'pos.checkout' => 'Use the checkout screen',
        'admin.access' => 'Open the admin panel (/admin)',
        'pos.discount.unlimited' => 'Give discounts above the cashier limit',
        'products.view' => 'View products',
        'products.manage' => 'Create and edit products and variants',
        'prices.manage' => 'Change selling prices',
        'cost.view' => 'See cost prices and profit',
        'cost.manage' => 'Revalue the average cost of an item',
        'stock.view' => 'View stock levels',
        'stock.receive' => 'Record stock in',
        'stock.adjust' => 'Adjust stock (damage, count corrections)',
        'stock.transfer' => 'Move stock between locations, and fulfil stock requests (multi_location)',
        'stock.request' => 'Ask another location for stock: stock requests, at the locations the user may use (multi_location)',
        'stock.write_off' => 'Write off expired or damaged batches, valued at what they cost (pharmacy)',
        'stock.count' => 'Start stock counts and enter counted quantities; posting a count needs stock.adjust',
        'batches.manage' => 'Accept stock close to expiry and settle unallocated stock (batches)',
        'pos.batch_override' => 'Pick a batch at checkout instead of first expiry first out (batches)',
        'pos.price_override' => 'Type the price of a service at the till, e.g. the consultation fee (products that allow it)',
        'pos.opd_bill' => 'Ring up OPD bills: consultation plus one Medicines amount (OPD tills)',
        'locations.manage' => 'Create and edit stock locations (multi_location)',
        'locations.all' => 'Sell and use tills at every location when no allowed locations are set (multi_location)',
        'purchasing.manage' => 'Suppliers, purchase orders, supplier returns and payments; reverse a posted goods received note (suppliers, purchasing)',
        'purchasing.receive' => 'Enter and post goods received notes (purchasing)',
        'payables.view' => 'See what is owed to suppliers: payables, balances and statements (purchasing); paying needs purchasing.manage',
        'sales.view' => 'View own sales',
        'sales.view_all' => 'View all sales',
        'sales.void' => 'Void a sale',
        'sales.review' => 'Review offline sales flagged for price or invoice differences',
        'returns.create' => 'Process returns and refunds',
        'shifts.open_close' => 'Open and close own shift',
        'shifts.manage' => 'View and manage all shifts',
        'reports.view' => 'View sales and stock reports',
        'reports.profit' => 'View profit reports',
        'users.manage' => 'Manage users and roles',
        'settings.manage' => 'Change shop settings',
        'tills.manage' => 'Register tills for offline sales and revoke their access',
        'imports.manage' => 'Import items, suppliers, opening stock and opening invoices from Excel or CSV',
        'labels.print' => 'Print barcode labels for items and packs, and give a pack without a barcode an in-store one (labels)',
        'labels.manage' => 'Set up label templates: sizes, sheets and what a label prints (labels)',
        'modules.manage' => 'Turn modules on or off',
        'system.health' => 'See the system health page: version, disk, queue, scheduler, backups and each till\'s last sync',
        'backups.download' => 'Download the latest backup (encrypted; opened only with the offline recovery key)',
        'audit.view' => 'View the audit log (includes cost price history)',
    ],

    /*
    | Roles and their permissions. '*' = every permission.
    | Adding Manager / Storekeeper later is just a new entry here.
    */
    'roles' => [
        'owner' => ['*'],
        'cashier' => [
            'pos.checkout',
            // Do nothing in a shop without OPD tills or products that allow a typed price.
            'pos.price_override',
            'pos.opd_bill',
            // Asks General Pharmacy for stock from the OPD; does nothing without multi_location.
            'stock.request',
            'products.view',
            'stock.view',
            'sales.view',
            'shifts.open_close',
        ],
    ],

    /*
    | Models whose changes are written to the audit_logs table.
    */
    'audited_models' => [
        User::class,
        Shop::class,
        Product::class,
        ProductVariant::class,
        VariantUnit::class,
        VariantUnitLocation::class,
        VariantPrice::class,
        DrugDetail::class,
        Device::class,
        Location::class,
        Supplier::class,
        SupplierOpeningBalance::class,
        SupplierPayment::class,
        SupplierAllocation::class,
    ],

    /*
    | Attributes never written to the audit log.
    */
    'audit_exclude' => [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'created_at', 'updated_at', 'last_login_at',
    ],
];
