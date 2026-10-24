#!/usr/bin/env bash
#
# Puts one client back on the release before the current one
# (docs/deploy/CLOUD.md, "Rolling back"). Runs as the client's user:
#
#   sudo -u pos-clinic /srv/pos/clinic/bin/rollback.sh             undo the last deploy
#   sudo -u pos-clinic /srv/pos/clinic/bin/rollback.sh --restore   …and its database changes from the pre-deploy backup
#
# Options: --root=/srv/pos/<client>  --to=<release folder name>  --dry-run
#
# Without --restore, the migrations the last deploy ran are rolled back
# (migrate:rollback with the newer code, which knows how). If one cannot be
# reversed, the app stays in maintenance mode on the newer release and asks
# for --restore: the database is restored from the backup made just before
# that deploy (pos:backup:restore, which makes a safety backup first), so
# anything recorded since that deploy is lost: check the tills' sales after.

set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$HERE/lib/common.sh"

ROOT="$(cd "$HERE/.." && pwd)"
TO=""
RESTORE=0
PHP="${PHP_BIN:-php}"

for arg in "$@"; do
    case "$arg" in
        --root=*) ROOT="${arg#*=}" ;;
        --to=*) TO="${arg#*=}" ;;
        --restore) RESTORE=1 ;;
        --dry-run) DRY_RUN=1 ;;
        -h | --help) sed -n '2,18p' "$0"; exit 0 ;;
        *) die "Unknown option $arg (see --help)." ;;
    esac
done

[ -L "$ROOT/current" ] || die "$ROOT/current is not a release: nothing to roll back."
CURRENT="$(readlink -f "$ROOT/current")"
META="$CURRENT/.deploy"
meta() { [ -f "$META" ] && sed -n "s/^$1=//p" "$META" | tail -n 1 || true; }

if [ -z "$TO" ]; then
    TO="$(meta previous)"
fi
if [ -z "$TO" ]; then
    # No record: the newest release older than the current one.
    TO="$(ls -1 "$ROOT/releases" | sort | awk -v cur="$(basename "$CURRENT")" '$0 < cur' | tail -n 1)"
fi
TARGET="$ROOT/releases/$TO"
[ -n "$TO" ] && [ -d "$TARGET" ] || die "No earlier release to go back to${TO:+ ($TO is gone)}."
[ "$TARGET" != "$CURRENT" ] || die "$TO is already the current release."

MIGRATIONS="$(meta migrations)"
MIGRATIONS="${MIGRATIONS:-0}"
BACKUP="$(meta backup)"
DATABASE="$(env_value "$ROOT/shared/.env" DB_DATABASE)"

exec 9>"$ROOT/.deploy.lock"
flock -n 9 || die "Another deploy or rollback of this client is running."

say "Rolling back $(basename "$CURRENT") → $TO ($MIGRATIONS migration(s) to undo${BACKUP:+, pre-deploy backup $BACKUP})"
run "$PHP" "$CURRENT/artisan" down --retry=30 --refresh=15

if [ "$RESTORE" = "1" ]; then
    { [ -n "$BACKUP" ] && [ "$BACKUP" != "none" ]; } || die "No pre-deploy backup is recorded for $(basename "$CURRENT"); restore one by name: $PHP $TARGET/artisan pos:backup:restore <name>. The app is in maintenance mode."
elif [ "$MIGRATIONS" -gt 0 ]; then
    say "Undoing $MIGRATIONS migration(s) with the newer code"
    if ! run "$PHP" "$CURRENT/artisan" migrate:rollback --step="$MIGRATIONS" --force; then
        warn "A migration cannot be reversed. The app stays in maintenance mode on $(basename "$CURRENT")."
        warn "Run again with --restore to put the database back as it was before that deploy ($BACKUP)."
        exit 1
    fi
fi

say "Switching to $TO"
run ln -sfn "$TARGET" "$ROOT/current.next"
run mv -Tf "$ROOT/current.next" "$ROOT/current"

if [ "$RESTORE" = "1" ]; then
    say "Restoring the database and files from $BACKUP (a safety backup comes first)"
    run "$PHP" "$TARGET/artisan" pos:backup:restore "$BACKUP" --confirm="$DATABASE"
fi

# The configuration may have changed in .env since that release was cached.
run "$PHP" "$TARGET/artisan" optimize
run "$PHP" "$TARGET/artisan" queue:restart
run "$PHP" "$TARGET/artisan" up

URL="$(env_value "$ROOT/shared/.env" APP_URL)"
if [ "$DRY_RUN" = "1" ]; then
    echo "+ curl -fsS $URL/up"
elif ! curl -fsS --max-time 15 -o /dev/null "$URL/up"; then
    warn "$URL/up does not answer after the rollback: $PHP $TARGET/artisan pos:health."
    exit 1
fi

[ "$DRY_RUN" = "1" ] || echo "$(date -u +%Y%m%d%H%M%S) rollback $(basename "$CURRENT") -> $TO restore=$RESTORE" >>"$ROOT/releases.log"
say "Back on $TO."
