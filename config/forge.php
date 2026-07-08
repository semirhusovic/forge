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
    | Where managed sites live, where per-site deploy keys are stored, and
    | the PHP binary used inside site directories (deploys, artisan).
    |
    */

    'sites_path' => env('FORGE_SITES_PATH', '/home/forge'),

    'ssh_path' => env('FORGE_SSH_PATH', '/home/forge/.ssh'),

    'php_binary' => env('FORGE_PHP_BINARY', '/usr/bin/php'),

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
