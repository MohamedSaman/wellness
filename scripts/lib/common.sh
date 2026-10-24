# shellcheck shell=bash
# Shared by the server scripts (provision-server.sh, add-client.sh, deploy.sh,
# rollback.sh). With DRY_RUN=1, `run` and `write_file` only print what they
# would do, so a script's plan can be read (and tested) without a server.

DRY_RUN="${DRY_RUN:-0}"

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31mxx\033[0m %s\n' "$*" >&2; exit 1; }

# run <command> [args…]: the command, echoed first; only echoed in a dry run.
run() {
    printf '+ %s\n' "$*"
    if [ "$DRY_RUN" != "1" ]; then
        "$@"
    fi
}

# write_file <path> <mode> [owner:group] < content: writes stdin to the file.
write_file() {
    local path="$1" mode="$2" owner="${3:-}"
    if [ "$DRY_RUN" = "1" ]; then
        printf '+ write %s (%s%s)\n' "$path" "$mode" "${owner:+ $owner}"
        cat >/dev/null
        return 0
    fi
    install -d "$(dirname "$path")"
    cat >"$path.tmp.$$"
    chmod "$mode" "$path.tmp.$$"
    if [ -n "$owner" ]; then chown "$owner" "$path.tmp.$$"; fi
    mv -f "$path.tmp.$$" "$path"
}

# fill <template>: the template with {{NAME}} replaced by $NAME for each NAME given after it.
fill() {
    local template="$1"
    shift
    local content
    content="$(cat "$template")"
    local name value
    for name in "$@"; do
        value="${!name}"
        content="${content//\{\{$name\}\}/$value}"
    done
    printf '%s\n' "$content"
}

# A random secret of letters and digits (no quotes or $ to escape in .env or SQL).
secret() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-32}"; }

require_root() { [ "$(id -u)" = "0" ] || [ "$DRY_RUN" = "1" ] || die "Run this as root (sudo $0 …)."; }

# The value of KEY in an .env file, without quotes.
env_value() {
    local file="$1" key="$2"
    sed -n "s/^${key}=//p" "$file" | tail -n 1 | sed 's/^"\(.*\)"$/\1/'
}
