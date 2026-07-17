#!/usr/bin/env bash
# One-time setup for the Forge panel host. Run as root on Ubuntu 22.04/24.04:
#   sudo bash server-setup.sh
#
# Override where the panel itself is deployed (used for the queue worker):
#   sudo PANEL_DIR=/home/forge/panel bash server-setup.sh
#
# Safe to re-run: re-running after the panel is deployed will pick it up and
# start the queue worker.
set -euo pipefail

FORGE_USER=forge
FORGE_HOME=/home/forge
PANEL_DIR="${PANEL_DIR:-/home/forge/panel}"

# --- system packages -------------------------------------------------------
# --allow-releaseinfo-change: third-party repos already on the box (e.g. the
# ondrej/php PPA) occasionally rename their Label/Origin metadata, which makes
# a plain `apt-get update` fail non-interactively and abort the whole script.
# Package signatures are still verified as usual.
apt-get update --allow-releaseinfo-change
apt-get install -y apache2 php-fpm php-cli php-mysql php-xml php-curl \
    php-mbstring php-zip php-sqlite3 composer git certbot python3-certbot-apache \
    mysql-server curl ca-certificates gnupg software-properties-common

# --- per-site php versions ---------------------------------------------------
# Sites pick their PHP version at creation (config/forge.php 'php_versions').
# Each version needs a CLI binary (/usr/bin/phpX.Y) for deploys/cron/workers
# and a forge FPM pool with a versioned socket for the site's vhost. Ubuntu's
# archive carries only one PHP, so the extra versions come from the ondrej PPA
# (idempotent to re-add).
SITE_PHP_VERSIONS="8.3 8.4"

# The panel itself keeps running on the current default /usr/bin/php and the
# unversioned php-fpm-forge.sock pool. Capture that version BEFORE installing
# site versions: the new packages would otherwise flip the /usr/bin/php
# alternative to the newest version, silently moving the panel onto an FPM
# service whose pool fights over the same socket.
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

add-apt-repository -y ppa:ondrej/php

# intl is required by the apt composer binary itself (Symfony's string helpers
# need the Normalizer class once install progress rendering kicks in) and by
# many Laravel apps.
for v in $SITE_PHP_VERSIONS; do
    apt-get install -y "php$v-fpm" "php$v-cli" "php$v-mysql" "php$v-xml" \
        "php$v-curl" "php$v-mbstring" "php$v-zip" "php$v-sqlite3" "php$v-intl"
done

update-alternatives --set php "/usr/bin/php$PHP_VERSION"

# Bare `php` inside a deploy — including commands spawned by build tooling the
# panel cannot rewrite (e.g. Vite's wayfinder plugin runs `php artisan`) —
# must resolve to the site's version, not the system default. The deploy job
# prepends /opt/forge/php/<version> to PATH; these shims are what it finds.
for v in $SITE_PHP_VERSIONS; do
    mkdir -p "/opt/forge/php/$v"
    ln -sf "/usr/bin/php$v" "/opt/forge/php/$v/php"
done

