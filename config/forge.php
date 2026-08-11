<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fake Shell Mode
    |--------------------------------------------------------------------------
    |
    | When true, ShellRunner logs commands instead of executing them and
    | managed-MySQL statements are skipped. Lets the whole panel be
    | clicked through on a local (non-Ubuntu) development machine.
    |
    */

    'fake_shell' => env('FORGE_FAKE_SHELL', false),

    /*
    |--------------------------------------------------------------------------
    | Server Paths
    |--------------------------------------------------------------------------
    |
    | Where managed sites live and where per-site deploy keys are stored.
    |
    */

    'sites_path' => env('FORGE_SITES_PATH', '/home/forge'),

    'ssh_path' => env('FORGE_SSH_PATH', '/home/forge/.ssh'),

    /*
    |--------------------------------------------------------------------------
    | Composer Binary
    |--------------------------------------------------------------------------
    |
    | Absolute path to the composer executable used for installs and deploys.
    | docs/server-setup.sh installs the official build here; the distro's
    | /usr/bin/composer is too old for PHP 8.4 and floods deploy logs with
    | deprecation notices.
    |
    */

    'composer_binary' => env('FORGE_COMPOSER_BINARY', '/usr/local/bin/composer'),

    /*
    |--------------------------------------------------------------------------
    | Per-Site PHP Versions
    |--------------------------------------------------------------------------
    |
    | Versions a site can be created with. Each must have a matching FPM pool
    | and CLI binary provisioned by docs/server-setup.sh (php-fpm-forge-X.Y
    | socket and /usr/bin/phpX.Y).
    |
    */

    'php_versions' => ['8.3', '8.4', '8.5'],

    'default_php_version' => env('FORGE_DEFAULT_PHP_VERSION', '8.4'),

    /*
    |--------------------------------------------------------------------------
    | Let's Encrypt
    |--------------------------------------------------------------------------
    |
    | Registration email passed to certbot when issuing certificates.
    |
    */

    'certbot_email' => env('FORGE_CERTBOT_EMAIL'),

];
