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
    | Per-Site PHP Versions
    |--------------------------------------------------------------------------
    |
    | Versions a site can be created with. Each must have a matching FPM pool
    | and CLI binary provisioned by docs/server-setup.sh (php-fpm-forge-X.Y
    | socket and /usr/bin/phpX.Y).
    |
    */

    'php_versions' => ['8.3', '8.4'],

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