# --- node.js ---------------------------------------------------------------
# Site deploy scripts (and the panel's own frontend) build assets with
# `npm ci && npm run build`. Deploys run as the forge user via a systemd
# worker, which only sees system binaries in the default PATH — a root-local
# nvm install (/root/.nvm) is unusable (forge can't even traverse /root), so
# Node MUST live in /usr/bin.
#
# Install NodeSource LTS when /usr/bin/node is missing or too old (npm 10+
# refuses Node < 18, which is the "known not to run" error on stale boxes).
node_major="$([ -x /usr/bin/node ] && /usr/bin/node -v 2>/dev/null | sed 's/^v\([0-9]*\).*/\1/' || echo 0)"
if [ "${node_major:-0}" -lt 18 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi

# /usr/local/bin precedes /usr/bin on the worker's PATH, so a stray Node there
# (old tarball / `n` / manual install) hijacks deploys and shadows the good
# one. With a valid /usr/bin/node in place, drop those shadows so the worker
# resolves the NodeSource binary.
if [ -x /usr/bin/node ]; then
    for b in node npm npx corepack; do
        if [ -e "/usr/local/bin/$b" ] && [ "$(readlink -f "/usr/local/bin/$b")" != "/usr/bin/$b" ]; then
            rm -f "/usr/local/bin/$b"
        fi
    done
fi

# --- forge user ------------------------------------------------------------
if ! id "$FORGE_USER" &>/dev/null; then
    useradd --create-home --shell /bin/bash "$FORGE_USER"
fi
mkdir -p "$FORGE_HOME/.ssh"
touch "$FORGE_HOME/.ssh/config"
chown -R "$FORGE_USER:$FORGE_USER" "$FORGE_HOME/.ssh"
chmod 700 "$FORGE_HOME/.ssh"

# Apache (www-data) must traverse /home/forge to reach site DocumentRoots.
chmod 755 "$FORGE_HOME"

# Pre-trust GitHub's host keys so git clone doesn't prompt (idempotent on re-runs).
if ! grep -qs '^github\.com' "$FORGE_HOME/.ssh/known_hosts"; then
    sudo -u "$FORGE_USER" bash -c "ssh-keyscan github.com >> $FORGE_HOME/.ssh/known_hosts 2>/dev/null"
fi

# --- php-fpm pools for the forge user ---------------------------------------
# PHP runs as the forge user via dedicated FPM pools: panel requests get the
# forge user's sudoers whitelist and SSH config, and deployed sites can write
# their own storage/ directories. The unversioned socket serves the panel;
# each site-selectable version gets its own pool and socket that the panel's
# generated vhosts route through (Site::fpmSocket()).
cat > "/etc/php/$PHP_VERSION/fpm/pool.d/forge.conf" <<POOL
[forge]
user = forge
group = forge
listen = /run/php/php-fpm-forge.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
POOL

for v in $SITE_PHP_VERSIONS; do
    cat > "/etc/php/$v/fpm/pool.d/forge-site.conf" <<POOL
[forge-$v]
user = forge
group = forge
listen = /run/php/php-fpm-forge-$v.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
POOL
done

# The stock Ubuntu php-fpm unit sets ProtectSystem=full, which mounts /etc
# read-only for php-fpm AND everything it spawns — including the panel's
# whitelisted sudo calls. Long provisioning jobs run in the queue worker (see
# below) and never hit this, but quick in-request actions (queue worker unit
# files, per-site cron files, vhost cleanup on site delete) write straight to
# /etc and fail with "Read-only file system" without these carve-outs. The
# sudoers whitelist below remains the actual gate on what gets written; the
# `-` prefix skips paths that don't exist yet so the unit still starts.
for v in $(printf '%s\n' "$PHP_VERSION" $SITE_PHP_VERSIONS | sort -u); do
    mkdir -p "/etc/systemd/system/php$v-fpm.service.d"
    cat > "/etc/systemd/system/php$v-fpm.service.d/forge-writable-paths.conf" <<'DROPIN'
[Service]
ReadWritePaths=-/etc/apache2/sites-available -/etc/apache2/sites-enabled -/etc/systemd/system -/etc/cron.d
DROPIN
done

# HTTP/2 needs mod_http2 plus an event/worker MPM — mod_http2 refuses to serve
# h2 under mpm_prefork. Sites run PHP via php-fpm (proxy_fcgi), not mod_php, so
# nothing pins us to prefork; switch to mpm_event when it isn't already active.
if ! apache2ctl -M 2>/dev/null | grep -q 'mpm_event_module'; then
    a2dismod mpm_prefork 2>/dev/null || true
    a2enmod mpm_event
fi

# Advertise h2 server-wide (h2 is HTTP/2 over TLS; http1.1 stays the fallback)
# so the per-site :443 vhosts certbot generates negotiate HTTP/2 without the
# panel having to touch those certbot-managed files. Plain :80 vhosts keep
# HTTP/1.1 since h2c is intentionally not offered.
cat > /etc/apache2/conf-available/forge-http2.conf <<'HTTP2'
Protocols h2 http1.1
HTTP2
a2enconf forge-http2

a2enmod rewrite proxy_fcgi setenvif http2
systemctl daemon-reload
for v in $(printf '%s\n' "$PHP_VERSION" $SITE_PHP_VERSIONS | sort -u); do
    systemctl enable --now "php$v-fpm"
    systemctl restart "php$v-fpm"
done
systemctl restart apache2

# --- sudoers whitelist -----------------------------------------------------
# Written to a temp file and validated before installing, so a bad edit can
# never brick sudo host-wide.
# NOTE: this whitelist is a convenience boundary, not hard isolation — worker
# units run deployed-site code as forge, and several wildcards (certbot *,
# cp * ...) are root-equivalent. Treat the forge user as trusted.
SUDOERS_TMP="$(mktemp)"
cat > "$SUDOERS_TMP" <<'SUDOERS'
forge ALL=(root) NOPASSWD: /usr/sbin/a2ensite *
forge ALL=(root) NOPASSWD: /usr/sbin/a2dissite *
forge ALL=(root) NOPASSWD: /usr/sbin/apache2ctl configtest
forge ALL=(root) NOPASSWD: /usr/bin/systemctl reload apache2
forge ALL=(root) NOPASSWD: /usr/bin/systemctl daemon-reload
forge ALL=(root) NOPASSWD: /usr/bin/systemctl start forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl stop forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl restart forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl enable --now forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl disable --now forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/certbot *
forge ALL=(root) NOPASSWD: /usr/bin/cp * /etc/apache2/sites-available/*
forge ALL=(root) NOPASSWD: /usr/bin/cp * /etc/systemd/system/forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/cp * /etc/cron.d/forge-site-*
forge ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/apache2/sites-available/*
forge ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/systemd/system/forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/cron.d/forge-site-*
forge ALL=(root) NOPASSWD: /usr/bin/rm /etc/apache2/sites-available/*
forge ALL=(root) NOPASSWD: /usr/bin/rm /etc/systemd/system/forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/cron.d/forge-site-*
SUDOERS
visudo -cf "$SUDOERS_TMP"
install -m 440 "$SUDOERS_TMP" /etc/sudoers.d/forge-panel
rm -f "$SUDOERS_TMP"

# --- privileged mysql user for managed databases ---------------------------
# The panel provisions per-site databases through 'forge_admin'@'localhost'
# (the forge_mysql connection in config/database.php). The password is
# generated once and kept in a root-only file so re-runs are idempotent; it is
# synced into the panel's .env as FORGE_MYSQL_PASSWORD below. ALTER USER on
# every run keeps MySQL in agreement with the stored file even if one side was
# changed by hand. Hex output avoids shell/SQL quoting pitfalls.
MYSQL_PASSWORD_FILE=/root/.forge-panel-mysql-password
if [ ! -s "$MYSQL_PASSWORD_FILE" ]; then
    (umask 077 && openssl rand -hex 24 > "$MYSQL_PASSWORD_FILE")
fi
chmod 600 "$MYSQL_PASSWORD_FILE"
MYSQL_ADMIN_PASSWORD="$(cat "$MYSQL_PASSWORD_FILE")"

# Ubuntu's mysql root account authenticates via auth_socket, so a root shell
# reaches mysql without a password.
mysql <<SQL
CREATE USER IF NOT EXISTS 'forge_admin'@'localhost' IDENTIFIED BY '$MYSQL_ADMIN_PASSWORD';
ALTER USER 'forge_admin'@'localhost' IDENTIFIED BY '$MYSQL_ADMIN_PASSWORD';
GRANT ALL PRIVILEGES ON *.* TO 'forge_admin'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

# --- panel queue worker ----------------------------------------------------
# Provisioning jobs (git clone, composer, apache vhosts) MUST run in a
# dedicated worker, not synchronously inside php-fpm. The php-fpm systemd unit
# sets ProtectSystem, which makes /etc read-only for anything it spawns, so the
# vhost `sudo cp` into /etc/apache2 would fail with a read-only filesystem.
# This worker service carries no such restriction, so its sudo calls succeed.
cat > /etc/systemd/system/forge-panel-worker.service <<UNIT
[Unit]
Description=Forge panel queue worker
After=network.target mysql.service

[Service]
User=$FORGE_USER
Group=$FORGE_USER
WorkingDirectory=$PANEL_DIR
ExecStart=/usr/bin/php artisan queue:work --tries=1 --timeout=3600 --sleep=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload

if [ -f "$PANEL_DIR/artisan" ]; then
    # Route jobs through the database queue so they run in this worker rather
    # than synchronously in the web request.
    if [ -f "$PANEL_DIR/.env" ]; then
        if grep -q '^QUEUE_CONNECTION=' "$PANEL_DIR/.env"; then
            sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' "$PANEL_DIR/.env"
        else
            echo 'QUEUE_CONNECTION=database' >> "$PANEL_DIR/.env"
        fi
        # Hand the panel the mysql admin password generated above (hex-only,
        # so it is safe inside a sed replacement).
        if grep -q '^FORGE_MYSQL_PASSWORD=' "$PANEL_DIR/.env"; then
            sed -i "s/^FORGE_MYSQL_PASSWORD=.*/FORGE_MYSQL_PASSWORD=$MYSQL_ADMIN_PASSWORD/" "$PANEL_DIR/.env"
        else
            echo "FORGE_MYSQL_PASSWORD=$MYSQL_ADMIN_PASSWORD" >> "$PANEL_DIR/.env"
        fi
        sudo -u "$FORGE_USER" php "$PANEL_DIR/artisan" config:clear || true
        # Creates the jobs table; tolerated if the DB is not reachable yet.
        sudo -u "$FORGE_USER" php "$PANEL_DIR/artisan" migrate --force || true
    fi
    systemctl enable --now forge-panel-worker
    systemctl restart forge-panel-worker
    echo "Panel queue worker is running (forge-panel-worker.service)."
else
    echo "NOTE: $PANEL_DIR is not deployed yet — worker unit was installed but not started."
    echo "      Deploy the panel there, then re-run this script (or:"
    echo "      systemctl enable --now forge-panel-worker) to start it."
fi

echo
echo "MySQL admin user 'forge_admin'@'localhost' is provisioned; its password lives"
echo "in $MYSQL_PASSWORD_FILE and is written to the panel's .env as"
echo "FORGE_MYSQL_PASSWORD whenever the panel is deployed."
echo
echo "Deploy the panel into $PANEL_DIR (or set PANEL_DIR=... and re-run this"
echo "script), configure its Apache vhost to route the panel's PHP through the forge"
echo "FPM pool (/run/php/php-fpm-forge.sock), and set FORGE_FAKE_SHELL=false."
echo "Re-running this script after the panel is deployed starts its queue worker"
echo "(forge-panel-worker.service), which runs provisioning jobs as the forge user."
