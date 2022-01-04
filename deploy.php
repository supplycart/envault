<?php

namespace Deployer;

require 'recipe/laravel.php';
require __DIR__ . '/deployer/recipe.php';

// Config

set('application', 'envault');
set('repository', 'git@github.com:supplycart/envault.git');

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

// Hosts
if (file_exists(__DIR__ . '/deployer/hosts.php')) {
    require __DIR__ . '/deployer/hosts.php';
} else {
    host('sc-aws-envault')
        ->set('remote_user', 'ubuntu')
        ->set('deploy_path', '/home/ubuntu/envault')
        ->set('branch', function () {
            return input()->getOption('branch') ?: 'main';
        })
        ->set('php_version', '8.0')
        ->set('domain', 'envault.supplycart.my');
}

// Tasks


// Hooks
before('artisan:migrate', 'deploy:env');
after('deploy:failed', 'deploy:unlock');