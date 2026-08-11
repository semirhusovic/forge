#!/usr/bin/env bash
# One-time setup for the Forge panel host. Run as root on Ubuntu 22.04/24.04.
#
# Clone the panel first (it is a private repo, so the clone stays manual), then
# run this script to provision the host AND finish deploying the panel:
#
#   sudo -u forge git clone git@github.com:semirhusovic/forge.git /home/forge/panel
#   sudo PANEL_DOMAIN=panel.example.com PANEL_EMAIL=you@example.com bash server-setup.sh
#
# Environment overrides:
#   PANEL_DIR     where the panel lives           (default /home/forge/panel)
#   PANEL_DOMAIN  vhost ServerName for the panel  (unset = skip vhost + SSL)
#   PANEL_EMAIL   Let's Encrypt registration mail (unset = skip SSL only)
#   SKIP_BUILD=1  skip `npm ci && npm run build`  (fast config-only re-runs)
#
# Safe to re-run. Every mutating step is either guarded or rewrites identical
# content; in particular APP_KEY is generated only once, the panel .env is
# seeded only when absent, the panel vhost is never rewritten over certbot's
# edits, and certbot is not re-invoked once a certificate exists.
set -euo pipefail

FORGE_USER=forge
FORGE_HOME=/home/forge
PANEL_DIR="${PANEL_DIR:-/home/forge/panel}"
PANEL_DOMAIN="${PANEL_DOMAIN:-}"
PANEL_EMAIL="${PANEL_EMAIL:-}"
SKIP_BUILD="${SKIP_BUILD:-}"

# --- system packages -------------------------------------------------------
# --allow-releaseinfo-change: third-party repos already on the box (e.g. the
# ondrej/php PPA) occasionally rename their Label/Origin metadata, which makes
# a plain `apt-get update` fail non-interactively and abort the whole script.
# Package signatures are still verified as usual.
apt-get update --allow-releaseinfo-change
# unzip is what composer shells out to when extracting dist packages; without it
# composer silently falls back to slow git clones (or fails on some archives).
# Composer is deliberately NOT installed from apt here — see the composer
# section below for why.
apt-get install -y apache2 php-fpm php-cli php-mysql php-xml php-curl \
    php-mbstring php-zip php-sqlite3 php-gd php-bcmath php-intl php-gmp \
    php-opcache php-readline git unzip certbot python3-certbot-apache \
    mysql-server curl ca-certificates gnupg software-properties-common

# --- per-site php versions ---------------------------------------------------
# Sites pick their PHP version at creation (config/forge.php 'php_versions').
# Each version needs a CLI binary (/usr/bin/phpX.Y) for deploys/cron/workers
# and a forge FPM pool with a versioned socket for the site's vhost. Ubuntu's
# archive carries only one PHP, so the extra versions come from the ondrej PPA
# (idempotent to re-add).
SITE_PHP_VERSIONS="8.3 8.4 8.5"

# The panel itself keeps running on the current default /usr/bin/php and the
# unversioned php-fpm-forge.sock pool. Capture that version BEFORE installing
# site versions: the new packages would otherwise flip the /usr/bin/php
# alternative to the newest version, silently moving the panel onto an FPM
# service whose pool fights over the same socket.
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

add-apt-repository -y ppa:ondrej/php

# intl is required by the apt composer binary itself (Symfony's string helpers
# need the Normalizer class once install progress rendering kicks in) and by
# many Laravel apps. gd/bcmath/gmp round out the set most Laravel packages
# assume is present (image manipulation, money math, crypto helpers) — a site
# that needs one and finds it missing only fails at runtime, long after deploy.
for v in $SITE_PHP_VERSIONS; do
    # fpm and cli are load-bearing: a version without them cannot serve or
    # deploy a site at all, so let a failure here abort the script.
    apt-get install -y "php$v-fpm" "php$v-cli"

    # The extensions are additive. Try them in one transaction, but fall back to
    # installing them individually if that fails: a newly released PHP whose PPA
    # is missing one package would otherwise abort provisioning entirely and
    # leave the host half-configured. Whatever is genuinely unavailable is
    # reported and skipped.
    ext_packages=""
    for ext in mysql xml curl mbstring zip sqlite3 intl gd bcmath gmp opcache readline; do
        ext_packages="$ext_packages php$v-$ext"
    done

    # shellcheck disable=SC2086 # deliberate word splitting into package args
    if ! apt-get install -y $ext_packages; then
        for pkg in $ext_packages; do
            apt-get install -y "$pkg" || echo "WARNING: $pkg is unavailable — skipped."
        done
    fi
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

