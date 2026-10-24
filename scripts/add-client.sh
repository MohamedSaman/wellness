#!/usr/bin/env bash
# Many variables here are read only by fill(), by name (${!name}).
# shellcheck disable=SC2034
#
# Adds one client environment to a server prepared by provision-server.sh
# (docs/deploy/CLOUD.md, "Adding a client"). Run once for production and
# once for staging, each with its own name, domain and database.
#
#   sudo bash scripts/add-client.sh --client=clinic --domain=pos.clinic.lk \
#        --env=production --profile=pharmacy \
#        --recovery-recipient=age1…  (the PUBLIC half of the offline recovery key) \
#        --backup-remote=b2:webxkey-pos-backups/clinic-production \
#        --release=/srv/pos/incoming/pos-v1.1.0.tar.gz \
#        [--rclone-config=/root/clinic-rclone.conf] [--alert-email=ops@webxkey.lk] \
#        [--timezone=Asia/Colombo] [--app-name="Clinic POS"] [--max-children=8]
#
#   sudo bash scripts/add-client.sh --client=clinic-staging --domain=staging.pos.clinic.lk --env=staging …
#
# Creates the Linux user pos-<client> (the app, its queue and scheduler run
# as it), /srv/pos/<client> (releases, shared/.env and storage, backups,
# keys), the MySQL database pos_<client> and its scratch database for the
# backup check, with a user that has rights on those two only and a
# generated password; the .env (production, no debug, secure cookies), the
# server's backup verify key, the PHP-FPM pool, the Caddy site (Let's
# Encrypt), the queue worker and scheduler; deploys the release and runs
# pos:install --profile=… for the owner's account (interactive).

set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
# shellcheck source=lib/common.sh
. "$HERE/lib/common.sh"

PHP_VERSION=8.5
CLIENT="" DOMAIN="" APP_ENV=production PROFILE="" RECOVERY_RECIPIENT="" BACKUP_REMOTE="" RELEASE=""
RCLONE_CONFIG="" ALERT_EMAIL="" TIMEZONE="Asia/Colombo" APP_NAME="" MAX_CHILDREN=8

for arg in "$@"; do
    case "$arg" in
        --client=*) CLIENT="${arg#*=}" ;;
        --domain=*) DOMAIN="${arg#*=}" ;;
        --env=*) APP_ENV="${arg#*=}" ;;
        --profile=*) PROFILE="${arg#*=}" ;;
        --recovery-recipient=*) RECOVERY_RECIPIENT="${arg#*=}" ;;
        --backup-remote=*) BACKUP_REMOTE="${arg#*=}" ;;
        --release=*) RELEASE="${arg#*=}" ;;
        --rclone-config=*) RCLONE_CONFIG="${arg#*=}" ;;
        --alert-email=*) ALERT_EMAIL="${arg#*=}" ;;
        --timezone=*) TIMEZONE="${arg#*=}" ;;
        --app-name=*) APP_NAME="${arg#*=}" ;;
        --max-children=*) MAX_CHILDREN="${arg#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        -h | --help) sed -n '2,30p' "$0"; exit 0 ;;
        *) die "Unknown option $arg (see --help)." ;;
    esac
done

require_root
[[ "$CLIENT" =~ ^[a-z][a-z0-9-]{1,30}$ ]] || die "--client: lower-case letters, digits and dashes (clinic, clinic-staging)."
[ -n "$DOMAIN" ] || die "--domain is required (its DNS A record must point at this server)."
[[ "$APP_ENV" =~ ^(production|staging)$ ]] || die "--env is production or staging."
[[ "$PROFILE" =~ ^(retail|pharmacy)$ ]] || die "--profile is retail or pharmacy."
[[ "$RECOVERY_RECIPIENT" =~ ^age1[0-9a-z]{58}$ ]] || die "--recovery-recipient must be the offline recovery key's public half (age1…), from age-keygen on Webxkey's offline machine."
[ -n "$BACKUP_REMOTE" ] || die "--backup-remote is required: an rclone remote:path at another provider (b2:bucket/clinic-production)."
[ -n "$RELEASE" ] && [ -f "$RELEASE" ] || [ "$DRY_RUN" = "1" ] || die "--release must be a release package (pos-vX.Y.Z.tar.gz from GitHub Releases)."
APP_NAME="${APP_NAME:-POS}"

