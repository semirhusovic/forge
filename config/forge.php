<?php

return [
    /*
     * When true, ShellRunner logs commands instead of executing them and
     * managed-MySQL statements are skipped. For local development.
     */
    'fake_shell' => env('FORGE_FAKE_SHELL', false),

    'sites_path' => env('FORGE_SITES_PATH', '/home/forge'),

    'ssh_path' => env('FORGE_SSH_PATH', '/home/forge/.ssh'),

    'php_binary' => env('FORGE_PHP_BINARY', '/usr/bin/php'),

    'certbot_email' => env('FORGE_CERTBOT_EMAIL'),
];