# --- composer --------------------------------------------------------------
# Ubuntu's `composer` package lags a long way behind upstream, and the versions
# in 22.04/24.04 predate PHP 8.4 support — they emit a wall of deprecation
# notices from Composer's own vendored code before doing any useful work. Take
# it from the official installer instead, into /usr/local/bin, which also makes
# `composer self-update` work (the apt binary is root-owned and not updatable).
#
# The panel and every site deploy invoke this absolute path via
# config('forge.composer_binary'), so /usr/bin/composer being left behind on an
# already-provisioned box is harmless.
COMPOSER_BIN=/usr/local/bin/composer

if [ ! -x "$COMPOSER_BIN" ]; then
    composer_setup="$(mktemp /tmp/composer-setup-XXXXXX.php)"
    curl -fsSL https://getcomposer.org/installer -o "$composer_setup"
    expected_sig="$(curl -fsSL https://composer.github.io/installer.sig)"
    actual_sig="$(php -r "echo hash_file('sha384', '$composer_setup');")"

    # Refuse rather than continue: this installer runs as root.
    if [ "$expected_sig" != "$actual_sig" ]; then
        rm -f "$composer_setup"
        echo "ERROR: composer installer signature mismatch — refusing to install." >&2
        exit 1
    fi

    php "$composer_setup" --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f "$composer_setup"
fi

# Keep an already-installed composer current on re-runs. COMPOSER_ALLOW_SUPERUSER
# belongs here and only here: this one call really does run as root. Non-fatal,
# so a network blip or a yanked release cannot abort provisioning.
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" self-update --no-interaction \
    || echo "WARNING: composer self-update failed; continuing with the installed version."

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

# useradd only owns the home directory when it is the one that creates it. If
# /home/forge already existed (pre-created by hand, or left behind by another
# tool), it stays root-owned and forge cannot write into it — which surfaces
# much later as an install failure that looks like a git problem:
#   fatal: could not create work tree dir '/home/forge/example.com': Permission denied
# Non-recursive on purpose: site trees below it are already forge-owned, and a
# recursive chown would needlessly walk every deployed site on each re-run.
chown "$FORGE_USER:$FORGE_USER" "$FORGE_HOME"

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
# h2 under mpm_prefork. A server-wide mod_php pins Apache to prefork and blocks
# the switch, but sites run PHP via php-fpm (proxy_fcgi), so that mod_php is
# unused — disable it first, then swap the MPM. Kept non-fatal: if the switch
# can't happen, the rest of provisioning still succeeds (HTTP/2 just won't be
# served until it's resolved).
if ! apache2ctl -M 2>/dev/null | grep -q 'mpm_event_module'; then
    for phpmod in $(a2query -m 2>/dev/null | awk '/^php[0-9]/ {print $1}'); do
        a2dismod "$phpmod" || true
    done
    a2dismod mpm_prefork || true
    a2enmod mpm_event || echo "WARNING: could not switch Apache to mpm_event; HTTP/2 will not be served."
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

# --- panel bootstrap -------------------------------------------------------
# Everything from here down configures the panel itself. The clone stays manual
# (private repo), so bail out with instructions when it hasn't happened yet —
# the host is fully provisioned at this point either way.
if [ ! -f "$PANEL_DIR/artisan" ]; then
    echo
    echo "Host provisioning is complete, but no panel was found at $PANEL_DIR."
    echo "Clone it and re-run this script to finish the deploy:"
    echo
    echo "  sudo -u $FORGE_USER git clone git@github.com:semirhusovic/forge.git $PANEL_DIR"
    echo "  sudo PANEL_DOMAIN=panel.example.com PANEL_EMAIL=you@example.com bash server-setup.sh"
    echo
    echo "MySQL admin user 'forge_admin'@'localhost' is provisioned; its password"
    echo "lives in $MYSQL_PASSWORD_FILE and is written into the"
    echo "panel's .env as FORGE_MYSQL_PASSWORD on that second run."
    exit 0