USER_NAME="pos-$CLIENT"
ROOT="/srv/pos/$CLIENT"
DB_DATABASE="pos_${CLIENT//-/_}"
DB_USERNAME="$DB_DATABASE"
[ ${#DB_USERNAME} -le 32 ] || die "The client name is too long for a MySQL user name."

# ---------------------------------------------------------------- user and directories
say "User $USER_NAME and $ROOT"
id "$USER_NAME" >/dev/null 2>&1 || run useradd --system --home-dir "$ROOT" --shell /usr/sbin/nologin "$USER_NAME"
# Caddy reads public/ through the group; deploy uploads releases and starts deploy.sh.
run usermod -aG "$USER_NAME" caddy
run install -d -m 750 -o "$USER_NAME" -g "$USER_NAME" "$ROOT" "$ROOT/releases" "$ROOT/shared" "$ROOT/bin"
run install -d -m 700 -o "$USER_NAME" -g "$USER_NAME" "$ROOT/backups" "$ROOT/keys"
for dir in app/public app/private framework/cache/data framework/sessions framework/views logs; do
    run install -d -m 750 -o "$USER_NAME" -g "$USER_NAME" "$ROOT/shared/storage/$dir"
done
for script in lib/common.sh deploy.sh rollback.sh; do
    run install -D -m 750 -o "$USER_NAME" -g "$USER_NAME" "$REPO/scripts/$script" "$ROOT/bin/$script"
done

# ---------------------------------------------------------------- database
say "MySQL database $DB_DATABASE (+ ${DB_DATABASE}_verify for the backup check)"
if [ -f "$ROOT/shared/.env" ]; then
    DB_PASSWORD="$(env_value "$ROOT/shared/.env" DB_PASSWORD)"
    APP_KEY="$(env_value "$ROOT/shared/.env" APP_KEY)"
    warn "$ROOT/shared/.env exists: keeping its APP_KEY and database password."
else
    DB_PASSWORD="$(secret 40)"
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
fi
# Least privilege: its own two databases, no FILE, PROCESS, SUPER or GRANT.
PRIVILEGES="SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER, CREATE VIEW, SHOW VIEW"
SQL="CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USERNAME'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT $PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'localhost';
GRANT $PRIVILEGES ON \`${DB_DATABASE}_verify\`.* TO '$DB_USERNAME'@'localhost';
FLUSH PRIVILEGES;"
if [ "$DRY_RUN" = "1" ]; then
    echo "+ mysql: create $DB_DATABASE and user $DB_USERNAME (password generated), grant on $DB_DATABASE and ${DB_DATABASE}_verify"
else
    mysql <<<"$SQL"
fi

# ---------------------------------------------------------------- backup keys
say "Backup keys"
if [ ! -f "$ROOT/keys/server-verify.key" ]; then
    run sudo -u "$USER_NAME" age-keygen -o "$ROOT/keys/server-verify.key"
fi
VERIFY_RECIPIENT="$([ "$DRY_RUN" = "1" ] && echo age1dryrun || age-keygen -y "$ROOT/keys/server-verify.key")"
if [ -n "$RCLONE_CONFIG" ]; then
    run install -m 600 -o "$USER_NAME" -g "$USER_NAME" "$RCLONE_CONFIG" "$ROOT/rclone.conf"
elif [ ! -f "$ROOT/rclone.conf" ]; then
    run install -m 600 -o "$USER_NAME" -g "$USER_NAME" /dev/null "$ROOT/rclone.conf"
    warn "Set up the backup store: sudo -u $USER_NAME rclone config --config $ROOT/rclone.conf (remote named as in $BACKUP_REMOTE)."
fi

# ---------------------------------------------------------------- .env
say ".env ($APP_ENV, no debug, secure cookies)"
if [ ! -f "$ROOT/shared/.env" ]; then
    DATE="$(date -u +%F)"
    fill "$REPO/deploy/env/production.env" CLIENT APP_ENV DATE APP_NAME APP_KEY DOMAIN PROFILE DB_DATABASE DB_USERNAME DB_PASSWORD TIMEZONE RECOVERY_RECIPIENT VERIFY_RECIPIENT BACKUP_REMOTE ALERT_EMAIL \
        | write_file "$ROOT/shared/.env" 0600 "$USER_NAME:$USER_NAME"
fi

# ---------------------------------------------------------------- PHP-FPM, Caddy, units, sudo
say "PHP-FPM pool, Caddy site, queue worker and scheduler"
fill "$REPO/deploy/php/pool.conf" CLIENT MAX_CHILDREN | write_file "/etc/php/${PHP_VERSION}/fpm/pool.d/pos-$CLIENT.conf" 0644
run systemctl restart "php${PHP_VERSION}-fpm"

if [ "$APP_ENV" = "staging" ]; then
    EXTRA_HEADERS='X-Robots-Tag "noindex, nofollow"'
else
    EXTRA_HEADERS=''
fi
fill "$REPO/deploy/caddy/site.caddy" CLIENT APP_ENV DOMAIN EXTRA_HEADERS | write_file "/etc/caddy/sites/$CLIENT.caddy" 0644
run caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
run systemctl reload caddy

# deploy may run this client's deploy and rollback scripts as the client, nothing else.
write_file "/etc/sudoers.d/pos-$CLIENT" 0440 <<EOF
deploy ALL=($USER_NAME) NOPASSWD: $ROOT/bin/deploy.sh, $ROOT/bin/rollback.sh
EOF
[ "$DRY_RUN" = "1" ] || visudo -cf "/etc/sudoers.d/pos-$CLIENT" >/dev/null || die "The sudoers rule is invalid."

# ---------------------------------------------------------------- first release
if [ ! -L "$ROOT/current" ]; then
    say "First release"
    run install -m 644 "$RELEASE" "/srv/pos/incoming/$(basename "$RELEASE")"
    run sudo -u "$USER_NAME" -H "$ROOT/bin/deploy.sh" --release="/srv/pos/incoming/$(basename "$RELEASE")" --skip-backup --maintenance=never
fi
run systemctl enable --now "pos-queue@$CLIENT.service" "pos-scheduler@$CLIENT.timer"

# ---------------------------------------------------------------- the shop
SHOPS="$([ "$DRY_RUN" = "1" ] && echo '?' || sudo -u "$USER_NAME" php "$ROOT/current/artisan" tinker --execute='echo App\Models\Shop::query()->count();' 2>/dev/null | tail -n 1)"
if [ "$SHOPS" = "0" ]; then
    say "The shop and its owner (pos:install --profile=$PROFILE)"
    sudo -u "$USER_NAME" -H php "$ROOT/current/artisan" pos:install --profile="$PROFILE"
else
    echo "+ sudo -u $USER_NAME php $ROOT/current/artisan pos:install --profile=$PROFILE (when the database has no shop yet)"
fi

cat <<EOF

$(say "Done: https://$DOMAIN")
Record now, in Webxkey's password manager under "$CLIENT ($APP_ENV)":
  APP_KEY     (in $ROOT/shared/.env)   without it encrypted data cannot be read back
  DB password (in $ROOT/shared/.env)
Then:
  1. sudo -u $USER_NAME php $ROOT/current/artisan pos:backup        first backup, uploaded to $BACKUP_REMOTE
  2. sudo -u $USER_NAME php $ROOT/current/artisan pos:backup:verify restore check
  3. Sign in as the owner, turn on two-factor authentication, open Administration → System health.
EOF
