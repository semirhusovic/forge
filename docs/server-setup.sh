#!/usr/bin/env bash
# One-time setup for the Forge panel host. Run as root on Ubuntu 22.04/24.04:
#   sudo bash server-setup.sh
set -euo pipefail

FORGE_USER=forge
FORGE_HOME=/home/forge

# --- system packages -------------------------------------------------------
apt-get update
apt-get install -y apache2 php-fpm php-cli php-mysql php-xml php-curl \
    php-mbstring php-zip php-sqlite3 composer git certbot python3-certbot-apache \
    mysql-server

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

# --- privileged mysql user for managed databases ---------------------------
echo
echo "Create the panel's MySQL admin user (put the password in the panel's .env as FORGE_MYSQL_PASSWORD):"
echo "  mysql -e \"CREATE USER 'forge_admin'@'localhost' IDENTIFIED BY '<password>'; GRANT ALL PRIVILEGES ON *.* TO 'forge_admin'@'localhost' WITH GRANT OPTION; FLUSH PRIVILEGES;\""
echo
echo "Then deploy the panel itself into $FORGE_HOME/<panel-domain>, configure its Apache vhost to"
echo "route the panel's PHP through the forge FPM pool (/run/php/php-fpm-forge.sock),"
echo "run its queue worker (systemd unit) as the forge user, and set FORGE_FAKE_SHELL=false."
