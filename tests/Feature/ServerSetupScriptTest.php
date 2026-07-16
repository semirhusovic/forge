<?php

test('server setup script carves php-fpm write access into every /etc path the panel writes in-request', function () {
    $script = file_get_contents(base_path('docs/server-setup.sh'));

    preg_match('/^ReadWritePaths=(.+)$/m', $script, $matches);

    expect($matches)->not->toBeEmpty(
        'server-setup.sh must install a php-fpm drop-in with ReadWritePaths: the stock unit sets '
        .'ProtectSystem=full, so in-request sudo writes to /etc fail with "Read-only file system".'
    );

    expect($matches[1])
        ->toContain('/etc/apache2/sites-available') // ApacheManager vhosts (site delete runs in-request)
        ->toContain('/etc/apache2/sites-enabled')   // a2ensite/a2dissite symlinks
        ->toContain('/etc/systemd/system')          // WorkerManager unit files
        ->toContain('/etc/cron.d');                 // SchedulerManager cron files
});

test('server setup script provisions every php version the panel offers for sites', function () {
    $script = file_get_contents(base_path('docs/server-setup.sh'));

    preg_match('/^SITE_PHP_VERSIONS="(.+)"$/m', $script, $matches);

    expect($matches)->not->toBeEmpty('server-setup.sh must define SITE_PHP_VERSIONS');

    // Every version offered in the panel (config/forge.php) must be provisioned
    // on the server, and the pool socket naming must match Site::fpmSocket().
    expect(explode(' ', $matches[1]))->toBe(config('forge.php_versions'));

    expect($script)
        ->toContain('ppa:ondrej/php')
        ->toContain('listen = /run/php/php-fpm-forge-$v.sock')
        // Without intl the apt composer binary fatals with "Class Normalizer
        // not found" mid-install when run under the versioned CLIs.
        ->toContain('"php$v-intl"')
        // PATH shims DeploySite prepends (Site::phpShimDir) so bare `php`
        // resolves to the site's version during deploys.
        ->toContain('ln -sf "/usr/bin/php$v" "/opt/forge/php/$v/php"');
});

test('server setup script survives third-party repos renaming their release metadata', function () {
    $script = file_get_contents(base_path('docs/server-setup.sh'));

    // Without the flag, a PPA changing its Label (as ondrej/php has done) makes
    // apt-get update fail non-interactively and set -e aborts the whole script.
    expect($script)->toContain('apt-get update --allow-releaseinfo-change');
});

test('server setup script provisions the mysql admin user the panel expects, without manual steps', function () {
    $script = file_get_contents(base_path('docs/server-setup.sh'));

    // Must match the forge_mysql connection defaults in config/database.php.
    expect($script)
        ->toContain("CREATE USER IF NOT EXISTS 'forge_admin'@'localhost'")
        ->toContain("ALTER USER 'forge_admin'@'localhost'") // re-runs resync the stored password
        ->toContain("GRANT ALL PRIVILEGES ON *.* TO 'forge_admin'@'localhost' WITH GRANT OPTION")
        ->toContain('FORGE_MYSQL_PASSWORD=')                // synced into the panel .env
        ->not->toContain("Create the panel's MySQL admin user");
});
