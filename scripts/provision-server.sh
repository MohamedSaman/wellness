#!/usr/bin/env bash
#
# Prepares a fresh Ubuntu 26.04 LTS VPS for POS clients (docs/deploy/CLOUD.md,
# "Provisioning"). Safe to run again: every step checks before it changes.
#
#   sudo bash scripts/provision-server.sh --acme-email=ops@webxkey.lk \
#        --deploy-key="ssh-ed25519 AAAA… github-actions" [--admin-key="ssh-ed25519 AAAA… you"] \
#        [--swap=2G] [--disable-root-login]
#
# Installs PHP 8.5-FPM with the app's extensions, MySQL 8.4 (localhost only),
# Caddy (automatic HTTPS), age, rclone, Composer; creates the `deploy` user
# (SSH keys only); firewall (22, 80, 443), fail2ban for SSH, unattended
# security upgrades, swap, time sync, journald and log limits; the systemd
# templates for each client's queue worker and scheduler.
# Clients are added afterwards with scripts/add-client.sh.

set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
# shellcheck source=lib/common.sh
. "$HERE/lib/common.sh"

PHP_VERSION=8.5
ACME_EMAIL=""
DEPLOY_KEY=""
ADMIN_KEY=""
SWAP_SIZE=2G
DISABLE_ROOT_LOGIN=0

for arg in "$@"; do
    case "$arg" in
        --acme-email=*) ACME_EMAIL="${arg#*=}" ;;
        --deploy-key=*) DEPLOY_KEY="${arg#*=}" ;;
        --admin-key=*) ADMIN_KEY="${arg#*=}" ;;
        --swap=*) SWAP_SIZE="${arg#*=}" ;;
        --disable-root-login) DISABLE_ROOT_LOGIN=1 ;;
        --dry-run) DRY_RUN=1 ;;
        -h | --help) sed -n '2,18p' "$0"; exit 0 ;;
        *) die "Unknown option $arg (see --help)." ;;
    esac
done

require_root
[ -n "$ACME_EMAIL" ] || die "--acme-email is required (Let's Encrypt sends certificate warnings there)."

if [ -r /etc/os-release ]; then
    . /etc/os-release
    [ "${VERSION_ID:-}" = "26.04" ] || warn "Written for Ubuntu 26.04 LTS; this is ${PRETTY_NAME:-unknown}."
fi

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------- packages
say "Packages"
if [ ! -f /etc/apt/sources.list.d/caddy-stable.list ]; then
    # Caddy's own repository: Ubuntu's caddy is too old for resolve_root_symlink (zero-downtime deploys).
    run apt-get update -qq
    run apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https curl gnupg
    if [ "$DRY_RUN" = "1" ]; then
        echo "+ add the Caddy apt repository (dl.cloudsmith.io/public/caddy/stable)"
    else
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' >/etc/apt/sources.list.d/caddy-stable.list
    fi
fi
run apt-get update -qq
run apt-get install -y -qq \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-gd" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-opcache" \
    mysql-server caddy age rclone unzip git curl ca-certificates \
    ufw fail2ban unattended-upgrades chrony logrotate

if ! command -v composer >/dev/null 2>&1; then
    say "Composer"
    if [ "$DRY_RUN" = "1" ]; then
        echo "+ install composer to /usr/local/bin/composer (installer checked against its signature)"
    else
        expected="$(curl -fsSL https://composer.github.io/installer.sig)"
        curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
        actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
        [ "$expected" = "$actual" ] || die "The Composer installer's checksum does not match."
        php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
        rm -f /tmp/composer-setup.php
    fi
fi

# ---------------------------------------------------------------- time, swap, journald
say "Time sync (UTC), swap, logs"
run timedatectl set-timezone UTC
run systemctl enable --now chrony

if ! swapon --show=NAME --noheadings | grep -q .; then
    run fallocate -l "$SWAP_SIZE" /swapfile
    run chmod 600 /swapfile
    run mkswap /swapfile
    run swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || { [ "$DRY_RUN" = "1" ] && echo "+ add /swapfile to /etc/fstab" || echo '/swapfile none swap sw 0 0' >>/etc/fstab; }
fi
write_file /etc/sysctl.d/90-pos.conf 0644 <<'EOF'
vm.swappiness = 10
EOF
run sysctl --system --quiet

write_file /etc/systemd/journald.conf.d/90-pos.conf 0644 <<'EOF'
[Journal]
SystemMaxUse=500M
MaxRetentionSec=30day
EOF
run systemctl restart systemd-journald

