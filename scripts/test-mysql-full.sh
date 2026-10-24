#!/usr/bin/env sh
# The whole test suite on MySQL, as CI's "mysql" job runs it (php artisan test
# with DB_* pointing at MySQL instead of phpunit.xml's SQLite). Uses the
# database in .env.testing.mysql (see CLAUDE.md), which every test wipes, so
# it refuses any database whose name does not end in _test.
set -eu

if [ ! -f .env.testing.mysql ]; then
    echo "No .env.testing.mysql: copy .env.testing.mysql.example and point it at a MySQL test database." >&2
    exit 1
fi

set -a
. ./.env.testing.mysql
set +a

case "${DB_DATABASE:-}" in
    *_test) ;;
    *) echo "Refusing to run against \"${DB_DATABASE:-}\": the database name must end in _test." >&2; exit 1 ;;
esac

php artisan config:clear --ansi
exec php artisan test "$@"
