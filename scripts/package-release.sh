#!/usr/bin/env bash
#
# Builds the release package a server deploys (docs/deploy/CLOUD.md, "Releases"):
#
#   bash scripts/package-release.sh v1.2.0 [dist]
#
# Run after `composer install` and `npm run build` (the release workflow does
# both): the tracked files of HEAD, plus the built assets git ignores
# (public/build, the service worker, Filament's published assets), and a
# VERSION file, as dist/pos-<version>.tar.gz with its SHA-256. No vendor/
# (the server runs composer install --no-dev) and no tests.

set -Eeuo pipefail

VERSION="${1:?usage: package-release.sh <version, e.g. v1.2.0> [out dir]}"
OUT="${2:-dist}"
[[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "The version must be a semantic version tag (v1.2.0, v1.2.0-rc.1)." >&2; exit 1; }
[ -f public/build/manifest.json ] || { echo "public/build is missing: run npm run build first." >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

git archive --format=tar HEAD | tar -x -C "$STAGE"

for path in public/build public/js/filament public/css/filament public/fonts/filament; do
    if [ -d "$path" ]; then
        mkdir -p "$STAGE/$path"
        cp -a "$path/." "$STAGE/$path/"
    fi
done
for file in public/sw.* public/workbox-*; do
    [ -e "$file" ] && cp -a "$file" "$STAGE/public/"
done

rm -rf "$STAGE/tests" "$STAGE/.github"
echo "$VERSION" >"$STAGE/VERSION"

mkdir -p "$OUT"
tar -czf "$OUT/pos-$VERSION.tar.gz" -C "$STAGE" .
(cd "$OUT" && sha256sum "pos-$VERSION.tar.gz" >"pos-$VERSION.tar.gz.sha256")
echo "$OUT/pos-$VERSION.tar.gz"