fi

# Run a command in the panel directory as the forge user. -H sets HOME so
# composer and npm find their caches instead of writing into root's.
run_as_forge() {
    sudo -u "$FORGE_USER" -H bash -c "cd '$PANEL_DIR' && $*"
}

# Idempotent .env writer: replace the key in place when present, append when
# not. '|' delimits the sed expression so paths and URLs need no escaping.
# Values containing '|' or '&' would still need care; none of the callers below
# pass any (hex password, domain, email, fixed literals).
set_env() {
    local key="$1" value="$2" file="$PANEL_DIR/.env"

    if grep -q "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        echo "${key}=${value}" >> "$file"
    fi
}

# The clone may well have been run as root. php-fpm and the queue worker both
# run as forge and must be able to write storage/, bootstrap/cache and the
# SQLite database.
chown -R "$FORGE_USER:$FORGE_USER" "$PANEL_DIR"

# Seeded ONLY when absent: a re-run must never clobber a configured panel. The
# values the panel cannot function without are re-asserted below on every run.
if [ ! -f "$PANEL_DIR/.env" ]; then
    cp "$PANEL_DIR/.env.example" "$PANEL_DIR/.env"
    chown "$FORGE_USER:$FORGE_USER" "$PANEL_DIR/.env"
fi

set_env APP_ENV production
set_env APP_DEBUG false
set_env DB_CONNECTION sqlite
set_env SESSION_DRIVER database
# Route jobs through the database queue so they run in the worker installed
# below rather than synchronously inside the web request.
set_env QUEUE_CONNECTION database
# Fake shell is a local-development switch; on the server the panel really does
# shell out.
set_env FORGE_FAKE_SHELL false
# Hand the panel the mysql admin password generated above (hex-only, so it is
# safe inside a sed replacement).
set_env FORGE_MYSQL_PASSWORD "$MYSQL_ADMIN_PASSWORD"

if [ -n "$PANEL_DOMAIN" ]; then
    # https only when a certificate is actually going to be issued below.
    if [ -n "$PANEL_EMAIL" ]; then
        set_env APP_URL "https://$PANEL_DOMAIN"
    else
        set_env APP_URL "http://$PANEL_DOMAIN"
    fi
fi

if [ -n "$PANEL_EMAIL" ]; then
    set_env FORGE_CERTBOT_EMAIL "$PANEL_EMAIL"
fi

# The panel's own database is SQLite by design (atomic appends to provision
# logs). touch is a no-op once it exists, so data survives re-runs.
sudo -u "$FORGE_USER" touch "$PANEL_DIR/database/database.sqlite"

# Pinned to the panel's own PHP rather than bare `php`: the panel is served by
# the FPM pool for $PHP_VERSION, and composer must resolve platform
# requirements against that exact version, not whatever the `php` alternative
# happens to point at. No COMPOSER_ALLOW_SUPERUSER here — this runs as forge,
# and if it ever needed that flag it would mean vendor/ was being written as
# root, which is the bug, not the fix.
run_as_forge "/usr/bin/php$PHP_VERSION" "$COMPOSER_BIN" install \
    --no-dev --no-interaction --prefer-dist --optimize-autoloader

# This guard is the important one. An unconditional `key:generate --force`
# would mint a fresh APP_KEY on every run and invalidate every session and
# signed cookie the panel has issued. No model uses an encrypted cast, so the
# blast radius would stop at "logged out of your own panel" rather than
# unreadable data — but it should still happen exactly once.
if ! grep -qE '^APP_KEY=.+' "$PANEL_DIR/.env"; then
    run_as_forge php artisan key:generate --force
fi

run_as_forge php artisan migrate --force
run_as_forge php artisan storage:link || true

if [ -n "$SKIP_BUILD" ]; then
    echo "SKIP_BUILD set — keeping the existing public/build assets."
else
    # Vite's wayfinder plugin shells out to `php artisan` mid-build, so assets
    # can only be built after composer install and APP_KEY generation.
    run_as_forge npm ci
    run_as_forge npm run build
fi