# Laravel writes daily logs and keeps 14 (LOG_CHANNEL=daily); this catches anything else there.
write_file /etc/logrotate.d/pos 0644 <<'EOF'
/srv/pos/*/shared/storage/logs/php-fpm.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
    su root root
}
EOF

# ---------------------------------------------------------------- deploy user and SSH
say "deploy user, SSH with keys only"
if ! id deploy >/dev/null 2>&1; then
    run useradd --create-home --shell /bin/bash deploy
fi
run install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
for key in "$DEPLOY_KEY" "$ADMIN_KEY"; do
    if [ -n "$key" ] && ! grep -qxF "$key" /home/deploy/.ssh/authorized_keys 2>/dev/null; then
        if [ "$DRY_RUN" = "1" ]; then echo "+ add a key to /home/deploy/.ssh/authorized_keys"; else echo "$key" >>/home/deploy/.ssh/authorized_keys; fi
    fi
done
[ "$DRY_RUN" = "1" ] || { touch /home/deploy/.ssh/authorized_keys; chown deploy:deploy /home/deploy/.ssh/authorized_keys; chmod 600 /home/deploy/.ssh/authorized_keys; }

if [ "$DRY_RUN" != "1" ] && [ ! -s /home/deploy/.ssh/authorized_keys ] && [ ! -s /root/.ssh/authorized_keys ]; then
    die "No SSH key for deploy or root: passwords stay on so you are not locked out. Pass --deploy-key / --admin-key and run again."
fi

write_file /etc/ssh/sshd_config.d/10-pos.conf 0644 <<EOF
# scripts/provision-server.sh: keys only.
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitRootLogin $([ "$DISABLE_ROOT_LOGIN" = "1" ] && echo no || echo prohibit-password)
MaxAuthTries 4
X11Forwarding no
EOF
run sshd -t
run systemctl reload ssh

# ---------------------------------------------------------------- firewall, fail2ban, upgrades
say "Firewall (22, 80, 443), fail2ban, security upgrades"
run ufw default deny incoming
run ufw default allow outgoing
run ufw limit 22/tcp
run ufw allow 80/tcp
run ufw allow 443/tcp
run ufw allow 443/udp
run ufw --force enable

write_file /etc/fail2ban/jail.d/sshd.local 0644 <<'EOF'
[sshd]
enabled = true
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
EOF
run systemctl enable --now fail2ban
run systemctl restart fail2ban

write_file /etc/apt/apt.conf.d/20auto-upgrades 0644 <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
write_file /etc/apt/apt.conf.d/52pos-unattended 0644 <<'EOF'
// Security updates only, installed nightly. No automatic reboot: a kernel
// update shows "Server restart: Needed" on System health; reboot after
// the clinic closes (docs/deploy/CLOUD.md, Maintenance).
Unattended-Upgrade::Allowed-Origins { "${distro_id}:${distro_codename}-security"; };
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
EOF

# ---------------------------------------------------------------- MySQL
say "MySQL 8.4 on localhost only"
write_file /etc/mysql/mysql.conf.d/zz-pos.cnf 0644 <<'EOF'
[mysqld]
bind-address = 127.0.0.1
mysqlx = OFF
local_infile = 0
skip-name-resolve = ON
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
default-time-zone = '+00:00'
innodb_buffer_pool_size = 512M
max_connections = 100
EOF
run systemctl enable --now mysql
run systemctl restart mysql
# What mysql_secure_installation does, without questions (root logs in through the socket only).
run mysql -e "DELETE FROM mysql.user WHERE User=''; DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES;"

# ---------------------------------------------------------------- PHP
say "PHP ${PHP_VERSION}"
for sapi in fpm cli; do
    write_file "/etc/php/${PHP_VERSION}/${sapi}/conf.d/99-pos.ini" 0644 <"$REPO/deploy/php/pos.ini"
done
if [ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ]; then
    # Every client has its own pool (add-client.sh); the default one is not used.
    run mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled"
fi
# An FPM with no pool will not start: it starts with the first client.
if ls "/etc/php/${PHP_VERSION}/fpm/pool.d/"pos-*.conf >/dev/null 2>&1; then
    run systemctl restart "php${PHP_VERSION}-fpm"
fi

# ---------------------------------------------------------------- Caddy
say "Caddy (automatic HTTPS)"
run install -d -m 755 /etc/caddy/sites /var/log/caddy
run chown caddy:caddy /var/log/caddy
ACME_EMAIL="$ACME_EMAIL" fill "$REPO/deploy/caddy/Caddyfile" ACME_EMAIL | write_file /etc/caddy/Caddyfile 0644
run systemctl enable caddy
run systemctl reload-or-restart caddy

# ---------------------------------------------------------------- POS layout and units
say "/srv/pos, systemd templates, sudo rules"
run install -d -m 755 -o root -g root /srv/pos
run install -d -m 755 -o deploy -g deploy /srv/pos/incoming
for unit in pos-queue@.service pos-scheduler@.service pos-scheduler@.timer; do
    write_file "/etc/systemd/system/$unit" 0644 <"$REPO/deploy/systemd/$unit"
done
run systemctl daemon-reload
run install -d -m 755 /etc/sudoers.d

say "Done. Next: sudo bash scripts/add-client.sh --help"
