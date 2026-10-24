#!/usr/bin/env bash
#
# Deploys a release package to one client (docs/deploy/CLOUD.md, "Deploying").
# Runs as the client's user; GitHub Actions starts it over SSH as deploy:
#
#   sudo -u pos-clinic /srv/pos/clinic/bin/deploy.sh --release=/srv/pos/incoming/pos-v1.2.0.tar.gz
#
# Options: --root=/srv/pos/<client> (default: the folder above bin/)
#          --maintenance=auto|always|never  (auto: only while migrations run)
#          --keep=5 (releases kept)  --skip-backup  --dry-run (print the plan only)
#
# 1. unpack the release into releases/<time>-<version>, link shared .env and storage
# 2. composer install --no-dev --optimize-autoloader
# 3. back up first (pos:backup --label=pre-deploy, with the release running now)
# 4. maintenance mode when there are migrations (tills keep billing offline meanwhile)
# 5. migrate --force; cache config, routes, views, events, Filament
# 6. switch the "current" symlink in one step (Caddy resolves it per request: no downtime)
# 7. restart the queue worker, maintenance off, check /up
# Rollback: rollback.sh (records of each deploy are in releases/<id>/.deploy).

set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$HERE/lib/common.sh"

ROOT="$(cd "$HERE/.." && pwd)"
RELEASE=""
MAINTENANCE=auto
KEEP=5
SKIP_BACKUP=0
PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"

for arg in "$@"; do
    case "$arg" in
        --root=*) ROOT="${arg#*=}" ;;
        --release=*) RELEASE="${arg#*=}" ;;
        --maintenance=*) MAINTENANCE="${arg#*=}" ;;
        --keep=*) KEEP="${arg#*=}" ;;
        --skip-backup) SKIP_BACKUP=1 ;;
        --dry-run) DRY_RUN=1 ;;
        -h | --help) sed -n '2,22p' "$0"; exit 0 ;;
        *) die "Unknown option $arg (see --help)." ;;
    esac
done

[[ "$MAINTENANCE" =~ ^(auto|always|never)$ ]] || die "--maintenance is auto, always or never."
[ -f "$RELEASE" ] || die "--release must be a release package (pos-vX.Y.Z.tar.gz)."
[ -f "$ROOT/shared/.env" ] || die "$ROOT/shared/.env is missing: is $ROOT a client set up by add-client.sh?"

VERSION="$(basename "$RELEASE" .tar.gz)"
VERSION="${VERSION#pos-}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
NEW="$ROOT/releases/$STAMP-$VERSION"
CURRENT=""
[ -L "$ROOT/current" ] && CURRENT="$(readlink -f "$ROOT/current")"

# One deploy or rollback at a time per client.
exec 9>"$ROOT/.deploy.lock"
flock -n 9 || die "Another deploy or rollback of this client is running."

say "Deploying $VERSION to $ROOT${CURRENT:+ (now: $(basename "$CURRENT"))}"

# ---------------------------------------------------------------- 1. unpack
run mkdir -p "$NEW"
run tar -xzf "$RELEASE" -C "$NEW"
run rm -rf "$NEW/storage"
run ln -s "$ROOT/shared/storage" "$NEW/storage"
run ln -sf "$ROOT/shared/.env" "$NEW/.env"
run ln -sfn ../storage/app/public "$NEW/public/storage"

# ---------------------------------------------------------------- 2. dependencies
run "$COMPOSER" install --working-dir="$NEW" --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

# ---------------------------------------------------------------- 3. backup first
BACKUP="none"
if [ -n "$CURRENT" ] && [ "$SKIP_BACKUP" != "1" ]; then
    say "Backup before deploying"
    run "$PHP" "$CURRENT/artisan" pos:backup --label=pre-deploy --no-prune
    if [ "$DRY_RUN" != "1" ]; then
        for file in "$ROOT"/backups/pos-backup-*-pre-deploy.tar.gz.age; do
            [ -e "$file" ] && BACKUP="$(basename "$file")"
        done
    fi
