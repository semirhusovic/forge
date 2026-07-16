<?php

use App\Models\Site;

test('default deploy script uses composer update so a stale lock cannot block deploys', function () {
    $script = Site::defaultDeployScript('/home/forge/app.example.com', 'main');

    expect($script)
        ->toContain('composer update --no-dev --no-interaction --prefer-dist --optimize-autoloader')
        ->not->toContain('composer install');
});