chmod -R ug+rwX "$PANEL_DIR/storage" "$PANEL_DIR/bootstrap/cache"

# optimize:clear rather than config:cache on purpose: this is the one machine
# you would be debugging the panel from, and a cached config silently ignores
# later .env edits made by hand.
run_as_forge php artisan optimize:clear

# --- panel vhost + ssl -----------------------------------------------------
if [ -z "$PANEL_DOMAIN" ]; then
    echo "NOTE: PANEL_DOMAIN not set — skipping the panel's Apache vhost and certificate."
else
    PANEL_VHOST="/etc/apache2/sites-available/$PANEL_DOMAIN.conf"

    # Written only when absent. Once certbot has run with --redirect it owns
    # edits inside this file (the rewrite up to HTTPS) and maintains a companion
    # $PANEL_DOMAIN-le-ssl.conf. Rewriting the :80 vhost on a re-run would
    # silently strip that redirect and drop the panel back to plain HTTP.
    if [ ! -f "$PANEL_VHOST" ]; then
        cat > "$PANEL_VHOST" <<VHOST
<VirtualHost *:80>
    ServerName $PANEL_DOMAIN
    DocumentRoot $PANEL_DIR/public

    <Directory $PANEL_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php\$>
        SetHandler "proxy:unix:/run/php/php-fpm-forge.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/$PANEL_DOMAIN-error.log
    CustomLog \${APACHE_LOG_DIR}/$PANEL_DOMAIN-access.log combined
</VirtualHost>
VHOST
        chmod 644 "$PANEL_VHOST"
    fi

    a2ensite "$PANEL_DOMAIN.conf" >/dev/null

    # Mirrors what the panel does for managed sites: a config that fails the
    # test is disabled again so Apache keeps serving everything else.
    if apache2ctl configtest >/dev/null 2>&1; then
        systemctl reload apache2
    else
        a2dissite "$PANEL_DOMAIN.conf" >/dev/null || true
        echo "WARNING: apache configtest failed — panel vhost disabled again. Output:"
        apache2ctl configtest || true
    fi

    if [ -z "$PANEL_EMAIL" ]; then
        echo "NOTE: PANEL_EMAIL not set — skipping the panel certificate. Issue it with:"
        echo "      certbot --apache -d $PANEL_DOMAIN"
    elif [ -d "/etc/letsencrypt/live/$PANEL_DOMAIN" ]; then
        echo "Certificate for $PANEL_DOMAIN already exists — leaving it untouched."
    else
        # Guarded on live/ above because Let's Encrypt caps duplicate
        # certificates at 5 per week. Non-fatal because DNS for a fresh panel
        # domain frequently has not propagated yet; re-running issues it later.
        certbot --apache -d "$PANEL_DOMAIN" --non-interactive --agree-tos \
            -m "$PANEL_EMAIL" --redirect \
            || echo "WARNING: certbot failed for $PANEL_DOMAIN (does DNS point here yet?). Re-run this script to retry."
    fi
fi

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
systemctl enable --now forge-panel-worker
systemctl restart forge-panel-worker

# --- summary ---------------------------------------------------------------
echo
echo "Panel deployed at $PANEL_DIR and its queue worker is running"
echo "(forge-panel-worker.service), executing provisioning jobs as $FORGE_USER."
echo
echo "MySQL admin user 'forge_admin'@'localhost' is provisioned; its password lives"
echo "in $MYSQL_PASSWORD_FILE and is synced into the panel's .env"
echo "as FORGE_MYSQL_PASSWORD on every run."

if [ -n "$PANEL_DOMAIN" ]; then
    echo
    if [ -d "/etc/letsencrypt/live/$PANEL_DOMAIN" ]; then
        echo "The panel is served at https://$PANEL_DOMAIN"
    else
        echo "The panel is served at http://$PANEL_DOMAIN (no certificate yet)."
    fi
    echo "Register the single admin account at /register — registration locks itself"
    echo "once the first user exists."
fi

echo
echo "This script is safe to re-run; do so after changing PANEL_DOMAIN, pulling new"
echo "panel code, or once DNS has propagated. Use SKIP_BUILD=1 to skip the asset"
echo "rebuild when you only need configuration re-applied."
