<?php

namespace Deployer;

require 'recipe/common.php';

set('application', 'daily-task');
set('repository', 'git@github.com:andreasetdotkodotid/daily-task.git');
set('keep_releases', 5);
set('shared_dirs', ['storage']);
set('shared_files', ['.env']);
set('writable_dirs', ['storage']);
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader');

host('production')
    ->setHostname(getenv('DEPLOY_HOST') ?: 'your-server-ip-or-domain')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setDeployPath(getenv('DEPLOY_PATH') ?: '/var/www/daily-task');

task('deploy:create_env', function (): void {
    $envFile = '{{deploy_path}}/shared/.env';

    if (! test("[ -f $envFile ]")) {
        run('mkdir -p {{deploy_path}}/shared/storage');
        run("printf '%s\n' 'APP_ENV=production' 'APP_URL=https://daily-task.dotko.id' 'DB_PATH={{deploy_path}}/shared/storage/tasks.sqlite' 'AUTH_API_URL=https://login.dotko.id/api/login' 'AUTH_SSO_URL=https://login.dotko.id/sso/google' 'AUTH_API_KEY=change-this-api-client-secret' > $envFile");
    }
});

task('deploy:storage', function (): void {
    run('mkdir -p {{deploy_path}}/shared/storage');
});

task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:create_env',
    'deploy:storage',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'deploy:publish',
]);

after('deploy:failed', 'deploy:unlock');
