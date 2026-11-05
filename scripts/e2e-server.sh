#!/usr/bin/env sh
# The app for the till's end-to-end tests (composer test:e2e; Playwright starts
# and stops this itself, see playwright.config.ts). Seeds the demo clinic into
# its own SQLite file, database/e2e.sqlite, and serves it on E2E_PORT, so the
# tests never touch the dev database or the servers on 8000 and 8001.
set -eu

if [ -f bootstrap/cache/config.php ]; then
    echo "The config is cached, so the settings below would be ignored (and the tests would hit .env's database). Run php artisan config:clear first." >&2
    exit 1
fi

port="${E2E_PORT:-8010}"
database="$(pwd)/database/e2e.sqlite"

# Real environment variables win over .env.
export APP_ENV=local
export APP_DEBUG=true
export APP_URL="http://127.0.0.1:${port}"
export POS_PROFILE=pharmacy
export DB_CONNECTION=sqlite
export DB_DATABASE="$database"
export DB_URL=
export SESSION_DRIVER=database
export CACHE_STORE=database
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array
export BROADCAST_CONNECTION=null
export PHP_CLI_SERVER_WORKERS=4
# The production Content-Security-Policy, so the browsers prove the till and the panel work under it.
export POS_CSP=true

rm -f "$database"
touch "$database"
php artisan migrate:fresh --seed --force --no-interaction > /dev/null

# A pale logo on the demo clinic, so offline-print.spec.ts sees it turn solid black.
php artisan storage:link > /dev/null 2>&1 || true
php artisan tinker --execute='
    $image = imagecreatetruecolor(240, 120);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagefilledellipse($image, 120, 60, 200, 100, imagecolorallocate($image, 150, 210, 200));
    ob_start();
    imagepng($image);
    Illuminate\Support\Facades\Storage::disk("public")->put("logos/e2e-logo.png", ob_get_clean());
    App\Models\Shop::query()->update(["logo_path" => "logos/e2e-logo.png"]);
' > /dev/null

# --no-reload passes the variables above through to the PHP server.
exec php artisan serve --no-reload --host=127.0.0.1 --port="$port"
