#!/usr/bin/env bash
# One-time setup for the Forge panel host. Run as root on Ubuntu 22.04/24.04:
#   sudo bash server-setup.sh
set -euo pipefail

FORGE_USER=forge
FORGE_HOME=/home/forge

# --- system packages -------------------------------------------------------
apt-get update
apt-get install -y apache2 libapache2-mod-php php-cli php-mysql php-xml php-curl \
    php-mbstring php-zip php-sqlite3 composer git certbot python3-certbot-apache \
    mysql-server
a2enmod rewrite
systemctl reload apache2

# --- forge user ------------------------------------------------------------
if ! id "$FORGE_USER" &>/dev/null; then
    useradd --create-home --shell /bin/bash "$FORGE_USER"
fi
mkdir -p "$FORGE_HOME/.ssh"
touch "$FORGE_HOME/.ssh/config"
chown -R "$FORGE_USER:$FORGE_USER" "$FORGE_HOME/.ssh"
chmod 700 "$FORGE_HOME/.ssh"

# Pre-trust GitHub's host keys so git clone doesn't prompt.
sudo -u "$FORGE_USER" bash -c "ssh-keyscan github.com >> $FORGE_HOME/.ssh/known_hosts 2>/dev/null"

# --- sudoers whitelist -----------------------------------------------------
cat > /etc/sudoers.d/forge-panel <<'SUDOERS'
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
chmod 440 /etc/sudoers.d/forge-panel
visudo -cf /etc/sudoers.d/forge-panel

# --- privileged mysql user for managed databases ---------------------------
echo
echo "Create the panel's MySQL admin user (put the password in the panel's .env as FORGE_MYSQL_PASSWORD):"
echo "  mysql -e \"CREATE USER 'forge_admin'@'localhost' IDENTIFIED BY '<password>'; GRANT ALL PRIVILEGES ON *.* TO 'forge_admin'@'localhost' WITH GRANT OPTION; FLUSH PRIVILEGES;\""
echo
echo "Then deploy the panel itself into $FORGE_HOME/<panel-domain>, configure its Apache vhost,"
echo "run its queue worker (systemd unit) as the forge user, and set FORGE_FAKE_SHELL=false."