fi

# ---------------------------------------------------------------- 4. maintenance
PENDING=0
if [ "$DRY_RUN" = "1" ]; then
    echo "+ $PHP $NEW/artisan migrate:status --pending (counts the migrations to run)"
else
    PENDING="$("$PHP" "$NEW/artisan" migrate:status --pending --no-ansi 2>/dev/null | grep -c 'Pending' || true)"
fi

DOWN=0
if [ "$MAINTENANCE" = "always" ] || { [ "$MAINTENANCE" = "auto" ] && [ "$PENDING" -gt 0 ]; }; then
    say "Maintenance mode ($PENDING migration(s)); tills bill offline meanwhile"
    run "$PHP" "${CURRENT:-$NEW}/artisan" down --retry=30 --refresh=15
    DOWN=1
fi

# ---------------------------------------------------------------- 5. migrate and cache
fail() {
    warn "$1"
    warn "The release in use is unchanged${CURRENT:+ ($(basename "$CURRENT"))}$([ "$DOWN" = "1" ] && echo '; the app stays in maintenance mode')."
    warn "Look at the error, then: $ROOT/bin/rollback.sh --restore (puts the database back as it was before: $BACKUP), or fix and deploy again."
    exit 1
}
run "$PHP" "$NEW/artisan" migrate --force || fail "The migrations failed."
run "$PHP" "$NEW/artisan" optimize || fail "Caching the configuration failed."
run "$PHP" "$NEW/artisan" filament:optimize || fail "Caching Filament failed."

if [ "$DRY_RUN" != "1" ]; then
    printf 'version=%s\nprevious=%s\nmigrations=%s\nbackup=%s\ndeployed_at=%s\n' \
        "$VERSION" "${CURRENT:+$(basename "$CURRENT")}" "$PENDING" "$BACKUP" "$STAMP" >"$NEW/.deploy"
fi

# ---------------------------------------------------------------- 6. switch
say "Switching to $(basename "$NEW")"
run ln -sfn "$NEW" "$ROOT/current.next"
run mv -Tf "$ROOT/current.next" "$ROOT/current"

# ---------------------------------------------------------------- 7. restart, up, check
run "$PHP" "$ROOT/current/artisan" queue:restart
if [ "$DOWN" = "1" ]; then
    run "$PHP" "$ROOT/current/artisan" up
fi

URL="$(env_value "$ROOT/shared/.env" APP_URL)"
if [ "$DRY_RUN" = "1" ]; then
    echo "+ curl -fsS $URL/up"
elif ! curl -fsS --max-time 15 -o /dev/null "$URL/up"; then
    warn "$URL/up does not answer. Check: $PHP $ROOT/current/artisan pos:health; undo with $ROOT/bin/rollback.sh."
    exit 1
fi

[ "$DRY_RUN" = "1" ] || echo "$STAMP $VERSION $(basename "$NEW") migrations=$PENDING backup=$BACKUP" >>"$ROOT/releases.log"

# The scripts of this release are the ones to use next time.
for script in lib/common.sh deploy.sh rollback.sh; do
    if [ -f "$NEW/scripts/$script" ]; then
        run install -D -m 750 "$NEW/scripts/$script" "$ROOT/bin/$script"
    fi
done

# ---------------------------------------------------------------- old releases
mapfile -t RELEASES < <(ls -1d "$ROOT"/releases/*/ 2>/dev/null | sed 's:/$::' | sort)
COUNT=${#RELEASES[@]}
if [ "$COUNT" -gt "$KEEP" ]; then
    for old in "${RELEASES[@]:0:$((COUNT - KEEP))}"; do
        [ "$old" = "$NEW" ] || [ "$old" = "$CURRENT" ] || run rm -rf "$old"
    done
fi

say "Deployed $VERSION."
