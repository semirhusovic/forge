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
apt-get update
apt-get install -y apache2 php-fpm php-cli php-mysql php-xml php-curl \
    php-mbstring php-zip php-sqlite3 composer git certbot python3-certbot-apache \
    mysql-server curl ca-certificates gnupg

# --- node.js ---------------------------------------------------------------
# Site deploy scripts (and the panel's own frontend) build assets with
# `npm ci && npm run build`, so install Node LTS via NodeSource. Idempotent:
# skip the repo setup when Node is already installed.
if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
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

# --- php-fpm pool for the forge user ---------------------------------------
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

# PHP runs as the forge user via a dedicated FPM pool: panel requests get the
# forge user's sudoers whitelist and SSH config, and deployed sites can write
# their own storage/ directories.
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

a2enmod rewrite proxy_fcgi setenvif
systemctl restart "php$PHP_VERSION-fpm"
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

# --- privileged mysql user for managed databases ---------------------------
echo
echo "Create the panel's MySQL admin user (put the password in the panel's .env as FORGE_MYSQL_PASSWORD):"
echo "  mysql -e \"CREATE USER 'forge_admin'@'localhost' IDENTIFIED BY '<password>'; GRANT ALL PRIVILEGES ON *.* TO 'forge_admin'@'localhost' WITH GRANT OPTION; FLUSH PRIVILEGES;\""
echo
echo "Then deploy the panel into $PANEL_DIR (or set PANEL_DIR=... and re-run this"
echo "script), configure its Apache vhost to route the panel's PHP through the forge"
echo "FPM pool (/run/php/php-fpm-forge.sock), and set FORGE_FAKE_SHELL=false."
echo "Re-running this script after the panel is deployed starts its queue worker"
echo "(forge-panel-worker.service), which runs provisioning jobs as the forge user."
