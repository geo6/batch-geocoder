<?php

namespace Deployer;

require 'recipe/zend_framework.php';

// Project name
set('application', 'batch-geocoder');

// Project repository
set('repository', 'git@github.com:geo6/batch-geocoder.git');
set('branch', 'master');

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true);

// Shared files/dirs between deploys
add('shared_files', [
    'config/autoload/local.php',
]);
add('shared_dirs', [
    'config/application',
    'data/upload',
]);

// Writable dirs by web server
add('writable_dirs', [
    'config',
    'data/cache',
    'data/upload',
]);
set('writable_mode', 'chown');
set('writable_use_sudo', true);

set('allow_anonymous_stats', false);
set('cleanup_use_sudo', true);

// Files/dirs to be deleted
set('clear_paths', [
    'node_modules',
    'deploy.php',
    'Procfile',
]);
after('deploy:update_code', 'deploy:clear_paths');

// Hosts
inventory('hosts.yml');

// Tasks
task('debug:enable', 'composer run development-enable');
task('debug:disable', 'composer run development-disable');

task('locale', 'composer run gettext:mo');
after('deploy:writable', 'locale');

task('install_providers', function () {
    $providers = [
        'geo6/geocoder-php-geo6-provider',
        'geo6/geocoder-php-urbis-provider',
        'geo6/geocoder-php-geopunt-provider',
        'geo6/geocoder-php-spw-provider',
        'geo6/geocoder-php-bpost-provider',
    ];

    run('cd {{ release_path }} && composer require '.implode(' ', $providers));
});
after('deploy:vendors', 'install_providers');

task('php:version', function () {
    $test = run('php -r "echo version_compare(PHP_VERSION, \'7.3\', \'<\');"');

    if ($test == 1) {
        run('cd {{ release_path }} && composer update');
    }
});
after('deploy:vendors', 'php:version');

// [Optional] if deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
